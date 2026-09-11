<?php

namespace TelescopeMcp\Tests;

use TelescopeMcp\TelescopeServer;
use TelescopeMcp\Tools\{GetEntry, ListEntries, Status};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class ToolsTest extends Application
{
    private const A = '12345678-1234-4234-8234-123456789abc';
    private const B = '87654321-1234-4234-8234-123456789abc';

    protected function setUp(): void
    {
        parent::setUp();
        Telescope::stopRecording();
        $this->migrate();
    }

    private function migrate(): void
    {
        (require glob(__DIR__.'/../vendor/laravel/telescope/database/migrations/*')[0])->up();
    }

    private function insertEntry(int $sequence, string $batch = self::A, string $type = 'request', array $content = [], bool $visible = true): string
    {
        $id = (string) Str::uuid();
        DB::table('telescope_entries')->insert([
            'sequence' => $sequence, 'uuid' => $id, 'batch_id' => $batch,
            'type' => $type, 'content' => json_encode($content),
            'should_display_on_index' => $visible, 'family_hash' => 'family-one',
            'created_at' => '2026-09-10 12:34:56',
        ]);

        return $id;
    }

    private function listing(array $arguments = []): array
    {
        return $this->data(TelescopeServer::tool(ListEntries::class, $arguments));
    }

    private function data($response): array
    {
        $data = [];
        $response->assertOk()->assertStructuredContent(function ($json) use (&$data) {
            $data = $json->toArray();
            $json->etc();
        });

        return $data;
    }

    public function test_filtered_keyset_pagination_with_gaps_hidden_rows_and_concurrent_insert(): void
    {
        $old = $this->insertEntry(3);
        $middle = $this->insertEntry(19, visible: false);
        $this->insertEntry(40, self::B);
        $new = $this->insertEntry(87);
        $first = $this->listing(['batch_id' => self::A, 'limit' => 2]);
        self::assertSame([$new, $middle], array_column($first['entries'], 'id'));
        self::assertSame('19', $first['next_cursor']);
        self::assertTrue($first['has_more']);
        $this->insertEntry(99);
        $last = $this->listing(['batch_id' => self::A, 'limit' => 2, 'cursor' => $first['next_cursor']]);
        self::assertSame([$old], array_column($last['entries'], 'id'));
        self::assertFalse($last['has_more']);
        self::assertNull($last['next_cursor']);
        self::assertNotContains($middle, array_column($this->listing()['entries'], 'id'));
    }

    public function test_tags_are_or_and_family_and_type_filters_are_database_scoped(): void
    {
        $a = $this->insertEntry(2, type: 'exception', visible: false);
        $b = $this->insertEntry(7, type: 'log', visible: false);
        $this->insertEntry(11);
        DB::table('telescope_entries_tags')->insert([
            ['entry_uuid' => $a, 'tag' => 'first'], ['entry_uuid' => $b, 'tag' => 'second'],
        ]);
        self::assertSame([$b, $a], array_column($this->listing(['tag' => 'first, second'])['entries'], 'id'));
        self::assertSame([$a], array_column($this->listing(['family_hash' => 'family-one', 'type' => 'exception'])['entries'], 'id'));
        self::assertSame([], $this->listing(['family_hash' => 'absent'])['entries']);
    }

    public static function invalidArguments(): array
    {
        return array_map(fn ($args) => [$args], [
            ['limit' => 0], ['limit' => -1], ['limit' => 101], ['limit' => 'invalid'],
            ['limit' => true], ['limit' => '2'],
            ['limit' => null], ['limit' => 1.5], ['cursor' => '0'], ['cursor' => '-1'],
            ['cursor' => '1e3'], ['cursor' => '999999999999999999999'], ['cursor' => 20],
            ['type' => 'sql'], ['batch_id' => '12345678'], ['tag' => str_repeat('a', 256)],
        ]);
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_list_input_is_an_error(array $arguments): void
    {
        TelescopeServer::tool(ListEntries::class, $arguments)->assertHasErrors();
    }

    public function test_full_uuid_required_and_absent_entry_is_safe_error(): void
    {
        $id = $this->insertEntry(1);
        foreach (['invalid', substr($id, 0, 8), '', '1'] as $invalid) {
            TelescopeServer::tool(GetEntry::class, ['id' => $invalid])->assertHasErrors();
        }
        TelescopeServer::tool(GetEntry::class, ['id' => (string) Str::uuid()])->assertHasErrors()->assertSee('not found');
    }

    public function test_large_sequence_cursors_roundtrip_without_numeric_precision_loss(): void
    {
        $older = $this->insertEntry(1000000000000000001);
        $this->insertEntry(1000000000000000003);
        $first = $this->listing(['limit' => 1]);
        self::assertSame('1000000000000000003', $first['next_cursor']);
        $next = $this->listing(['limit' => 1, 'cursor' => $first['next_cursor']]);
        self::assertSame([$older], array_column($next['entries'], 'id'));
        self::assertFalse($next['has_more']);
    }

    public function test_database_exception_message_does_not_expose_interpolated_sql(): void
    {
        $id = $this->insertEntry(1, type: 'exception', content: [
            'class' => 'Illuminate\\Database\\QueryException',
            'message' => "SQLSTATE[23000]: SQL: insert into users values ('PRIVATE_EMAIL')",
            'file' => '/app/User.php', 'line' => 42,
        ]);
        TelescopeServer::tool(GetEntry::class, ['id' => $id])->assertOk()
            ->assertDontSee('PRIVATE_EMAIL')->assertSee('[REDACTED: database exception message]');
    }

    public function test_redaction_unicode_and_detail_allowlist(): void
    {
        $id = $this->insertEntry(1, type: 'exception', content: [
            'class' => 'RuntimeException', 'message' => 'token=HIDDEN Bearer ABC password="two words" '.str_repeat('lỗi🙂', 1000),
            'line' => 73, 'file' => '/app/Example.php',
            'context' => ['nested' => ['password' => 'NESTED']], 'trace' => [['args' => ['TRACE_SECRET']]],
            'user' => ['email' => 'PRIVATE'], 'line_preview' => ['SOURCE_SECRET'],
        ]);
        $response = TelescopeServer::tool(GetEntry::class, ['id' => $id])->assertOk();
        $response->assertDontSee(['HIDDEN', 'ABC', 'two words', 'NESTED', 'TRACE_SECRET', 'PRIVATE', 'SOURCE_SECRET']);
        $entry = $this->data($response)['entry'];
        self::assertSame(self::A, $entry['batch_id']);
        self::assertSame(73, $entry['fields']['line']);
        self::assertTrue($entry['truncated']);
        self::assertTrue(mb_check_encoding($entry['fields']['message'], 'UTF-8'));
        self::assertStringContainsString('[truncated]', $entry['fields']['message']);
        self::assertLessThan(16000, strlen(json_encode($entry)));

        foreach (['request', 'query', 'job', 'log'] as $i => $type) {
            $id = $this->insertEntry($i + 2, type: $type, content: [
                'session' => ['nested' => ['secret' => 'SECRET']], 'headers' => ['Authorization' => 'SECRET'],
                'payload' => ['SECRET'], 'response' => 'SECRET', 'sql' => "select 'SECRET'",
                'bindings' => ['SECRET'], 'context' => ['SECRET'], 'data' => ['SECRET'],
                'user' => ['SECRET'], 'name' => ['nested' => 'SECRET'],
            ]);
            TelescopeServer::tool(GetEntry::class, ['id' => $id])->assertOk()->assertDontSee('SECRET');
        }
    }

    public function test_sensitive_permission_is_explicit_local_and_does_not_change_normal_reads(): void
    {
        $id = $this->insertEntry(1, type: 'log', content: ['message' => 'token=SECRET', 'context' => ['password' => 'SECRET']]);
        self::assertFalse($this->data(TelescopeServer::tool(Status::class))['allow_sensitive_data']);
        foreach ([false, 'true', 1, null] as $disabled) {
            config(['telescope-mcp.allow_sensitive_data' => $disabled]);
            TelescopeServer::tool(GetEntry::class, ['id' => $id, 'unredacted' => true, 'allow_sensitive_data' => true])
                ->assertHasErrors()->assertDontSee('SECRET');
            self::assertFalse($this->data(TelescopeServer::tool(Status::class))['allow_sensitive_data']);
        }
        config(['telescope-mcp.allow_sensitive_data' => true]);
        self::assertTrue($this->data(TelescopeServer::tool(Status::class))['allow_sensitive_data']);
        foreach ([[], ['unredacted' => false]] as $args) {
            TelescopeServer::tool(GetEntry::class, ['id' => $id, ...$args])->assertOk()->assertDontSee('SECRET');
        }
        TelescopeServer::tool(ListEntries::class, ['unredacted' => true])->assertOk()->assertDontSee('SECRET');
        TelescopeServer::tool(GetEntry::class, ['id' => $id, 'unredacted' => true])->assertOk()->assertSee('SECRET');
        $this->app->detectEnvironment(fn () => 'production');
        self::assertFalse($this->data(TelescopeServer::tool(Status::class))['allow_sensitive_data']);
        TelescopeServer::tool(GetEntry::class, ['id' => $id, 'unredacted' => true])->assertHasErrors();
    }

    public function test_unredacted_chunks_reassemble_complete_content_metadata_and_tags(): void
    {
        config(['telescope-mcp.allow_sensitive_data' => true]);
        $content = [
            'headers' => ['Authorization' => 'Bearer SECRET'], 'session' => ['password' => 'SECRET'],
            'payload' => ['nested' => ['a' => ['b' => ['c' => ['d' => ['e' => 'DEEP']]]]]],
            'response' => str_repeat("lỗi🙂\x01\"\\", 1800), 'sql' => "select 'SECRET'", 'bindings' => ['SECRET'],
            'context' => array_fill(0, 105, ['token' => 'SECRET']), 'data' => ['private' => 'SECRET'],
            'trace' => [['args' => ['SECRET']]], 'user' => ['email' => 'private@example.test'],
            str_repeat('k', 200) => [false, 0, null, 'LAST'],
        ];
        $id = $this->insertEntry(17, content: $content);
        DB::table('telescope_entries_tags')->insert(['entry_uuid' => $id, 'tag' => 'private-tag']);
        $args = ['id' => $id, 'unredacted' => true];
        $json = '';
        $pages = 0;
        do {
            $page = $this->data(TelescopeServer::tool(GetEntry::class, $args))['unredacted_entry'];
            self::assertFalse($page['redacted']);
            self::assertSame('json', $page['encoding']);
            self::assertSame(self::A, $page['batch_id']);
            self::assertLessThanOrEqual(8192, strlen($page['data']));
            self::assertTrue(mb_check_encoding($page['data'], 'UTF-8'));
            self::assertNotEmpty($page['data']);
            $json .= $page['data'];
            $args['cursor'] = $page['next_cursor'];
            self::assertLessThan(30, ++$pages);
        } while ($page['has_more']);
        self::assertGreaterThan(1, $pages);
        self::assertNull($page['next_cursor']);
        self::assertSame([
            // Telescope's find() returns sequence=null, unlike its list API.
            'id' => $id, 'sequence' => null, 'batch_id' => self::A, 'type' => 'request',
            'content' => $content, 'tags' => ['private-tag'], 'family_hash' => 'family-one',
            'created_at' => '2026-09-10 12:34:56',
        ], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_unredacted_exact_chunk_boundary_does_not_require_an_empty_final_page(): void
    {
        config(['telescope-mcp.allow_sensitive_data' => true]);
        foreach ([8191, 8192, 8193] as $size) {
            $id = $this->insertEntry($size, content: ['payload' => '']);
            $expected = [
                'id' => $id, 'sequence' => null, 'batch_id' => self::A, 'type' => 'request',
                'content' => ['payload' => ''], 'tags' => [], 'family_hash' => 'family-one',
                'created_at' => '2026-09-10 12:34:56',
            ];
            $expected['content']['payload'] = str_repeat('x', $size - strlen(json_encode($expected)));
            DB::table('telescope_entries')->where('uuid', $id)->update(['content' => json_encode($expected['content'])]);
            $args = ['id' => $id, 'unredacted' => true];
            $page = $this->data(TelescopeServer::tool(GetEntry::class, $args))['unredacted_entry'];
            self::assertSame($size > 8192, $page['has_more']);
            $json = $page['data'];
            if ($page['has_more']) {
                $page = $this->data(TelescopeServer::tool(GetEntry::class, [...$args, 'cursor' => $page['next_cursor']]))['unredacted_entry'];
                self::assertSame(1, strlen($page['data']));
                self::assertFalse($page['has_more']);
                $json .= $page['data'];
            }
            self::assertNull($page['next_cursor']);
            self::assertSame(json_encode($expected), $json);
        }
    }

    public function test_unredacted_validation_revocation_changed_and_cross_entry_cursors(): void
    {
        config(['telescope-mcp.allow_sensitive_data' => true]);
        $id = $this->insertEntry(1, content: ['payload' => str_repeat('x', 9000)]);
        $other = $this->insertEntry(2, content: ['payload' => str_repeat('x', 9000)]);
        foreach ([null, 1, 0, 'true', 'false', []] as $invalid) {
            TelescopeServer::tool(GetEntry::class, ['id' => $id, 'unredacted' => $invalid])->assertHasErrors();
        }
        $args = ['id' => $id, 'unredacted' => true];
        foreach ([null, '', 10, 'bad', str_repeat('a', 64).':0', str_repeat('a', 64).':-1', str_repeat('a', 64).':9999999999999999999'] as $invalid) {
            TelescopeServer::tool(GetEntry::class, [...$args, 'cursor' => $invalid])->assertHasErrors();
        }
        $first = $this->data(TelescopeServer::tool(GetEntry::class, $args))['unredacted_entry'];
        $cursor = $first['next_cursor'];
        self::assertNotNull($cursor);
        TelescopeServer::tool(GetEntry::class, ['id' => $id, 'cursor' => $cursor])->assertHasErrors();
        TelescopeServer::tool(GetEntry::class, [...$args, 'id' => $other, 'cursor' => $cursor])->assertHasErrors();
        TelescopeServer::tool(GetEntry::class, [...$args, 'cursor' => explode(':', $cursor)[0].':999999999999999999'])->assertHasErrors();
        config(['telescope-mcp.allow_sensitive_data' => false]);
        TelescopeServer::tool(GetEntry::class, [...$args, 'cursor' => $cursor])->assertHasErrors();
        config(['telescope-mcp.allow_sensitive_data' => true]);
        DB::table('telescope_entries')->where('uuid', $id)->update(['content' => json_encode(['payload' => 'changed'])]);
        TelescopeServer::tool(GetEntry::class, [...$args, 'cursor' => $cursor])->assertHasErrors()->assertSee('stale');
        $last = $this->data(TelescopeServer::tool(GetEntry::class, $args))['unredacted_entry'];
        self::assertFalse($last['has_more']);
        self::assertNull($last['next_cursor']);
        DB::table('telescope_entries')->where('uuid', $id)->delete();
        TelescopeServer::tool(GetEntry::class, $args)->assertHasErrors()->assertSee('not found');
    }

    public function test_output_budget_never_skips_entries_and_default_limit_is_twenty(): void
    {
        $ids = [];
        for ($i = 1; $i <= 105; $i++) {
            $ids[] = $this->insertEntry($i * 3, type: 'exception', content: array_fill_keys(['class', 'message', 'file', 'line'], str_repeat('🙂', 2000)));
        }
        // Small records independently verify the default, since large records hit the byte budget.
        for ($i = 1; $i <= 22; $i++) {
            $this->insertEntry(1000 + $i, self::B);
        }
        self::assertCount(20, $this->listing(['batch_id' => self::B])['entries']);
        $seen = [];
        $args = ['batch_id' => self::A, 'limit' => 100];
        do {
            $page = $this->listing($args);
            self::assertLessThan(25000, strlen(json_encode($page)));
            self::assertNotEmpty($page['entries']);
            $seen = [...$seen, ...array_column($page['entries'], 'id')];
            if (count($seen) < 105) {
                self::assertTrue($page['truncated']);
            }
            $args['cursor'] = $page['next_cursor'];
        } while ($page['has_more']);
        self::assertSame(array_reverse($ids), $seen);
    }

    public function test_status_reports_pause_disabled_and_no_recording_from_reads(): void
    {
        config(['telescope.watchers' => [
            'Example\\DisabledWatcher' => false,
            'Example\\EnabledWatcher' => ['enabled' => true, 'credential' => 'DO_NOT_EXPOSE'],
            'Example\\ImplicitWatcher',
        ]]);
        cache()->put('telescope:pause-recording', true);
        $status = $this->data(TelescopeServer::tool(Status::class));
        self::assertSame([
            ['name' => 'DisabledWatcher', 'enabled' => false],
            ['name' => 'EnabledWatcher', 'enabled' => true],
            ['name' => 'ImplicitWatcher', 'enabled' => true],
        ], $status['watchers']);
        self::assertTrue($status['enabled']);
        self::assertTrue($status['recording_paused']);
        self::assertTrue($status['pause_state_known']);
        config(['telescope.enabled' => false]);
        self::assertFalse($this->data(TelescopeServer::tool(Status::class))['enabled']);
        cache()->forget('telescope:pause-recording');
        Telescope::startRecording();
        $this->listing();
        TelescopeServer::tool(Status::class)->assertOk();
        self::assertTrue(Telescope::isRecording());
        Telescope::stopRecording();
        self::assertCount(0, Telescope::$entriesQueue);
    }

    public function test_pause_cache_failure_is_unknown_not_false(): void
    {
        config(['cache.default' => 'missing-store']);
        $status = $this->data(TelescopeServer::tool(Status::class));
        self::assertNull($status['recording_paused']);
        self::assertFalse($status['pause_state_known']);
    }

    public function test_storage_failures_do_not_leak_connection_details_even_with_debug_enabled(): void
    {
        DB::statement('DROP TABLE telescope_entries');
        TelescopeServer::tool(ListEntries::class)->assertHasErrors()->assertDontSee(['SQLSTATE', 'select ', 'sqlite']);
        TelescopeServer::tool(GetEntry::class, ['id' => self::A])->assertHasErrors()->assertDontSee(['SQLSTATE', 'select ', 'sqlite']);
    }

    public function test_real_stdio_initialize_list_calls_and_shutdown_leave_database_unchanged(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tmcp-');
        try {
            config(['database.connections.sqlite.database' => $path]);
            DB::purge('sqlite');
            $this->migrate();
            $id = $this->insertEntry(91, content: ['response_status' => 503]);
            $huge = $this->insertEntry(92, content: array_fill_keys(
                ['method', 'response_status', 'duration', 'memory', 'controller_action'], str_repeat("\x01🙂", 5000)
            ));
            $logContent = [
                'message' => 'token=STDIO_SECRET',
                'context' => ['count' => 42, 'password' => 'STDIO_SECRET', 'large' => str_repeat("\"\\🙂\x01", 1300)],
            ];
            $log = $this->insertEntry(93, type: 'log', content: $logContent);
            $messages = [
                ['method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'package-test', 'version' => '1']]],
                ['method' => 'tools/list'],
                ['method' => 'tools/call', 'params' => ['name' => 'telescope_status']],
                ['method' => 'tools/call', 'params' => ['name' => 'telescope_list_entries', 'arguments' => ['batch_id' => self::A]]],
                ['method' => 'tools/call', 'params' => ['name' => 'telescope_get_entry', 'arguments' => ['id' => $id]]],
                ['method' => 'tools/call', 'params' => ['name' => 'telescope_get_entry', 'arguments' => ['id' => $huge]]],
                ['method' => 'tools/call', 'params' => ['name' => 'telescope_get_entry', 'arguments' => ['id' => $log]]],
                ['method' => 'tools/call', 'params' => ['name' => 'telescope_get_entry', 'arguments' => ['id' => $log, 'unredacted' => true]]],
            ];
            $input = '';
            foreach ($messages as $i => $message) {
                $input .= json_encode(['jsonrpc' => '2.0', 'id' => $i + 1, ...$message])."\n";
            }
            foreach ([[], ['--no-ansi']] as $options) {
                $enabled = $options !== [];
                $env = ['TMCP_TEST_DB' => $path, 'TELESCOPE_MCP_ALLOW_SENSITIVE_DATA' => $enabled ? 'true' : false];
                $process = new Process([PHP_BINARY, 'tests/fixtures/artisan', ...$options, 'mcp:start', 'telescope'], dirname(__DIR__), $env, $input, 20);
                $process->run();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
                self::assertSame('', $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                self::assertStringNotContainsString('STDIO_SECRET', implode("\n", array_slice($lines, 0, 7)));
                self::assertCount(8, $lines, $process->getOutput());
                foreach ($lines as $line) {
                    self::assertLessThan(65536, strlen($line));
                }
                $replies = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
                self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_column($replies, 'id'));
                self::assertSame('2025-11-25', $replies[0]['result']['protocolVersion']);
                $tools = $replies[1]['result']['tools'];
                self::assertSame(['telescope_status', 'telescope_list_entries', 'telescope_get_entry'], array_column($tools, 'name'));
                foreach ($tools as $tool) {
                    self::assertArrayHasKey('outputSchema', $tool);
                    self::assertTrue($tool['annotations']['readOnlyHint']);
                }
                self::assertTrue($replies[2]['result']['structuredContent']['mcp_recording_suppressed']);
                self::assertSame($enabled, $replies[2]['result']['structuredContent']['allow_sensitive_data']);
                self::assertSame([$log, $huge, $id], array_column($replies[3]['result']['structuredContent']['entries'], 'id'));
                self::assertArrayNotHasKey('context', $replies[3]['result']['structuredContent']['entries'][0]['fields']);
                self::assertSame(503, $replies[4]['result']['structuredContent']['entry']['fields']['response_status']);
                self::assertTrue($replies[5]['result']['structuredContent']['entry']['truncated']);
                $detail = $replies[6]['result']['structuredContent']['entry'];
                self::assertArrayNotHasKey('context', $detail['fields']);
                if ($enabled) {
                    $chunk = $replies[7]['result']['structuredContent']['unredacted_entry'];
                    self::assertTrue($chunk['has_more']);
                    $json = $chunk['data'];
                    $pages = 1;
                    while ($chunk['has_more']) {
                        // Resume over a new real process: cursors must not depend on process memory.
                        $call = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                            'name' => 'telescope_get_entry', 'arguments' => ['id' => $log, 'unredacted' => true, 'cursor' => $chunk['next_cursor']],
                        ]];
                        $continuation = json_encode(['jsonrpc' => '2.0', 'id' => 1, ...$messages[0]])."\n".json_encode($call)."\n";
                        $reader = new Process([PHP_BINARY, 'tests/fixtures/artisan', 'mcp:start', 'telescope'], dirname(__DIR__), $env, $continuation, 20);
                        $reader->run();
                        self::assertSame(0, $reader->getExitCode());
                        self::assertSame('', $reader->getErrorOutput());
                        $output = explode("\n", trim($reader->getOutput()));
                        self::assertCount(2, $output);
                        self::assertLessThan(65536, strlen($output[1]));
                        $reply = json_decode($output[1], true, 512, JSON_THROW_ON_ERROR);
                        $chunk = $reply['result']['structuredContent']['unredacted_entry'];
                        $json .= $chunk['data'];
                        self::assertLessThan(10, ++$pages);
                    }
                    self::assertNull($chunk['next_cursor']);
                    self::assertSame($logContent, json_decode($json, true, 512, JSON_THROW_ON_ERROR)['content']);
                } else {
                    self::assertTrue($replies[7]['result']['isError']);
                    self::assertStringNotContainsString('STDIO_SECRET', $lines[7]);
                }
                self::assertSame(3, DB::table('telescope_entries')->count());
            }
        } finally {
            DB::disconnect('sqlite');
            unlink($path);
        }
    }

    public function test_disabled_and_nonlocal_do_not_register_server(): void
    {
        foreach ([['TMCP_TEST_DISABLED' => '1'], ['TMCP_TEST_ENV' => 'production'], ['TMCP_TEST_DEFAULT' => '1', 'TELESCOPE_MCP_ENABLED' => false]] as $env) {
            $process = new Process([PHP_BINARY, 'tests/fixtures/artisan', 'mcp:start', 'telescope'], dirname(__DIR__), [...$env, 'TELESCOPE_MCP_ALLOW_SENSITIVE_DATA' => 'true'], '', 20);
            $process->run();
            self::assertSame(1, $process->getExitCode());
            self::assertSame('', $process->getOutput());
            self::assertStringContainsString('Telescope MCP is unavailable', $process->getErrorOutput());
        }
    }
}

<?php

namespace TelescopeMcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Telescope\EntryResult;

final class EntryOutput
{
    // Normal reads always use this allowlist, regardless of sensitive-data permission.
    private const FIELDS = [
        'request' => ['method', 'response_status', 'duration', 'memory', 'controller_action'],
        'exception' => ['class', 'message', 'file', 'line'],
        'query' => ['time', 'slow', 'file', 'line'],
        'log' => ['level', 'message'],
        'job' => ['status', 'name', 'tries', 'timeout'],
    ];

    public static function schema(JsonSchema $schema): mixed
    {
        return $schema->object([
            'id' => $schema->string()->required(),
            'batch_id' => $schema->string()->required(),
            'type' => $schema->string()->required(),
            'created_at' => $schema->string()->required(),
            'family_hash' => $schema->string()->nullable()->required(),
            'fields' => $schema->object()->required(),
            'truncated' => $schema->boolean()->required(),
            'redacted' => $schema->boolean()->required(),
        ]);
    }

    public function serialize(EntryResult $entry, bool $detail = false): array
    {
        $truncated = false;
        $fields = [];
        foreach (self::FIELDS[$entry->type] ?? [] as $key) {
            $value = $entry->content[$key] ?? null;
            if (is_string($value)) {
                $value = mb_scrub($value, 'UTF-8');
                // QueryException embeds interpolated SQL and credentials in its message.
                if ($key === 'message' && (str_contains($value, 'SQLSTATE[')
                    || ($entry->content['class'] ?? null) === \Illuminate\Database\QueryException::class)) {
                    $value = '[REDACTED: database exception message]';
                }
                $value = $this->text($value, $detail ? 2048 : 160, $truncated);
            } elseif (! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                continue;
            }
            $fields[$key] = $value;
        }

        return [
            'id' => $entry->id,
            'batch_id' => $entry->batchId,
            'type' => $entry->type,
            'created_at' => $entry->createdAt->toDateTimeString(),
            'family_hash' => $entry->familyHash !== null && preg_match('/^[a-f0-9-]{1,64}$/iD', $entry->familyHash)
                ? $entry->familyHash : null,
            'fields' => (object) $fields,
            'truncated' => $truncated,
            'redacted' => true,
        ];
    }

    public function unredacted(EntryResult $entry, ?string $cursor): array
    {
        // ASCII JSON keeps each chunk valid UTF-8, even across escaped Unicode.
        // Concatenating chunks restores the complete repository representation.
        $json = json_encode($entry->jsonSerialize(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $hash = hash('sha256', $json);
        $offset = 0;
        if ($cursor !== null) {
            [$expectedHash, $offset] = explode(':', $cursor);
            if (! hash_equals($hash, $expectedHash) || $offset >= strlen($json)) {
                throw new \InvalidArgumentException('Stale or invalid cursor.');
            }
            $offset = (int) $offset;
        }
        $chunk = substr($json, $offset, 8192);
        $next = $offset + strlen($chunk);
        $hasMore = $next < strlen($json);

        return [
            'id' => $entry->id,
            'batch_id' => $entry->batchId,
            'redacted' => false,
            'encoding' => 'json',
            'data' => $chunk,
            'next_cursor' => $hasMore ? $hash.':'.$next : null,
            'has_more' => $hasMore,
        ];
    }

    private function text(string $value, int $max, bool &$truncated): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace('/\b(Bearer|Basic)\s+\S+/iu', '$1 [REDACTED]', $value);
        $value = preg_replace('/\b(password|secret|token|api[_-]?key|authorization|cookie)\s*[:=]\s*("[^"]*"|\x27[^\x27]*\x27|[^\s,;]+)/iu', '$1=[REDACTED]', $value);
        // Bound escaped JSON too: control characters expand up to sixfold.
        if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > $max) {
            $low = 0;
            $high = min(strlen($value), $max);
            while ($low < $high) {
                $middle = intdiv($low + $high + 1, 2);
                $candidate = mb_strcut($value, 0, $middle, 'UTF-8');
                if (strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) <= $max) {
                    $low = $middle;
                } else {
                    $high = $middle - 1;
                }
            }
            $value = mb_strcut($value, 0, $low, 'UTF-8').'…[truncated]';
            $truncated = true;
        }

        return $value;
    }
}

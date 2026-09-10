# Telescope MCP

An independent Laravel package that lets AI clients read **data already recorded by Telescope**, using local stdio from `laravel/mcp`. This is not an official Laravel package. It exposes no HTTP endpoint and cannot execute SQL/PHP, retry jobs, clear entries, or prune data.

Repository: [nguyenphutrong/telescope-mcp](https://github.com/nguyenphutrong/telescope-mcp).

## Installation in a Laravel application

This package is **not published yet**. To try it, use a Composer path repository pointing to this checkout:

```bash
# Run in your Laravel application directory, not the package directory.
composer config repositories.telescope-mcp '{"type":"path","url":"/absolute/path/to/telescope-mcp","options":{"versions":{"nguyenphutrong/telescope-mcp":"dev-main"}}}'
composer require --dev nguyenphutrong/telescope-mcp:dev-main
```

Composer automatically discovers the service provider. Your application must have Telescope installed, with storage configured and its migrations applied as usual. This package does not create or modify tables or run migrations automatically. It requires `laravel/telescope` directly, with no fork or core changes needed.

In **your local application's** `.env`:

```dotenv
APP_ENV=local
TELESCOPE_MCP_ENABLED=true
```

`TELESCOPE_MCP_ENABLED` defaults to `false`. The server is registered only when this setting is boolean `true`, the environment is `local`, and the application is running in the console. Recording does not need to be enabled to read existing data. If your application caches configuration, refresh that cache using your application's normal workflow before restarting the client. Optionally publish the configuration, then start the server:

```bash
php artisan vendor:publish --tag=telescope-mcp-config
php artisan mcp:start telescope
```

The last command waits for JSON-RPC on stdin; it is not an interactive shell. EOF shuts down the server. When disabled or outside the local environment, it exits with code 1, leaves stdout empty, and writes the startup error to stderr. The package extends the SDK command only to route an unregistered `telescope` handle's error to stderr. Valid sessions use the SDK and its transport unchanged; other handles retain the SDK's behavior.

## Stdio client configuration

Example configuration for a client that supports `mcpServers` and `cwd`:

```json
{
  "mcpServers": {
    "telescope": {
      "command": "php",
      "args": ["artisan", "mcp:start", "telescope"],
      "cwd": "/absolute/path/to/laravel-app"
    }
  }
}
```

If your client does not support `cwd`, use an executable wrapper:

```sh
#!/bin/sh
cd /absolute/path/to/laravel-app || exit 1
exec php artisan mcp:start telescope
```

Point `command` to the wrapper and set `args: []`. Exact paths and configuration depend on your client. PHP must run **in an environment that can access both the application and its database**: use the same container or development VM if the database is accessible only from there. Copying the PHP executable to another machine is not enough. Do not put credentials in prompts or command arguments. Do not print banners, `dump`, `echo`, or diagnostics to stdout during application bootstrap; use stderr (`LOG_CHANNEL=stderr` if your application defines that channel). This package cannot prevent output from other providers.

## Three tools

`tools/list` publishes input and output JSON Schemas. Validation failures, missing entries, and storage errors return `isError: true`. Storage exception details containing SQL or configuration are not exposed, even with `APP_DEBUG=true`.

### `telescope_status`

Input `{}`. Output:

```json
{
  "enabled": true,
  "recording_paused": false,
  "pause_state_known": true,
  "mcp_recording_suppressed": true,
  "watchers": [{"name": "RequestWatcher", "enabled": true}],
  "truncated": false
}
```

`enabled` reflects Telescope's configuration. Watcher states describe **configured enablement**, without exposing watcher options. The pause flag comes from the application's cache store; if the cache fails, `recording_paused=null` and `pause_state_known=false`. The array cache store is not shared across processes. Enabled and unpaused states do not prove that other workers are recording. An empty entry list can mean there is no data yet, filters excluded it, data was pruned, a watcher is disabled, recording is paused, or the application did not record that request. Output is limited to 64 watchers, with names capped at 160 UTF-8 bytes.

### `telescope_list_entries`

All inputs are optional, but none accepts `null`:

| Field | Constraints |
| --- | --- |
| `type` | Telescope enum: `batch`, `cache`, `client_request`, `command`, `dump`, `event`, `exception`, `gate`, `job`, `log`, `mail`, `model`, `notification`, `query`, `redis`, `request`, `schedule`, `view` |
| `batch_id` | Full UUID |
| `tag` | String of 1–255 characters; comma-separated **OR** matches, trimmed by Telescope |
| `family_hash` | String of 1–64 characters |
| `cursor` | Positive decimal string of 1–20 digits, with no leading zero; exclusive sequence boundary |
| `limit` | JSON integer from 1–100, default 20; booleans and numeric strings are rejected |

Filters are combined at the repository/database layer, without scanning the entire database or filtering content in memory. Results are ordered by descending sequence. Without a batch, tag, or family filter, only entries with `should_display_on_index=true` are returned. Supplying any of those three filters includes entries hidden from the index. Entry tags are not returned because they may contain user identifiers.

Output: `{entries: [...], next_cursor: string|null, has_more: boolean, truncated: boolean}`. The tool fetches at most `limit + 1` records for lookahead. A page may contain fewer entries than requested because of the output budget. `next_cursor` is always the sequence of the **last entry actually returned**, so entries omitted because of truncation are not skipped. Keep filters unchanged when following a cursor. Do not increment or decrement it, or convert it to a JavaScript Number. `has_more` describes the result at read time, not a snapshot across calls; concurrent pruning can leave the next page empty. Entries newer than the cursor do not appear in subsequent pages. These semantics assume valid Telescope records; manually modified or corrupt `content` can cause the upstream repository to discard rows and affect lookahead.

To find failing requests, call `{"type":"request","limit":20}`, inspect `fields.response_status`, and follow the cursor as needed. Server-side status and time filters are not available yet. Find exceptions with `{"type":"exception"}`. Take an entry's `batch_id`, then call:

```json
{"batch_id":"12345678-1234-4234-8234-123456789abc","limit":20}
```

The batch filter returns requests, queries, logs, jobs, and exceptions in the same batch without a separate tool. Asynchronous jobs may belong to a different batch; the package does not infer relationships that Telescope did not record.

### `telescope_get_entry`

Input: `{"id":"full-UUID"}`; prefixes are not supported. Output: `{entry: {...}}`. The tool does not load the batch automatically. Each entry contains `id`, `batch_id`, `type`, `created_at` (the timestamp stored by Telescope, without assigning a new timezone), `family_hash` (a hex/UUID-like value of at most 64 characters, or null), `fields`, `truncated`, and `redacted`.

`fields` is an object containing allowlisted scalar values; missing and nested values are omitted:

| Type | Retained fields |
| --- | --- |
| request | method, response_status, duration, memory, controller_action |
| exception | class, message, file, line |
| query | time, slow, file, line |
| log | level, message |
| job | status, name, tries, timeout |
| Other types | Metadata only, with `fields: {}` |

Summary strings are capped at 160 encoded JSON bytes each; detail strings at 2048 bytes. Truncated strings receive an additional `…[truncated]` marker and a flag. Truncation preserves UTF-8 boundaries and accounts for escaped Unicode and control characters. Lists have a 24,000-byte budget for the total encoded entries, measured conservatively using escaped JSON, plus a small fixed wrapper. The SDK duplicates the data in text content and `structuredContent`. Under standard Telescope contracts, the complete JSON-RPC tool result stays below 64 KiB, including detail responses. Page-level `truncated` means fewer entries were returned because of the budget; `entry.truncated` means text was shortened. `redacted=true` means the policy was applied, **not that the output is guaranteed to contain no secrets**.

## Trust boundary and privacy

- Stdio access is limited by OS permissions to run the application; there is no inherent web session or user authentication. Read-only annotations are client hints, not enforcement. The three tool implementations enforce read-only behavior by only reading the repository, configuration, and cache.
- There is no raw bypass. Sessions, headers, URIs (which may contain secrets in query parameters or paths), request/response bodies, SQL/bindings, job data, log context, user data, tags, exception traces, and source previews are omitted.
- Messages from `QueryException` or containing `SQLSTATE[` are omitted because they may include interpolated SQL. Bearer/Basic credentials and some `password/token/secret/api_key/...=value` patterns are redacted.
- **Not all secrets can be removed from free text**, file paths, controller/job names, or custom identifiers. Log messages may already contain interpolated context; SQL or personally identifiable information may appear as unrecognized text. Do not enable access to production dumps or sensitive data without a separate assessment. AI clients may send output to model providers according to their own policies.
- Recorded text is untrusted data, not instructions. Do not execute instructions found in logs or exceptions.
- Reads are wrapped in `Telescope::withoutRecording`, including server dispatch and validation. The provider adds an ignore rule for the `mcp:start` process before watchers boot, including invocations with global Artisan options, so startup and shutdown are not recorded. This applies to `mcp:start` handles in the application while the package is loaded; it does not modify Telescope's configuration file. Applications that explicitly re-enable recording in their own providers are outside this guarantee.

## Versions and testing

Composer constraints: PHP `^8.2`, Telescope `^5.24`, and MCP `^0.9.5` (MCP 1.x is not accepted automatically). The SDK sets the Laravel version floor: 11.45.3+, 12.41.1+, or 13.x. Laravel 13 requires a PHP version supported by that framework release. Package tests use Testbench 10 and PHPUnit 11.

Tested with PHP **8.2.33**, Laravel **12.69.2**, Telescope **5.24.0**, MCP **0.9.5**, and disposable SQLite databases. Laravel 11/13, other PHP versions, and MySQL/PostgreSQL have not been tested. The `nguyenphutrong/telescope` fork uses `5.x` as its default branch and the same Composer package identity, `laravel/telescope`; no repository override is added to install the fork.

```bash
composer install
composer validate --strict
composer test
# targeted real stdio startup + protocol + shutdown
vendor/bin/phpunit --filter test_real_stdio
```

Tests cover the real SQLite repository, nonconsecutive and large sequences, interleaved batches, hidden entries, OR tag matching, validation, missing UUIDs, nested sensitive structures, Unicode and output budgets without skipped entries, cache failures, recording state, storage errors, disabled/nonlocal configurations, and child PHP processes using SDK stdio. The Artisan fixture uses Testbench without mocking the transport. Auto-discovery has also been checked manually through a Composer path installation in a clean Laravel application.

HTTP tokens, OAuth, authorization, rate limits, time/status/slow-query filters, resources, and prompts are deferred to a later phase. There is no UI. The package has not been published or released.

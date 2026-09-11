# Changelog

## 0.2.0 — 2026-09-11

### Added

- Default-off `allow_sensitive_data` permission, reported by status, and explicit `unredacted=true` detail reads. Normal get/list calls retain their redaction policy.
- Complete repository-entry access through bounded JSON text chunks and content-bound continuation cursors; stale cursors cannot mix different entry versions. Unredacted output can contain credentials and personal data.
- Tests for permission enforcement, complete reconstruction, input validation, stale/cross-entry cursors, and real stdio continuation without recording new Telescope entries.

## 0.1.0 — 2026-09-10

Initial experimental release for local development. Available from the GitHub tag and Packagist.

### Added

- Local, read-only Telescope MCP server using `laravel/mcp`, registered through Laravel package discovery.
- `telescope_status`, `telescope_list_entries`, and `telescope_get_entry` tools for recorded events and batch correlation.
- Bounded keyset pagination, full UUID validation, allowlisted output, secret redaction, and UTF-8-safe truncation.
- Default-disabled, local-only access; suppression of Telescope recording during MCP startup, reads, and shutdown.
- SQLite integration tests and real stdio protocol tests, with CI for Laravel 12 on PHP 8.2/8.4 and Laravel 13 on PHP 8.3/8.4.

### Requirements

- PHP 8.2+, Laravel `^12.61.1|^13.12`, Telescope `^5.24`, and Laravel MCP `^0.9.5`.
- Laravel 13 requires PHP 8.3+. Laravel 11 is excluded because of unresolved framework security advisories; security blocking remains enabled.

### Limitations

- Free text can still contain secrets. OS/process permissions are the stdio trust boundary; no HTTP transport is exposed.
- SQL, bindings, payloads, bodies, contexts, and exception traces are intentionally omitted.
- No status/time/slow-query filters, write tools, resources, or prompts.
- MySQL and PostgreSQL have not been tested.

# Changelog

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

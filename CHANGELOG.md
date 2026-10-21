# Changelog

All notable changes to `laravel-mysql-advisor` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-21

### Added

- 13 read-only checks for MySQL 8 and MariaDB 10.6+:
  - Indexes: `unused-index`, `redundant-index`, `unindexed-foreign-key`.
  - Queries: `full-table-scan`, `top-statements`, `n-plus-one`.
  - Tables: `missing-primary-key`, `table-size`, `fragmentation`, `stale-statistics`.
  - Server: `buffer-pool-hit-ratio`, `disk-temp-tables`, `connection-headroom`.
- Every finding carries the fix as SQL and, where one applies, as a Laravel migration or Eloquent snippet. Nothing is ever executed: `ReadOnlyGuard` refuses any statement that is not a single `SELECT` or `SHOW`.
- `php artisan mysql-advisor:report` with `--only`, `--connection`, `--format=json` and `--fail-on` (exit 1 on findings, 2 when the database cannot be inspected).
- A Blade dashboard at `/mysql-advisor`, behind the `viewMysqlAdvisor` gate (local only by default), with no assets to build.
- Graceful degradation: when performance_schema is off, a privilege is missing, or the app uses native prepared statements (invisible to performance_schema digests), the affected checks are listed as skipped with the reason and the exact `GRANT` or setting to enable them.
- `php artisan about` entry.

[Unreleased]: https://github.com/amjadiqbal/laravel-mysql-advisor/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/amjadiqbal/laravel-mysql-advisor/releases/tag/v0.1.0

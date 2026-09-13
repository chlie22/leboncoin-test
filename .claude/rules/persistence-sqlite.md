---
paths:
  - "src/FizzBuzz/Infrastructure/Persistence/**"
  - "src/FizzBuzz/Infrastructure/Cli/**"
  - "migrations/**"
  - "config/packages/doctrine*.yaml"
  - "docs/benchmarks/**"
---

# Statistics storage: SQLite with Doctrine DBAL

The reference SQL is `docs/conception.md` §6.3 and §6.4, mirrored in `docs/benchmarks/lib.php`. Keep both identical.

- Doctrine DBAL only: no ORM, no entity.
- Record transaction (`Connection::transactional()`): the UPSERT is the **first** statement. Starting with a read risks an immediate `SQLITE_BUSY` when another process writes.
- Eviction: read `max(id)` once after the UPSERT; `threshold = max(id) - N`, and no eviction when it is below 1; then `UPDATE ... WHERE id IN (...)`, delete the log rows, then delete stats rows with `hits = 0` (foreign keys impose this order). Never rewrite the decrement as `UPDATE ... FROM` with an aggregate: it scans the whole log index (5.5 ms per call instead of 0.09 ms, measured).
- Read: one statement, with `max(id)` and `min(id)` in two separate scalar subqueries; combined in one subquery, SQLite scans the log. `window_count = max - min + 1` relies on contiguous log ids: `AUTOINCREMENT`, deletions only at the head of the log.
- `applyWindowSize()`, run by the startup command, executes the eviction in `BEGIN IMMEDIATE` because its first statement is a read.
- `busy_timeout` (200 ms), `synchronous = FULL`, `journal_size_limit` and foreign keys are per connection: DBAL middlewares (`EnableForeignKeys`, `SqliteConnectionPragmas`), never migrations. `journal_mode = WAL` is persistent and set by a non-transactional migration.
- Translate expected storage failures (lock timeout, read-only or missing file) into `StatisticsStoreUnavailable`; let programming errors propagate.
- After any change to this SQL: `php docs/benchmarks/02-fenetre-exactitude.php` must report 0 mismatch; check the query plans with `php -d memory_limit=1G docs/benchmarks/01-stockage.php`; replace the file in `docs/benchmarks/results/`.

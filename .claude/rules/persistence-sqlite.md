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
- Record transaction, written by hand and never with `Connection::transactional()`:
  - `beginTransaction()` comes before the `try`, then `commit()`.
  - On error, call `rollBack()` while DBAL still counts a transaction, otherwise send a raw `ROLLBACK`. Ignore a failing rollback and translate the original error.
  - The raw `ROLLBACK` covers a failed `COMMIT`: DBAL counts the transaction as ended, but SQLite keeps it open after `SQLITE_BUSY`.
  - Why: when SQLite rolls the whole transaction back itself (`RAISE(ROLLBACK)`, some `SQLITE_FULL`), `transactional()` still calls `rollBack()`, which throws "There is no active transaction" and hides the original error.
- The UPSERT is the **first** statement. Starting with a read risks an immediate `SQLITE_BUSY` when another process writes.
- Eviction: read `max(id)` once after the UPSERT; `threshold = max(id) - N`, and no eviction when it is below 1; then `UPDATE ... WHERE id IN (...)`, delete the log rows, then delete stats rows with `hits = 0` (foreign keys impose this order). Never rewrite the decrement as `UPDATE ... FROM` with an aggregate: it scans the whole log index (5.5 ms per call instead of 0.09 ms, measured).
- Read: one statement, with `max(id)` and `min(id)` in two separate scalar subqueries; combined in one subquery, SQLite scans the log. `window_count = max - min + 1` relies on contiguous log ids: `AUTOINCREMENT`, deletions only at the head of the log.
- `applyWindowSize()`, run by the startup command, executes the eviction in `BEGIN IMMEDIATE` because its first statement is a read.
- `busy_timeout` (200 ms), `synchronous = FULL`, `journal_size_limit` and foreign keys are per connection: DBAL middlewares (`EnableForeignKeys`, `SqliteConnectionPragmas`), never migrations. `journal_mode = WAL` is persistent and set by a non-transactional migration.
- Translate expected storage failures into `StatisticsStoreUnavailable`:
  - The test is on the SQLite primary result code (`code & 0xFF`), never on the DBAL exception class. DBAL's SQLite converter classifies by message substrings and puts disk-full and I/O errors in the generic `DriverException`.
  - Translated codes: PERM 3, BUSY 5, READONLY 8, IOERR 10, CORRUPT 11, FULL 13, CANTOPEN 14, PROTOCOL 15, NOTADB 26.
  - Everything else propagates as a programming error: ERROR 1, LOCKED 6, CONSTRAINT 19 and the rest.
- `STATS_WINDOW_SIZE` is read raw (`%env(STATS_WINDOW_SIZE)%`) and parsed strictly by the adapter factory: only a decimal integer >= 1 is accepted. Never use `%env(int:...)%`, which truncates floats (`1.5` becomes 1).
- DBAL `logging: false`: in debug mode, DoctrineBundle would log the bound `str1` and `str2` values (§8.1).
- After any change to this SQL: `php docs/benchmarks/02-fenetre-exactitude.php` must report 0 mismatch; check the query plans with `php -d memory_limit=1G docs/benchmarks/01-stockage.php`; replace the file in `docs/benchmarks/results/`.

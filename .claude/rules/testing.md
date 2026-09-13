---
paths:
  - "tests/**"
  - "phpunit.dist.xml"
---

# Tests

Levels, placement and required cases: `docs/conception.md` §9.1.

- Domain and Application unit tests never boot the kernel and never touch SQLite. Use case tests use `tests/Support/InMemoryRequestStatisticsStore`.
- The port contract is written once, in the abstract `tests/Contract/RequestStatisticsStoreContractTest.php`. The in-memory adapter test and the SQLite integration test both extend it; a contract change goes there first.
- SQLite test database: `var/test.db` (`DATABASE_URL` in `.env.test`).
  - It is migrated by `make test-db`, a prerequisite of the test targets, and by the "Migrations de test" CI step. Run `make test-db` before calling `vendor/bin/phpunit` directly on a fresh clone.
  - Tests clear both tables in `setUp()`: `SqliteTestDatabase::clear()` also resets `sqlite_sequence`, so log ids restart at 1.
  - Use a small window (for example N = 5) to observe evictions.
- `tests/Support/SqliteTestDatabase` provides the kernel connection, isolated connections with the application's middlewares, and the checks of invariants 2 to 5 of §6.3.
- A test that needs its own file (migrations, concurrency) migrates a temporary file in a subprocess. Never copy `var/test.db`: it is in WAL mode. Concurrency tests share one file, never `:memory:` databases.
- Remove temporary SQLite files together with their `-wal` and `-shm` files.
- Functional tests cover the input matrix of `docs/conception.md` §3.3 row by row, with the exact message, and assert that `HEAD` never counts.
- Behaviour owned by Nginx (quotas, 413, 414 and 429 bodies, `Cache-Control`, log contents) is tested in `tests/Smoke/`, never simulated in a `WebTestCase`.
- A test is reported as passing only after running it and reading its exit code.

---
paths:
  - "tests/**"
  - "phpunit.dist.xml"
---

# Tests

Levels, placement and required cases: `docs/conception.md` §9.1.

- Domain and Application unit tests never boot the kernel and never touch SQLite. Use case tests use `tests/Support/InMemoryRequestStatisticsStore`.
- The port contract is written once, in the abstract `tests/Contract/RequestStatisticsStoreContractTest.php`. The in-memory adapter test and the SQLite integration test both extend it; a contract change goes there first.
- SQLite tests use a temporary file (`DATABASE_URL` from `.env.test`), clear both tables in `setUp()`, and use a small window (for example N = 5) to observe evictions. Concurrency tests share one file, never `:memory:` databases.
- Functional tests cover the input matrix of `docs/conception.md` §3.3 row by row, with the exact message, and assert that `HEAD` never counts.
- Behaviour owned by Nginx (quotas, 413, 414 and 429 bodies, `Cache-Control`, log contents) is tested in `tests/Smoke/`, never simulated in a `WebTestCase`.
- A test is reported as passing only after running it and reading its exit code.

# PHP / Symfony reference

Layout, naming, framework wiring, Deptrac and tests for this repository. Source of truth: `docs/conception.md` §5, §9 and §10.

## Contents

- Directory layout
- Naming
- PHP and Symfony specifics
- Deptrac
- Tests

## Directory layout

```
src/
├── FizzBuzz/
│   ├── Domain/
│   │   ├── FizzBuzzParameters.php          # value object, invariants in the constructor
│   │   ├── FizzBuzzGenerator.php           # pure domain service
│   │   └── Exception/InvalidFizzBuzzParameters.php
│   ├── Application/
│   │   ├── GenerateFizzBuzz.php            # use case, execute()
│   │   ├── GetMostFrequentRequest.php      # use case, execute()
│   │   ├── Model/RequestStatistics.php     # read model
│   │   ├── Port/RequestStatisticsStore.php # outbound port
│   │   └── Exception/StatisticsStoreUnavailable.php
│   └── Infrastructure/
│       ├── Api/                            # controllers + GenerateFizzBuzzQuery (HTTP DTO and constraints)
│       ├── Cli/ApplyStatisticsWindowCommand.php
│       └── Persistence/                    # SqliteRequestStatisticsStore, SqliteConnectionPragmas
└── Shared/
    └── Infrastructure/
        ├── Http/                           # HealthController, JsonErrorFormatSubscriber
        └── Logging/RequestIdProcessor.php
tests/
├── Unit/FizzBuzz/{Domain,Application}/
├── Unit/Support/InMemoryRequestStatisticsStoreTest.php   # runs the contract suite
├── Contract/RequestStatisticsStoreContractTest.php        # abstract
├── Integration/FizzBuzz/{Persistence,Cli}/
├── Functional/
├── Smoke/smoke.sh
├── Load/fizzbuzz.js
└── Support/InMemoryRequestStatisticsStore.php
```

A new class goes into an existing directory of this tree. A new directory is a design change: check it against `docs/conception.md` §10 first.

## Naming

| Element | Rule | Example |
|---|---|---|
| Value object | business noun, no technical suffix | `FizzBuzzParameters` |
| Domain service | business noun describing the role | `FizzBuzzGenerator` |
| Use case | verb + object, no suffix, single public `execute()` | `GenerateFizzBuzz`, `GetMostFrequentRequest` |
| Outbound port | the business need, no `Interface` suffix, in `Application/Port/` | `RequestStatisticsStore` |
| Adapter | technology + port name | `SqliteRequestStatisticsStore`, `InMemoryRequestStatisticsStore` |
| Read model | what it describes, in `Application/Model/` | `RequestStatistics` |
| HTTP DTO | use case + `Query` | `GenerateFizzBuzzQuery` |
| Controller | use case + `Controller` | `GenerateFizzBuzzController` |
| Exception | the problem, no `Exception` suffix, in the layer's `Exception/` | `InvalidFizzBuzzParameters`, `StatisticsStoreUnavailable` |
| Console command | action + `Command`; command name `app:<area>:<action>` | `ApplyStatisticsWindowCommand`, `app:statistics:apply-window` |
| DBAL middleware | technology + purpose | `SqliteConnectionPragmas` |

## PHP and Symfony specifics

- `declare(strict_types=1)` in every file. `final` by default. `readonly` on value objects, DTOs and read models. No `mixed` for convenience.
- Constructor injection only; no service locator.
- `config/services.yaml`: alias `RequestStatisticsStore` to `SqliteRequestStatisticsStore`; exclude `src/FizzBuzz/Domain/` value objects and `src/FizzBuzz/Application/Model/` from service registration.
- `config/routes.yaml`: `resource: routing.controllers` (Symfony 8.1 recipe) loads `#[Route]` attributes from registered service classes (`AttributeServicesLoader`), not from a folder scan. Controllers live in `src/FizzBuzz/Infrastructure/Api/` and `src/Shared/Infrastructure/Http/`, not `src/Controller/`, and are registered by the `App\` resource in `config/services.yaml`.
- Window size: `%env(int:STATS_WINDOW_SIZE)%`, injected into the SQLite adapter, which rejects values below 1.
- Exception to status mapping lives in `config/packages/framework.yaml` (`framework.exceptions`), not in a custom subscriber.
- Doctrine DBAL only: no ORM configuration, no entity mapping.

## Deptrac

`deptrac.yaml` at the repository root. Syntax verified against the Deptrac documentation (`docs/collectors.md`, `docs/index.md`):

- the `directory` collector assigns project classes to layers by file path;
- the `classLike` collector matches fully qualified names against a regular expression. It is used for vendor namespaces. `.` stands for the namespace separator, which avoids backslashes in YAML.

```yaml
deptrac:
  paths:
    - ./src
  layers:
    - name: Domain
      collectors:
        - type: directory
          value: src/FizzBuzz/Domain/.*
    - name: Application
      collectors:
        - type: directory
          value: src/FizzBuzz/Application/.*
    - name: Infrastructure
      collectors:
        - type: directory
          value: src/FizzBuzz/Infrastructure/.*
    - name: Shared
      collectors:
        - type: directory
          value: src/Shared/.*
    - name: PsrLog
      collectors:
        - type: classLike
          value: ^Psr.Log.*
    - name: Framework
      collectors:
        - type: classLike
          value: ^Symfony.*
        - type: classLike
          value: ^Doctrine.*
        - type: classLike
          value: ^Monolog.*
  ruleset:
    Domain: ~
    Application:
      - Domain
      - PsrLog
    Infrastructure:
      - Application
      - Domain
      - Framework
      - PsrLog
    Shared:
      - Framework
      - PsrLog
```

Run it as:

```bash
vendor/bin/deptrac analyse --fail-on-uncovered
```

**Why `--fail-on-uncovered`:** by default, Deptrac does not report a dependency on a class that belongs to no layer. Without the option, a vendor namespace missing from the configuration would slip through. With it, every dependency must land in a declared layer; PHP internal classes stay ignored (`ignore_uncovered_internal_classes` defaults to `true`). When Infrastructure legitimately needs a new vendor namespace, add it to the `Framework` layer.

**Prove the rule when it is set up (plan step 2):** temporarily add a `use Symfony\...` import in a Domain class, check that `make lint` fails, then revert. This also confirms that `classLike` matches vendor classes that are outside `paths`. The Deptrac documentation does not state that explicitly.

## Tests

| Layer | Suite | Kernel | Storage |
|---|---|---|---|
| Domain | `tests/Unit/FizzBuzz/Domain/` | no | none |
| Application | `tests/Unit/FizzBuzz/Application/` | no | `InMemoryRequestStatisticsStore` |
| Port contract | `tests/Contract/`, extended by both adapter tests | no | per adapter |
| SQLite adapter, command | `tests/Integration/FizzBuzz/` | only if needed | temporary SQLite file |
| HTTP | `tests/Functional/` (`WebTestCase`) | yes | temporary SQLite file |
| Nginx, headers, logs, startup | `tests/Smoke/smoke.sh` | through Docker | Docker volume |

Detailed cases per level: `docs/conception.md` §9.1.

---
name: hexagonal-conventions
description: Hexagonal architecture and DDD conventions of the FizzBuzz API - layer boundaries, dependency direction, where ports and adapters live, naming, decisions already made. Use when creating or moving a class, deciding which layer code belongs in, or answering "where does this go?" in this repository.
argument-hint: "[class or concept to place]"
---

# Hexagonal conventions

Project decisions only; DDD and ports-and-adapters theory is not repeated here. Source of truth: `docs/conception.md` §5 and §10. If this skill and the spec disagree, the spec wins and this skill must be fixed.

For the directory layout, naming table, Deptrac configuration and test placement, read [references/php-symfony.md](references/php-symfony.md).

## The one rule

Dependencies point inward: `Infrastructure -> Application -> Domain`. Domain depends on nothing.

Delete test: if `src/FizzBuzz/Infrastructure/` and `src/Shared/` disappeared, Domain and Application must still compile and their unit tests must still pass.

## Layers

| Layer | Contains | May depend on | Must never depend on |
|---|---|---|---|
| Domain `src/FizzBuzz/Domain/` | value object `FizzBuzzParameters`, domain service `FizzBuzzGenerator`, domain exceptions | PHP only | Symfony, Doctrine, Application, Infrastructure |
| Application `src/FizzBuzz/Application/` | use cases, outbound port `Port/RequestStatisticsStore`, read model `Model/RequestStatistics`, application exceptions | Domain, `Psr\Log\LoggerInterface` | Symfony, Doctrine, Infrastructure |
| Infrastructure `src/FizzBuzz/Infrastructure/` | HTTP adapters `Api/`, SQLite adapter `Persistence/`, console commands `Cli/` | Application, Domain, Symfony, Doctrine DBAL | — |
| Shared infrastructure `src/Shared/Infrastructure/` | health check, JSON error format, request-id log processor | Symfony, Doctrine DBAL | business code of `src/FizzBuzz/` |

## Decisions already made

Do not reopen them without a new fact; raise it with the developer instead.

1. **Ports live in `Application/Port/`**, declared by the use cases that need them. Not in Domain: the domain has no persistence need.
2. **No entity, no aggregate.** `FizzBuzzParameters` is a value object identified by its five values. SQLite row ids are technical and never leave `Infrastructure/Persistence/`.
3. **The adapter owns the transaction.** `RequestStatisticsStore::record()` is atomic inside `SqliteRequestStatisticsStore`. There is no transaction port.
4. **One use case = one class = one public method `execute()`.** No command bus, no Messenger. Controllers call use cases directly.
5. **Use cases never return framework types.** `GenerateFizzBuzz` returns `list<string>`; `GetMostFrequentRequest` returns the `RequestStatistics` read model.
6. **Degraded mode is an application policy.** `GenerateFizzBuzz` catches `StatisticsStoreUnavailable`, logs a warning without the parameters, and still returns the sequence.
7. **Validation has two levels.** HTTP contract constraints (`NotBlank`, `Range`, `Length`, `Regex`) live on the Infrastructure DTO `GenerateFizzBuzzQuery`. Domain invariants (`int1`, `int2`, `limit` >= 1) live in the `FizzBuzzParameters` constructor. No Symfony attribute in Domain.
8. **Storage maintenance stays out of the port.** `applyWindowSize()` belongs to the SQLite adapter and is called by `Cli/ApplyStatisticsWindowCommand` at startup.
9. **An interface needs a second implementation.** `RequestStatisticsStore` has two (SQLite, and in-memory for tests), both running the same abstract contract test suite. No other interface is justified today.

## Patterns deliberately excluded

Command bus or CQRS, domain events, one interface per use case, aggregates and entities, generic repositories, circuit breaker (`docs/conception.md` §5.9 and §15.5). Proposing one requires a concrete, current need.

## Severity, for reviews

| Level | Example | Action |
|---|---|---|
| Blocking | Domain or Application imports Symfony or Doctrine; business rule in a controller, a subscriber or the SQLite adapter; Deptrac missing or failing | fix before committing |
| Discuss | port declared outside `Application/Port/`; use case doing two things; new interface with a single implementation | raise it; fix it or record the decision in the spec |
| Note | naming drift from the reference table | comment only |

A deviation recorded in `docs/conception.md` (decision tables or alternatives section) is not a finding.

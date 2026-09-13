---
name: feature-workflow
description: End-to-end workflow for implementing a step of the implementation plan (docs/conception.md §12) or any non-trivial change in this repository - read the spec, plan, build test-first, architecture review, verification, documentation sync. Use when starting a plan step, a new endpoint or behaviour, or a change touching more than one layer, before writing code.
argument-hint: "[plan step number | change description]"
---

# Feature workflow

Orchestration only: each step delegates to an existing skill or agent. Target: $ARGUMENTS (a step of `docs/conception.md` §12, or a change description).

## Hard rules

- Validated decisions in `docs/conception.md` §2 and §13 are not reopened inside an implementation step. A spec gap is a question for the developer, not a blank to fill.
- `docs/openapi.yaml` is the contract: the code is fixed to match it. Changing the contract updates both documents first.
- Never commit unless the developer asks.

## Sequence

Copy this checklist and tick an item only when its done criterion holds:

```
Step progress:
- [ ] 1. Scope: spec sections read, done criterion from §12 restated
- [ ] 2. Gaps: questions asked in one batch, or none
- [ ] 3. Plan written and reviewed
- [ ] 4. Built test-first, inside out
- [ ] 5. Architecture review: no blocking finding
- [ ] 6. Verification: checks run, exit codes read
- [ ] 7. Documents, contract and docs/progress.md in sync
- [ ] 8. Commit message proposed; the developer decides
```

| # | Step | Delegate to | Skip when |
|---|---|---|---|
| 1 | Read only the spec sections the step touches: list the headings with `grep -nE '^#{2,3} ' docs/conception.md`, then read those line ranges (the whole spec is ~26k tokens). Restate the done criterion from §12 | — | never |
| 2 | Clarify only the gaps that change the implementation | `superpowers:brainstorming` | the spec fully specifies the step |
| 3 | Plan: files by layer (§10), tests by level (§9.1), invariants touched (§6), documents to update | `superpowers:writing-plans`, then the `plan-reviewer` agent | single-file change |
| 4 | Implement test-first | the `tdd-implementer` agent, or `superpowers:test-driven-development` inline | never |
| 5 | Architecture check on the change | the `arch-reviewer` agent | never |
| 6 | Run the checks and read their exit codes | `superpowers:verification-before-completion` | never |
| 7 | Update what the change made stale | — | never |
| 8 | Non-architecture review, then a Conventional Commits message | `/code-review` | never |

Steps 1 to 3 run in the main session: they may need the developer.

## Step 4 - test order

Inside out, matching the dependency direction and `docs/conception.md` §9.1:

1. Domain unit tests: no framework, no doubles.
2. Application unit tests with `tests/Support/InMemoryRequestStatisticsStore`.
3. Port contract changes: first in `tests/Contract/RequestStatisticsStoreContractTest.php`, which both adapters run.
4. Integration tests against a temporary SQLite file: SQL, pragmas, query plans, failures.
5. Functional tests (`WebTestCase`): the §3.3 matrix rows for the endpoints touched.
6. Smoke tests through Nginx when the step touches Docker, Nginx or headers.

Write the domain test first even when an endpoint is the visible deliverable.

## Step 6 - verification

- Before the Makefile exists (plan steps 1 and 2): run the tools actually installed and state which checks could not run.
- Once it exists: `make ci`, exit code 0. Never a hand-picked subset of tests.
- A change to the statistics SQL also runs `php docs/benchmarks/02-fenetre-exactitude.php` (0 mismatch) and checks the query plans with `php -d memory_limit=1G docs/benchmarks/01-stockage.php`.

## Step 7 - document sync

| Change | Update |
|---|---|
| HTTP contract: parameters, responses, headers, errors | `docs/openapi.yaml`, then `npx --yes @redocly/cli@2.52.1 lint`, then `docs/conception.md` §3 and §4 |
| Statistics SQL or schema | `docs/conception.md` §6 and `docs/benchmarks/lib.php`; rerun the benchmarks and replace the results file |
| New command, Makefile target or tool | the commands section of `CLAUDE.md` |
| New figure or verified behaviour in the spec | a `[source]`, `[mesure]`, `[objectif]` or `[hypothèse]` tag |
| Plan step started, done or blocked; end of session | `docs/progress.md`: step status with its proof (command and exit code, or commit hash), "Où on en est", "Prochaine action" with the pitfalls found for the next step, decisions taken, one journal line (5 latest entries kept, file under 80 lines) |

## Anti-patterns

- Writing the controller first and "extracting" the domain later.
- Adding an interface, an event or a bus for a case that does not exist yet.
- Declaring a step done from a partial test run.
- Changing a validated decision inside an implementation step instead of raising it.

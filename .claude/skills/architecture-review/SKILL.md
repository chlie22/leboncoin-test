---
name: architecture-review
description: Reviews a diff or a set of paths of this repository against its hexagonal conventions - Deptrac layer rules, framework leaks, business logic placement, port placement, use case shape, test placement. Use before committing a plan step, when reviewing a change, or when asked whether code respects the architecture.
argument-hint: "[path | branch]"
---

# Architecture review

Scope: architecture only. Bugs, security and performance belong to `/code-review`; do not duplicate them. Conformance of the SQL, the HTTP contract and the runtime to `docs/conception.md` is covered by the `architecture-audit` workflow.

Load the `hexagonal-conventions` skill before reviewing.

## Scope of the review

- In a git repository: the changed lines, `git diff --merge-base main`, or the branch given as argument.
- Before `git init`, or when paths are given: those paths only.
- Never edit files. Report; the developer decides.

## Checks, cheapest first

Copy this checklist and tick it as you go:

```
Architecture review:
- [ ] 1. Deptrac layer rules
- [ ] 2. Framework leaks into Domain or Application
- [ ] 3. Business logic placement
- [ ] 4. Ports and interfaces
- [ ] 5. Use case shape
- [ ] 6. Excluded patterns
- [ ] 7. Test placement
- [ ] 8. Naming
```

**1. Deptrac.** Run the lint target (`make lint`), or Deptrac alone as documented in [the reference](../hexagonal-conventions/references/php-symfony.md). Any violation is blocking.
If `deptrac.yaml` is missing or Deptrac cannot run, report it first as blocking: the layer rule is then unproven. A text search is only a stopgap and never proves the layers clean:

```bash
grep -rnE '^use (Symfony|Doctrine)' src/FizzBuzz/Domain src/FizzBuzz/Application || echo "no obvious leak (not a proof)"
```

**2. Framework leaks.** A Symfony or Doctrine import, attribute, type or exception in Domain or Application: blocking. `Psr\Log\LoggerInterface` in Application is allowed.

**3. Business logic placement.** FizzBuzz substitution, window arithmetic or degraded-mode decisions inside a controller, the HTTP DTO, a subscriber or the SQLite adapter: blocking. The adapter owns SQL and the transaction, not policy.

**4. Ports and interfaces.** An outbound port outside `Application/Port/`, a new interface with a single implementation, or a method added to `RequestStatisticsStore` that no use case needs: discuss.

**5. Use case shape.** A use case returning a framework type: blocking. More than one public method besides the constructor, or a boolean parameter switching behaviour: discuss.

**6. Excluded patterns.** Command bus, domain events, entities or aggregates, circuit breaker, without a decision recorded in the spec: discuss.

**7. Test placement.** A Domain or Application unit test that boots the kernel or touches SQLite; a use case test not using `tests/Support/InMemoryRequestStatisticsStore`; an adapter not running the shared contract suite: discuss.

**8. Naming.** Against the naming table of the reference: note.

## Report

One line per finding: `path:line - SEVERITY - rule - what to do`.

```
src/FizzBuzz/Domain/FizzBuzzParameters.php:12 - BLOCKING - Symfony Validator attribute in Domain - move the constraint to Infrastructure/Api/GenerateFizzBuzzQuery.
src/FizzBuzz/Application/GenerateFizzBuzz.php:31 - DISCUSS - use case also shapes the HTTP response - return list<string> and encode in the controller.
```

- Blocking findings first; only they stop a commit.
- Every finding has a file and a line.
- At most 10 findings, highest value first.
- A deviation documented in `docs/conception.md` is not a finding.
- If nothing is blocking, say so in one line and stop.

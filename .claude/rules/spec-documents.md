---
paths:
  - "docs/conception.md"
  - "docs/openapi.yaml"
  - "docs/benchmarks/README.md"
  - "redocly.yaml"
---

# Spec and contract documents

- `docs/conception.md` stays in French; API messages and `docs/openapi.yaml` field names stay in English.
- Every new figure or behavioural claim in `docs/conception.md` carries a tag: `[source]` (verified in source code or official documentation, with the version), `[mesure]` (script and output in `docs/benchmarks/`), `[objectif]` (to be demonstrated by a future test) or `[hypothèse]`. A future test is never described as executed.
- Decisions marked validated (§2 and §13) change only with the developer's agreement; record the change in both tables and in the alternatives section (§15).
- The contract and the spec change together. After editing `docs/openapi.yaml`, run `npx --yes @redocly/cli@2.52.1 lint`: a passing lint validates syntax and examples, not server behaviour.
- These documents contain escape sequences in examples (`\u00e9`, `\z`, `\n`). Some writing tools convert them silently: grep for them after every write.

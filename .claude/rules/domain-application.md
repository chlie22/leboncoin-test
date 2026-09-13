---
paths:
  - "src/FizzBuzz/Domain/**"
  - "src/FizzBuzz/Application/**"
---

# Domain and Application layers

- Pure PHP. Domain imports nothing outside Domain; Application imports Domain and `Psr\Log\LoggerInterface` only. Deptrac enforces it.
- No Symfony or Doctrine attribute, type or exception here, including validation constraints: HTTP constraints belong to `src/FizzBuzz/Infrastructure/Api/GenerateFizzBuzzQuery.php`.
- `FizzBuzzParameters` enforces `int1`, `int2`, `limit` >= 1 in its constructor and throws `InvalidFizzBuzzParameters`. The upper bounds (10 000, 50 code points) are HTTP concerns, not domain invariants.
- `FizzBuzzGenerator` records whether a divisor matched instead of testing the produced string: an empty `str1` produces `""`, and `"0"` is never treated as false.
- Use cases: one class, one public `execute()`. `GenerateFizzBuzz` calls `record()` exactly once. On `StatisticsStoreUnavailable` it logs `statistics.record_skipped` at warning level with the class names only (`error_class`, `cause_class`), never a message nor the parameters, and still returns the sequence. No retry.
- The port `RequestStatisticsStore` has exactly two methods, `record()` and `findMostFrequent()`. Storage maintenance such as window reduction is never added to it.
- Exceptions are named after the problem, without an `Exception` suffix, in the `Exception/` namespace of their layer.

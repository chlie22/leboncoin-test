---
paths:
  - "src/FizzBuzz/Infrastructure/Api/**"
  - "src/Shared/Infrastructure/Http/**"
  - "config/packages/framework.yaml"
  - "docs/openapi.yaml"
---

# HTTP API

`docs/openapi.yaml` is the contract. A change to parameters, responses, headers or errors updates it first, then `npx --yes @redocly/cli@2.52.1 lint`, then `docs/conception.md` §3 and §4.

- `#[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]`: Symfony defaults to 404.
- Every parameter error is a 400 problem+json with `violations`. Conversion errors (`int1=abc`, arrays) are reported alone, because Symfony skips validation when a conversion fails: documented behaviour, not a bug. Array values on nullable DTO properties yield Symfony's union messages (`int|null`, `null|string`), not bare `int` / `string`.
- `JsonErrorFormatSubscriber` sets the request format to `json` and rewrites error `Content-Type` from `application/json` to `application/problem+json`. Invalid UTF-8 in a violation payload (`str1=%FF`) needs `serializer.encoder.json` with `JSON_INVALID_UTF8_SUBSTITUTE` (see `config/services.yaml`), or the error renderer falls back to HTML.
- Integers: nullable, `NotNull` then `Range`. The upper bound also rejects values the serializer silently casts to `PHP_INT_MAX`.
- Strings: nullable, `NotBlank` (the serializer keeps `''` for strings, which `NotNull` and `RegexValidator` would accept), then `Sequentially([Length(max: 50), Regex('/^\P{Cc}+\z/u')])`. `"0"` and `" "` stay valid.
- In PHP, `$` accepts a trailing newline: keep the `\z` anchor. In `docs/openapi.yaml`, keep `^\P{Cc}+$` (ECMA-262 syntax).
- Missing and empty parameters share one message: `This parameter is required and must not be empty.`
- `HEAD` is routed like `GET`: validate, answer without body, never generate or count.
- Encode JSON once: `json_encode($data, JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`, then `JsonResponse::fromJsonString()`. `AbstractController::json()` overrides global options, and `setEncodingOptions()` re-encodes the data.
- Never set `Cache-Control` in PHP: Nginx is its only source.
- Exception mapping (`framework.exceptions`): `StatisticsStoreUnavailable` to 503, reaching clients only from `/v1/stats`; `InvalidFizzBuzzParameters` to 500, since after HTTP validation it can only be a programming error.
- `/healthz` reads both statistics tables. Storage unavailable after startup returns 200 `{"status":"degraded"}`, never 503.

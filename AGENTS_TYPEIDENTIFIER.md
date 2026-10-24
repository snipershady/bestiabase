# AGENTS.md — `snipershady/typeidentifier`

Integration guide for AI coding agents working in a project that depends on
`snipershady/typeidentifier` (PHP ≥ 8.3, library ≥ 2.0).

Copy this file into the consuming project (root `AGENTS.md`, or append it as a
section of an existing one). Everything below describes how to **use** the
library; it does not describe how to develop it.

---

## 1. What the library is for

It reads untyped input (superglobals, decoded JSON, config arrays, legacy
`mixed` values) and returns it as the **effective PHP primitive type**:
`"42"` → `int(42)`, `"1.5"` → `float(1.5)`, `"hello"` → `string`, arrays typed
recursively.

**Rule for agents:** whenever code in this project reads a value from
`$_GET`, `$_POST`, `$_COOKIE`, `$_SERVER`, `$_ENV`, or a loosely-typed array,
use this library instead of `isset()` + manual casts, `filter_input()` or
`(int) $_GET['x'] ?? 0`.

```php
// ❌ Don't
$page = isset($_GET['page']) ? (int) $_GET['page'] : 0;
$name = trim($_POST['name'] ?? '');

// ✅ Do
$page = $ept->getIntValueFromGet('page');
$name = $ept->getStringValueFromPost('name', trim: true);
```

---

## 2. Setup

```bash
composer require snipershady/typeidentifier
```

Namespaces:

| Symbol | FQCN |
| --- | --- |
| Service (concrete, `final readonly`) | `TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService` |
| Service contract | `TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface` |
| HTML sanitizer contract | `TypeIdentifier\Sanitizer\HtmlSanitizerServiceInterface` |
| Default HTML sanitizer | `TypeIdentifier\Sanitizer\HtmlSanitizerService` |
| Exception marker | `TypeIdentifier\Exception\TypeIdentifierExceptionInterface` |
| Depth exception | `TypeIdentifier\Exception\MaxDepthExceededException` (`\RuntimeException`) |

### Instantiation

```php
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

$ept = new EffectivePrimitiveTypeIdentifierService();
// optional: inject a custom sanitizer
$ept = new EffectivePrimitiveTypeIdentifierService(new MyHtmlSanitizer());
```

The service is stateless and immutable: **one shared instance is enough**.

### Dependency injection (preferred)

Type-hint the **interface**, never the concrete class, so the service can be
mocked in tests.

```php
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface;

final readonly class ListProductsController
{
    public function __construct(
        private EffectivePrimitiveTypeIdentifierServiceInterface $input,
    ) {}
}
```

Symfony (`config/services.yaml`):

```yaml
services:
    TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService: ~
    TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface:
        alias: TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService
```

Laravel (`AppServiceProvider::register()`):

```php
$this->app->singleton(
    \TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface::class,
    \TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService::class,
);
```

---

## 3. Choosing the right method

Decision order — pick the **first** that fits:

1. **You know the expected scalar type** → use a *typed accessor*
   (`get{Int,Float,Bool,String}Value…`). Returns a guaranteed scalar, never
   `null`, never an array. **This is the default choice.**
2. **You need to distinguish "missing" from "zero/empty"**, or the value may
   legitimately be an array (`field[]`) → use `getTypedValue…` and handle
   `null` / `array` yourself.
3. Never read the superglobal directly.

### Source × type matrix

| Source | Untyped (nullable, may be array) | `int` | `float` | `bool` | `string` |
| --- | --- | --- | --- | --- | --- |
| any `mixed` value | `getTypedValue($data, …)` | `getIntValue` | `getFloatValue` | `getBoolValue` | `getStringValue` |
| array key | `getTypedValueFromArray($key, $array, …)` | `getIntValueFromArray` | `getFloatValueFromArray` | `getBoolValueFromArray` | `getStringValueFromArray` |
| `$_GET` | `getTypedValueFromGet($key, …)` | `getIntValueFromGet` | `getFloatValueFromGet` | `getBoolValueFromGet` | `getStringValueFromGet` |
| `$_POST` | `getTypedValueFromPost` | `getIntValueFromPost` | `getFloatValueFromPost` | `getBoolValueFromPost` | `getStringValueFromPost` |
| `$_COOKIE` | `getTypedValueFromCookie` | `getIntValueFromCookie` | `getFloatValueFromCookie` | `getBoolValueFromCookie` | `getStringValueFromCookie` |
| `$_SERVER` | `getTypedValueFromServer` | `getIntValueFromServer` | `getFloatValueFromServer` | `getBoolValueFromServer` | `getStringValueFromServer` |
| `$_ENV` | `getTypedValueFromEnv` | `getIntValueFromEnv` | `getFloatValueFromEnv` | `getBoolValueFromEnv` | `getStringValueFromEnv` |

Do **not** invent other names (e.g. `getIntFromGet`, `getQueryInt`,
`getValueFromRequest`): only the methods in the table exist.

### Signatures

```php
// Untyped — return array|bool|int|float|string|null
getTypedValue(mixed $data, bool $trim = false, bool $forceString = false, bool $sanitizeHtml = false, int $maxDepth = 64)
getTypedValueFromArray(int|string $needle, ?array $array, bool $trim = false, bool $forceString = false, bool $sanitizeHtml = false, int $maxDepth = 64)
getTypedValueFrom{Get|Post|Cookie|Server|Env}(string $needle, bool $trim = false, bool $forceString = false, bool $sanitizeHtml = false, int $maxDepth = 64)

// Typed — never null
get{Int|Float|Bool}Value(mixed $data, bool $trim = false): int|float|bool
getStringValue(mixed $data, bool $trim = false, bool $forceString = false, bool $sanitizeHtml = false): string

get{Int|Float|Bool}ValueFromArray(int|string $needle, ?array $array, bool $trim = false)
getStringValueFromArray(int|string $needle, ?array $array, bool $trim = false, bool $forceString = false, bool $sanitizeHtml = false)

get{Int|Float|Bool}ValueFrom{Get|Post|Cookie|Server|Env}(string $needle, bool $trim = false)
getStringValueFrom{Get|Post|Cookie|Server|Env}(string $needle, bool $trim = false, bool $forceString = false, bool $sanitizeHtml = false)
```

Note the argument order for arrays: **key first, array second**
(`getIntValueFromArray('port', $config)`).

### Flags

Always pass flags as **named arguments** — positional booleans are unreadable
and easy to misplace.

| Flag | Available on | Effect |
| --- | --- | --- |
| `trim` | all methods | trims leading/trailing whitespace of string results |
| `forceString` | untyped methods + `getString…` | keeps numeric-looking strings as strings (`"007"` stays `"007"`) |
| `sanitizeHtml` | untyped methods + `getString…` | strips tags/entities and removes `& < > " % ( ) +` |
| `maxDepth` | untyped methods only | max array nesting (default `64`, must be `>= 0`) |

```php
$zip   = $ept->getStringValueFromPost('zip', trim: true, forceString: true);
$tags  = $ept->getTypedValueFromPost('tags', maxDepth: 2);
$about = $ept->getStringValueFromPost('about', trim: true, sanitizeHtml: true);
```

Passing `forceString` / `sanitizeHtml` / `maxDepth` to `getInt…`, `getFloat…`
or `getBool…` throws an `\Error` (unknown named parameter) and is flagged by
PHPStan: they don't accept them by design.

---

## 4. Defaults for missing / non-matching values

| Accessor | Missing key / `null` | Non-numeric string | Non-empty array | Empty array |
| --- | --- | --- | --- | --- |
| `getInt…` | `0` | `0` | `1` | `0` |
| `getFloat…` | `0.0` | `0.0` | `1.0` | `0.0` |
| `getBool…` | `false` | PHP truthiness | `true` | `false` |
| `getString…` | `""` | the string | `""` | `""` |
| `getTypedValue…` | `null` | the string | typed array | `[]` |

If `0`, `0.0`, `false` or `""` is a **valid** user value and you must tell it
apart from "not sent", use the untyped method and check `null`:

```php
$qty = $ept->getTypedValueFromPost('qty');
if ($qty === null) {
    // field not submitted
}
```

Defaults for absent values:

```php
$limit = $ept->getTypedValueFromGet('limit') ?? 20;      // OK: null-coalesce on the untyped method
$limit = $ept->getIntValueFromGet('limit') ?: 20;        // OK only if 0 is not a valid limit
$limit = $ept->getIntValueFromGet('limit') ?? 20;        // ❌ never null, ?? is dead code
```

---

## 5. Pitfalls — read before writing code

1. **Leading zeros are lost without `forceString`.** `"007"` → `int(7)`,
   `"00123"` → `int(123)`. For IDs, ZIP/postal codes, phone numbers, IBAN,
   codes and any identifier, use `getString…(…, forceString: true)`.
2. **`"false"` is truthy.** `getBool…("false")` → `true`; `"true"` and
   `"false"` are **not** converted to booleans by `getTypedValue()` (they stay
   strings). Only `"0"`, `""`, `0`, `null`, `[]` are falsy. For checkboxes
   send `"1"`/`"0"` or compare the string explicitly:
   ```php
   $enabled = $ept->getStringValueFromEnv('FEATURE_X', trim: true) === 'true';
   ```
3. **Other numeric forms are promoted too:** `"1e3"` → `float(1000)`,
   `"1.0"` → `float(1)`, integers beyond `PHP_INT_MAX` → `float`, `" 42 "` →
   `int(42)`. Hex like `"0x1A"` stays a string.
4. **`getInt…` truncates floats:** `"3.9"` → `3`. Use `getFloat…` and round
   explicitly if rounding matters.
5. **`sanitizeHtml` is not output escaping.** It is a lossy input filter (it
   also removes `& ( ) + % "`, so `"Tom & Jerry (2)"` loses characters). Do
   not enable it for passwords, URLs, JSON, Markdown, emails or any field
   where those characters are meaningful. Always escape at output time
   (`htmlspecialchars()`, Twig/Blade auto-escaping) regardless.
6. **Never trim passwords or secrets** — `trim: true` would alter them.
7. **Arrays collapse in typed accessors:** a `field[]` input passed to
   `getString…` returns `""`, to `getInt…` returns `0`/`1`. For multi-value
   fields use `getTypedValueFrom…` and validate that the result `is_array()`.
8. **The library types, it does not validate.** Range checks, required
   fields, formats (email, date, enum) remain the caller's job.
9. **Prepared statements are still mandatory.** A typed int is safe to bind,
   but never concatenate any returned string into SQL.

---

## 6. Error handling

Only methods that walk arrays can throw:

| Exception | Cause | What to do |
| --- | --- | --- |
| `MaxDepthExceededException` (implements `TypeIdentifierExceptionInterface`) | input nests deeper than `$maxDepth` (hostile payload, self-referencing array) | catch and reject the input (HTTP 400) |
| `\InvalidArgumentException` | `$maxDepth` is negative | programming bug — **do not catch**, fix the call |

```php
use TypeIdentifier\Exception\TypeIdentifierExceptionInterface;

try {
    $payload = $ept->getTypedValue(json_decode($body, true, flags: JSON_THROW_ON_ERROR));
} catch (TypeIdentifierExceptionInterface) {
    return new JsonResponse(['error' => 'Payload rejected'], 400);
}
```

Catch the **interface**, not `\RuntimeException`, so programming errors are not
swallowed. Raise `maxDepth` only for trusted, known-deep data.

---

## 7. Superglobal behaviour

- Reads go through `filter_input()` (correct under a web SAPI) and fall back to
  the `$_*` array when it returns `null` (CLI, PHPUnit, values set at runtime).
  So in tests you can simply populate `$_GET` / `$_POST` etc.
- `$_ENV` is often empty depending on `variables_order`; for env vars loaded by
  a framework (Symfony Dotenv, Laravel), prefer the framework's config/
  parameters or `getTypedValue(getenv('X'))` / `getIntValue(getenv('X'))`.
- In frameworks with a Request object (Symfony `Request`, PSR-7), prefer the
  array source over the superglobal one:
  ```php
  $page = $ept->getIntValueFromArray('page', $request->query->all());
  $body = $ept->getTypedValueFromArray('items', $request->toArray());
  ```

---

## 8. Testing code that uses the library

- Mock `EffectivePrimitiveTypeIdentifierServiceInterface` for unit tests of
  your own classes; don't mock the concrete `final` class (it can't be).
- Or use the real service (it has no I/O besides reading superglobals) and set
  `$_GET` / `$_POST` / `$_SERVER` in `setUp()`, resetting them in `tearDown()`.

---

## 9. Quick reference recipes

```php
// Pagination
$page  = max(1, $ept->getIntValueFromGet('page'));
$limit = min(100, $ept->getIntValueFromGet('limit') ?: 20);

// Form
$email   = $ept->getStringValueFromPost('email', trim: true);
$age     = $ept->getIntValueFromPost('age', trim: true);
$price   = $ept->getFloatValueFromPost('price', trim: true);
$consent = $ept->getBoolValueFromPost('consent');               // checkbox value "1"
$zip     = $ept->getStringValueFromPost('zip', trim: true, forceString: true);
$comment = $ept->getStringValueFromPost('comment', trim: true, sanitizeHtml: true);

// Multi-value field (tags[])
$tags = $ept->getTypedValueFromPost('tags', trim: true, maxDepth: 1);
$tags = is_array($tags) ? $tags : [];

// Headers / server
$ua     = $ept->getStringValueFromServer('HTTP_USER_AGENT');
$length = $ept->getIntValueFromServer('CONTENT_LENGTH');

// Config array
$port    = $ept->getIntValueFromArray('port', $config);
$timeout = $ept->getFloatValueFromArray('timeout', $config);
$host    = $ept->getStringValueFromArray('host', $config, trim: true);
```

# AGENTS.md

Guidance for AI coding agents (Claude, Copilot, Cursor, etc.) working
on the **better-data** codebase. Human contributors should read
`README.md` first; this file distils the conventions an agent needs to
produce changes that match the project's taste and stay consistent
with the surrounding code.

Treat this as a set of **skills** — focused capabilities an agent
invokes when the task calls for them. Each skill lists its triggers,
the steps that earn the house style, and the specific landmines to
avoid.

---

## Ground rules (always apply)

- **PHP 8.3+ only.** Readonly classes, constructor promotion, typed
  constants, `#[\Override]`, `json_validate()`. No 8.4 features yet
  (we deliberately hold back property hooks / asymmetric visibility
  until a baseline release).
- **No framework.** No Laravel helpers, Symfony components, or
  Doctrine types in `src/`. WordPress function calls are allowed but
  every source/sink engine in `src/Internal/` must stay WP-free so
  it can be unit-tested without a WP runtime.
- **`readonly` everywhere.** Every `DataObject` subclass is
  `final readonly class`. Never introduce a mutable DTO path —
  immutability is load-bearing for `Secret`, `with()`, and reasoning
  about sink projections.
- **Style:** PSR-12 via `vendor/bin/php-cs-fixer fix`. Short grouped
  imports, trailing commas on multi-line calls, never a space before
  `(` on function calls.
- **Static analysis:** PHPStan level 6 stays clean. If you silence a
  diagnostic, use `@phpstan-ignore-next-line` with a one-line
  justification; never a blanket ignore.
- **Testing discipline:** Unit tests in `tests/Unit`, fixtures in
  `tests/Fixtures`. Every behavioural change adds or updates unit
  tests. Live-WP concerns get an additional scenario in the companion
  plugin's stress suite.
- **Comments:** The codebase prefers docblocks over inline comments.
  Inline comments exist to explain *why* a seemingly-odd choice was
  made (e.g. "WP recurser fatals on stdClass items — use an array").
  Don't narrate the *what*.

### After every change, run:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix
```

All three must be green before the change is done.

---

## Skill 1 — Add a new DataObject fixture / real DTO

**Trigger:** any task that introduces a new typed data shape
(`UserProfileDto`, `OrderDto`, test fixture, etc.).

**Steps:**

1. File lives under `src/…` for production DTOs or `tests/Fixtures/`
   for test-only fixtures; plugin-level DTOs live under
   `wp-content/plugins/better-data-plugin-test/src/Dto/`.
2. `final readonly class Foo extends DataObject`. Never skip
   `readonly`, never skip `final`.
3. Prefer constructor promotion. Declare the most-specific type
   possible (`?DateTimeImmutable` over `?string`, `Secret` over
   `string` for credentials, `BackedEnum` over `string` for closed
   sets).
4. **Every trailing constructor parameter needs a default.** PHP
   silently demotes earlier defaults to "required" when a later
   parameter has no default (Reflection reports
   `isDefaultValueAvailable: false`, and
   `MissingRequiredFieldException` fires at hydration). `int $id = 0`
   everywhere, `string $foo = ''`, `public ?T $bar = null`.
5. Decorate with attributes:
   - `#[MetaKey('key', type: 'number', showInRest: true)]` for meta
   - `#[PostField('post_date_gmt')]` / `#[UserField]` / `#[TermField]`
     / `#[Column]` for renames
   - `#[Sensitive]` for PII plain strings
   - `#[Encrypted]` next to `#[MetaKey]` for at-rest encryption
   - `#[ListOf(Element::class)]` on `array` properties that should
     coerce elements
   - `#[Rule\Required]`, `#[Rule\Email]`, etc. for validation
   - `#[DateFormat('Y-m-d')]` on DateTime fields that want a
     non-default serialization format
6. If the DTO talks to WP, `use HasWpSources;` and/or
   `use HasWpSinks;`. Don't manually reimplement `::fromPost`.
7. For the Presenter's admin rendering, add `use HasPresenter;` so
   `$dto->present()` works.

**Anti-patterns:**

- Defaulting a `Secret` to `new Secret('')`. Use `?Secret = null`
  instead — an empty-string secret is worse than no secret.
- Adding `encrypt: true` without also typing the property as `Secret`.
  The lib permits plain-string-with-encrypt, but the Secret type is
  the only way to prevent in-memory leaks.
- Giving a DTO a public mutator or non-readonly property. Always
  `->with([...])`.

**Example:**

```php
final readonly class ProductDto extends DataObject
{
    use HasWpSources;
    use HasWpSinks;

    public function __construct(
        public int $id = 0,
        #[Rule\Required] public string $post_title = '',
        public string $post_status = 'publish',
        public string $post_type = 'product',
        #[PostField('post_date_gmt')] public ?\DateTimeImmutable $publishedAt = null,
        #[MetaKey('_price'), Rule\Min(0)] public float $price = 0.0,
        #[MetaKey('_sku'), Rule\Regex('/^[A-Z]{2,4}-\d+$/')] public string $sku = '',
        #[MetaKey('_api_key'), Encrypted] public ?Secret $apiKey = null,
        #[MetaKey('_notes'), Sensitive] public ?string $notes = null,
    ) {}
}
```

---

## Skill 2 — Add a new attribute

**Trigger:** a new declarative hint for DTO parameters (e.g.
`#[ArrayOf]`, `#[Default]`, a new rule).

**Steps:**

1. Live in `src/Attribute/`.
2. `#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]`.
3. `final readonly class` with constructor-promoted public properties
   carrying the attribute args.
4. Docblock explains **what it does**, **where it is read** (sinks,
   sources, Presenter, RestSchemaBuilder…), and **what composes
   with what** (e.g. "Pairs naturally with `public Secret $field`").
5. Never embed business logic in the attribute — keep it a pure data
   carrier. The logic lives in the consumer (SinkProjection,
   AttributeDrivenHydrator, Presenter, etc.).
6. Wire the attribute into every relevant engine. A partial wire is a
   footgun:
   - Read-side: `AttributeDrivenHydrator`, `DataObject::coerceParameter`.
   - Write-side: `SinkProjection::prepareValue`, `OptionSink::projectForStorage`.
   - Schema: `RestSchemaBuilder::buildProperty`.
   - Presenter: `Presenter::sensitiveFieldNames` / filters / etc.

**Anti-patterns:**

- Adding an attribute + wiring it into *one* sink. Stress scenarios
  have caught this pattern before (the original `encrypt` was
  meta-only; `#[Encrypted]` replaced it once options needed the
  same semantics).

---

## Skill 3 — Add a new validation rule

**Trigger:** a rule that isn't in the built-in set.

**Steps:**

1. File: `src/Validation/Rule/YourRule.php`.
2. `#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY, Attribute::IS_REPEATABLE)]`.
3. `final readonly class YourRule implements RuleInterface`.
4. Implement `check(mixed $value, string $fieldName, mixed $subject): ?string` — return `null` on pass, a human-readable error string on failure. Keep messages short; consumers wrap them in localised strings when shown to users.
5. If the rule should surface in JSON Schema output (for
   `register_rest_route` args), add a mapping in
   `RestSchemaBuilder::applyRuleAttribute`.
6. Add unit tests in `tests/Unit/ValidationTest.php` covering:
   - Happy path
   - Explicit failure path
   - Null handling (does the rule apply to nulls?)
   - Edge case the rule's name suggests (empty string for `Required`,
     boundary for `Min`, …)

**Anti-patterns:**

- Throwing from `check()`. Rules return null/string; exceptions are
  for the engine, not individual rules.
- Introducing runtime config (e.g. reading environment variables) in
  a rule. Rules are pure.

---

## Skill 4 — Add a new source adapter

**Trigger:** reading from a WP store the library doesn't cover yet
(comment, attachment, transient, etc.).

**Steps:**

1. File under `src/Source/` for the public API; engine under
   `src/Internal/` stays WP-free.
2. `hydrate(int|object $record, string $dtoClass): DataObject` +
   `hydrateMany(list<int> $ids, string $dtoClass): list<DataObject>`
   — the two-method shape every existing source uses.
3. Prewarm caches in `hydrateMany`:
   - `_prime_post_caches` / equivalent for the object
   - `update_meta_cache` for meta
4. Meta fetcher closure contract: **return `null` when the key does
   not exist**, return the stored value (including `''`) when it
   does. `AttributeDrivenHydrator` distinguishes
   "missing → use default" from "stored empty string" via this
   contract.
5. Add a `HasWpSources` companion trait entry if the source should
   expose a static shortcut (`::fromComment($id)`).
6. Unit tests cover the pure engine behaviour with a fake fetcher;
   live-WP behaviour is a stress scenario.

**Anti-patterns:**

- Calling `get_meta($key, true)` without a `metadata_exists` check
  first. You'll conflate "empty string" with "missing".
- Looking up `WP_User` via `new WP_User($id)` inside a hot loop. Use
  `get_user_by('id', $id)` so the object cache participates.

---

## Skill 5 — Add a new sink

**Trigger:** writing to a store the library doesn't cover yet
(comment meta, REST upload, custom taxonomy hierarchy).

**Steps:**

1. File under `src/Sink/`.
2. Mirror the shape of `PostSink`:
   - `toArgs(DataObject $dto, ?array $only = null, bool $strict = false, bool $skipNullDeletes = false)`
   - `toMeta(…)` returning `['write' => [...], 'delete' => [...]]`
   - `insert(…)`, `update(…)`, `save(…)` — each forwards all flags to
     the inner projection.
3. Convenience methods (`insert`, `update`, `save`) must pass values
   through `wp_slash()` before calling any `update_*_meta` /
   `wp_insert_*` / `wp_update_*`. Projection methods leave values raw.
4. Honour `#[Encrypted]` by routing writes through
   `EncryptionEngine::encrypt`. Never silently skip encryption for any
   sink that accepts rich types.
5. Add a `HasWpSinks` trait method (`saveAsComment`) if the sink
   should expose a static-feeling shortcut.
6. Unit test the projection (pure logic, no WP). Stress-test the
   convenience methods against real WP.

**Anti-patterns:**

- Forgetting `wp_slash`. Any `\"` in a payload will round-trip as `"`.
- Asymmetric behaviour: if you encrypt on write but don't decrypt on
  read (or vice versa), the feature is worse than not existing. See
  the early Phase-8.7 OptionSink Secret bug for why.
- Storing rich types as PHP-serialized objects (class names in the
  DB). Always unwrap through `SinkProjection::prepareValue` which
  recurses into arrays and turns `DataObject` instances into arrays.

---

## Skill 6 — Extend the Presenter

**Trigger:** a new output transformation (mask a field, format a
URL, compute from context, etc.).

**Steps:**

1. Decide whether the behaviour is per-call (fluent builder method on
   `Presenter`) or per-context (on `PresentationContext`).
2. For the fluent builder, mirror the shape of existing methods:
   return `$this` (or `static` / `self`) so chains compose.
3. For `CollectionPresenter`, add the matching `$this->configurers[]
   = fn (Presenter $p) => $p->yourMethod(...)` recording so the
   collection replays it on every item.
4. Any new data source that reads from the DTO: honour
   `#[Sensitive]` and the `Secret` type. A new method that bypasses
   the `sensitiveFieldNames()` redaction is a security regression.
5. Whenever the method emits text that could be localised, wrap the
   call through `LocaleScope::runIn($ctx->locale, fn () => ...)` so
   `withLocale('hu_HU')` works.

**Anti-patterns:**

- Revealing a Secret "for convenience" in a computed field without a
  `->reveal()` call. The explicit reveal is the audit point.
- Mutating `$this->dto` or any other builder state permanently. Every
  call returns a builder with adjusted state; the DTO is never
  modified.

---

## Skill 7 — Modify hydration or coercion behaviour

**Trigger:** a bug report or feature touching how strings become
typed values.

**Steps:**

1. Changes land in `src/Internal/TypeCoercer.php` (primitives +
   enums + DateTime + Secret) or `src/DataObject.php::coerceParameter`
   (attribute-aware: `#[ListOf]`, `#[Encrypted]` decrypt).
2. `TypeCoercer::coerce` is pure and must stay pure — no side
   effects, no WP calls. Feed it a `ReflectionType` and get back a
   value or a `TypeCoercionException`.
3. Attribute-driven coercion lives **above** TypeCoercer: read the
   attribute, do the rich-type dance (e.g. walk a list, decrypt an
   envelope), then either return directly or call TypeCoercer with
   a simpler value.
4. Every coercion path needs a unit test. The file
   `tests/Unit/TypeCoercionTest.php` is the home for primitive + WP
   builtins; attribute-aware tests live in their own files
   (`ListOfTest.php`, `EncryptedAttributeTest.php`, `SecretTest.php`).

**Anti-patterns:**

- Leaking a runtime dependency into `TypeCoercer`. It must be
  callable from a no-WordPress unit test.
- Using `settype()` or `intval()` on unchecked input. Go through
  the explicit helpers (`toString`, `toInt`, `toFloat`, `toBool`,
  `toArray`) and throw `TypeCoercionException` on anything
  surprising.

---

## Skill 8 — Security-sensitive changes

**Trigger:** any edit that touches `Secret`, `EncryptionEngine`,
`#[Sensitive]`, `#[Encrypted]`, `MetaKeyRegistry::register`,
`RequestSource` guards, or `user_pass` handling.

**Steps:**

1. **Threat model first.** Write (in the PR description or a code
   comment) what the change is preventing and what remains
   un-prevented.
2. **Loud over silent.** Missing key → throw. Tampered ciphertext →
   throw. Unknown strict-whitelist field → throw. Colliding
   route-owned field → throw. A silent degradation is the worst
   outcome for a security feature.
3. **Symmetric end-to-end.** If you encrypt on write, decrypt on
   read. If you redact on toArray, reveal on sink-write. If you add
   a new leak path, cover it in `SecretTest::testXxxDoesNotLeak`.
4. **Never cache the raw key.** `EncryptionEngine` re-reads the
   constant/filter on every call. Don't add a static cache — a
   process-long cache defeats rotation.
5. **Constant-time comparison.** Any string comparison involving
   secret material uses `hash_equals`. `==` and `===` are timing
   oracles.
6. **Unit tests** must include a tamper probe (flip a byte, expect
   `DecryptionFailedException`) and a leak probe
   (`print_r`/`var_dump`/`json_encode`/`serialize` must not contain
   the raw value).
7. **Stress scenario** covers the WP boundary (REST response,
   wp_options row, post meta).

**Anti-patterns:**

- Adding a "debug mode" that logs secrets even conditionally.
- Adding a new `decrypt()` path without the
  `DecryptionFailedException` contract.
- Relaxing `__serialize` to redact instead of throw. The throwing
  behaviour is deliberate — a caller that serialized a Secret has
  already made a security-relevant mistake.

---

## Skill 9 — Compose with better-route

**Trigger:** any task that asks better-data to work with
`better-route`, register DTO-backed REST endpoints, feed DTO schemas
into better-route OpenAPI output, or move request data from a
better-route handler into a better-data `DataObject`.

**Steps:**

1. Read the better-route flow first when behaviour is unclear:
   `../better-route/README.md`, `src/Router/Router.php`,
   `src/Router/RouteBuilder.php`, and `src/OpenApi/OpenApiExporter.php`.
   Do not edit better-route unless the user explicitly asks for it.
2. Use `BetterData\Route\BetterRouteBridge` as the integration seam.
   The bridge is deliberately method-name based so better-data remains
   installable without a hard Composer dependency on better-route.
3. Preferred route → data flow:
   `BetterRouteBridge::{get,post,put,patch,delete}($router, $uri,
   Dto::class, $handler, $options)`. The bridge registers the route,
   hydrates the request into the DTO, validates it, calls the handler
   with `(DataObject $dto, mixed $request)`, and presents returned
   `DataObject` values through `Presenter` with
   `PresentationContext::rest()`.
4. For URL-owned fields, always set `routeFields`, for example
   `['id']`. Those values are merged from URL params and rejected from
   JSON/body/query buckets via `RequestParamCollisionException`; this
   is the route-side version of `RequestSource::noCollision()`.
5. Let the bridge generate `RouteBuilder::args()` and better-route
   `meta()` from `MetaKeyRegistry::toRestArgs()` /
   `toJsonSchema()`. Use `BetterRouteBridge::openApiComponents()` when
   passing DTO schemas into `BetterRoute::openApiExporter()`.
6. Keep permission and middleware concerns route-owned. Pass
   `permissionCallback` and `middlewares` through bridge options
   rather than adding an auth abstraction to the data layer.
7. Unit tests live in `tests/Unit/BetterRouteBridgeTest.php` with fake
   Router/RouteBuilder/request objects. Do not require WordPress or
   better-route to be installed for pure bridge tests. Add companion
   plugin smoke/stress coverage only for live-WP behaviour.

**Anti-patterns:**

- Adding `better-route/better-route` as a hard runtime dependency of
  better-data.
- Reimplementing better-route's Resource DSL in better-data. The
  bridge is for DTO request/response/schema composition, not another
  router/resource layer.
- Hydrating write routes from merged params while also accepting
  route-owned fields like `id`. Use explicit `source` plus
  `routeFields`.
- Duplicating schema inference instead of reusing
  `MetaKeyRegistry` / `RestSchemaBuilder`.
- Returning raw `DataObject::toArray()` from new bridge code and
  bypassing Presenter redaction.

---

## Skill 10 — Work on the companion plugin

**Trigger:** changes under
`wp-content/plugins/better-data-plugin-test/`.

**Steps:**

1. The plugin is **not** part of the library's public API — feel
   free to break its internals aggressively to demonstrate a point.
2. Three test tiers:
   - `src/Smoke/` — regression smoke (never tolerate a FAIL here).
     Every library change adds at least one smoke scenario that
     exercises the new behaviour against live WordPress.
   - `src/Stress/` — deep integration scenarios. Produce `OK`,
     `FAIL`, or `NOTE` findings. `NOTE` is for discovered
     inconsistencies or scope limits worth surfacing without failing
     the run.
   - Admin pages — eyeball-level proof of the library's behaviour
     (e.g. `ShopSettingsPage` renders `print_r($dto)` to visually
     confirm Secret redaction).
3. The Widget Shop fixture (`bd_widget` CPT + `bd_order` CPT +
   `ShopSettingsDto`) is the canonical "realistic consumer". Extend
   it rather than inventing a new fixture unless the new use case
   doesn't fit.
4. CLI commands are the main driving surface:
   `wp better-data {test,stress,seed,purge,inventory}`. Add a new
   subcommand when the scenario category grows large enough to
   warrant it.

**Anti-patterns:**

- Putting integration-only behaviour in the library. The plugin is
  the integration testbed.
- Making the plugin depend on anything beyond `better-data` itself —
  no WooCommerce, no ACF, no Composer packages outside the library.
  It must run on a clean WP install.

---

## Skill 11 — Commit message shape

**Trigger:** every commit.

**Steps:**

1. Subject: conventional-commits-ish but without the strict
   tooling. `feat(phase-X): one-line summary`,
   `fix(source): …`, `docs: …`, `chore: …`.
2. Body explains **why**, not just **what**. A future-you reader
   should understand the motivation without opening the diff.
3. If the change was driven by a discovery (stress finding,
   external review, user bug report), say so and link the source
   (commit hash, issue number, or "stress scenario X").
4. Quote test numbers: "155 unit tests (+3), PHPStan L6 clean, 87/87
   smoke green, 49 OK / 0 FAIL / 4 NOTE stress". They make regression
   spotting trivial.
5. End with:
   ```
   Co-Authored-By: <AI identifier>
   ```

**Anti-patterns:**

- `fix: bug fixed`. Always name the symptom, the root cause, and
  the verification strategy.
- Squashing unrelated changes. One concern per commit.

---

## Quick decision table

| You're about to… | Skill to read |
|---|---|
| Introduce a new DTO field typed as `Secret` | 1 + 8 |
| Add an attribute like `#[ArrayOf]` | 2 |
| Need `Rule\CreditCard` | 3 |
| Support a new WP store (comment, attachment) | 4 + 5 |
| Change how `Presenter` renders a DateTime | 6 |
| Touch how a string becomes a `DateTimeImmutable` | 7 |
| Add at-rest encryption support to a new sink | 5 + 8 |
| Reproduce a live-WP bug | 9 |

---

## What NOT to do (globally)

- **Don't silently skip WP function availability.** If you call
  `wp_verify_nonce`, wrap in `\function_exists` so unit tests still
  run. But in a live plugin path, the function is always present —
  guards there should surface clear errors, not degrade.
- **Don't add a "magic" transformation.** Every coercion path the lib
  does is documented. If you're tempted to snake-case ↔ camelCase
  automatically, use an explicit attribute instead.
- **Don't introduce optional globals.** The library has one optional
  global: `BETTER_DATA_ENCRYPTION_KEY` (+ its `_PREVIOUS` sibling).
  No other constants, no filter soup.
- **Don't add dependencies.** The only hard deps are `php: ^8.3` and
  `ext-openssl`. Dev deps are PHPUnit, PHPStan, php-cs-fixer, WP
  stubs, WC stubs. Nothing else.
- **Don't break readonly.** Every touched DTO stays `final readonly
  class`. Every value object (`Secret`, `ValidationResult`,
  `PresentationContext`) stays immutable.
- **Don't cache reflection results in a static property.** Lib is
  short-lived per request; the premature optimisation complicates
  invalidation without speeding up anything that matters.

---

## When in doubt

- Read the relevant file in `src/Internal/` — that's where the
  engines live, and they're written to be self-explanatory.
- Run `wp better-data stress` from the companion plugin to see what
  the library's live behaviour looks like.
- If a change feels like it needs a new architectural concept (a
  service container, a registry, a bus), stop and ask. This library
  has stayed small on purpose; pushing back on complexity is a
  feature, not a bug.

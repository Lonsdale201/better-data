# better-data

> **version 1.0** · PHP 8.3+ DTO & Presenter library for WordPress

Typed hydration of WordPress data into immutable DTOs, contextual output
shaping back to REST / admin / email / CSV. WordPress-aware without
pulling you into an ORM. Composer-friendly, framework-free,
MIT-licensed.

---

## What it is

`better-data` gives a WordPress plugin or theme three small layers that
work together:

1. **DataObject** — typed, immutable(-ish) DTOs declared via PHP 8's
   `readonly class` + constructor-promoted properties. Hydrate from any
   shape (array, `WP_Post`, `WP_User`, REST request, `wpdb` row, option
   payload); serialize back when needed.
2. **Sources / Sinks** — WP-aware adapters that move values between
   your DTOs and WordPress storage (posts + meta, users + meta, terms +
   meta, options, custom tables). Every sink has a projection layer so
   you can inspect or test the write payload before it hits the DB.
3. **Presenter** — contextual output shaping. The same DTO becomes a
   REST response, an admin-list row, a CSV line, or a localized email
   body depending on the `PresentationContext` you hand it.

On top of that sit the security primitives — `Secret`, `#[Sensitive]`,
`#[Encrypted]` — a validation layer that plays nicely with
`register_rest_route`'s arg validator, and a registry that feeds
`register_meta` from your DTO shape.

## What it is NOT

- **Not an ORM.** No relationships, no query builder, no lazy loading,
  no schema migrations. Use whichever query layer your plugin already
  has (`WP_Query`, `get_users`, `$wpdb`) and hand the results to a
  source.
- **Not a form renderer.** Data shaping only — HTML is the consumer's
  problem.
- **Not a DI container / service bus / router.** Our sibling library
  `better-route` handles the routing layer; better-data only ships an
  optional bridge that lets DTOs feed better-route handlers, args, and
  OpenAPI metadata.
- **Not a secrets manager.** `Secret` + `#[Encrypted]` provide
  leak-proof in-memory handling and at-rest AES-256-GCM for meta and
  option values. Key distribution, rotation, and vault integration are
  the consumer's responsibility.

## Requirements

- PHP **8.3+** (readonly classes, readonly-clone, typed class
  constants, `#[\Override]`, `json_validate()`)
- WordPress (tested against current stable; no version floor
  enforced — the sources fall back gracefully when optional WP
  functions are absent)
- `ext-openssl` when you use `#[Encrypted]` (ships with every modern
  PHP)

## Installation

The package is not on Packagist yet. Install it via a Composer VCS
repository pointing at the public GitHub repo:

```json
{
  "require": {
    "better-data/better-data": "^1.0"
  },
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Lonsdale201/better-data"
    }
  ],
  "prefer-stable": true
}
```

Then:

```bash
composer update better-data/better-data
```

After the package lands on Packagist you can drop the `repositories`
block and keep only `require`.

---

## Quick start

### 1. Declare a DTO

```php
use BetterData\Attribute\Encrypted;
use BetterData\Attribute\MetaKey;
use BetterData\Attribute\PostField;
use BetterData\Attribute\Sensitive;
use BetterData\DataObject;
use BetterData\Secret;
use BetterData\Sink\HasWpSinks;
use BetterData\Source\HasWpSources;
use BetterData\Validation\Rule;

final readonly class ProductDto extends DataObject
{
    use HasWpSources;
    use HasWpSinks;

    public function __construct(
        public int $id = 0,
        #[Rule\Required, Rule\MinLength(2)]
        public string $post_title = '',
        public string $post_status = 'publish',
        public string $post_type = 'product',
        #[PostField('post_date_gmt')]
        public ?\DateTimeImmutable $publishedAt = null,

        #[MetaKey('_price', type: 'number', showInRest: true)]
        #[Rule\Min(0)]
        public float $price = 0.0,

        #[MetaKey('_stock', type: 'integer', showInRest: true)]
        public int $stock = 0,

        #[MetaKey('_sku', type: 'string', showInRest: true)]
        #[Rule\Required, Rule\Regex('/^[A-Z]{2,4}-\d+$/')]
        public string $sku = '',

        #[MetaKey('_vendor_api_key'), Encrypted]
        public ?Secret $vendorApiKey = null,

        #[MetaKey('_internal_note'), Sensitive]
        public ?string $internalNote = null,
    ) {}
}
```

### 2. Read / write

```php
// Hydrate from a post id
$product = ProductDto::fromPost(42);
$product->price;                    // 19.99 (coerced from WP's string meta)
$product->vendorApiKey->reveal();   // 'sk_live_abc…' (decrypted at read)

// Immutable update
$updated = $product->with(['price' => 24.99]);

// Persist back (insert if id=0, update otherwise)
$updated->saveAsPost();

// Bulk-hydrate efficiently (post + meta prewarmed in 2 SQL queries)
$products = ProductDto::fromPosts([1, 2, 3, 4, 5]);
```

### 3. Validate

```php
$result = $product->validate();
if (!$result->isValid()) {
    foreach ($result->flatten() as $error) {
        error_log($error);
    }
}

// Or fail-fast during hydration:
$product = ProductDto::fromArrayValidated($_POST);
```

### 4. Present

```php
use BetterData\Presenter\PresentationContext;
use BetterData\Presenter\Presenter;

// REST JSON — Secret and #[Sensitive] fields auto-excluded
$json = Presenter::for($product)
    ->context(PresentationContext::rest())
    ->only(['id', 'post_title', 'price', 'sku', 'priceFormatted'])
    ->compute('priceFormatted', fn ($p) => wc_price($p->price))
    ->rename('post_title', 'title')
    ->toJson();

// Admin list
$rows = Presenter::forCollection($products)
    ->context(PresentationContext::admin())
    ->hideUnlessCan('cost_breakdown', 'manage_options')
    ->formatDate('publishedAt', 'Y-m-d H:i')
    ->toArray();
```

### 5. Hook into WP REST

```php
use BetterData\Registration\MetaKeyRegistry;

add_action('init', function () {
    register_post_type('product', [...]);
    MetaKeyRegistry::register(
        ProductDto::class,
        objectType: 'post',
        subtype: 'product',
    );
});

// Generate register_rest_route args from the DTO shape
add_action('rest_api_init', function () {
    register_rest_route('shop/v1', '/products', [
        'methods'  => 'POST',
        'args'     => MetaKeyRegistry::toRestArgs(ProductDto::class),
        'callback' => fn (\WP_REST_Request $r) =>
            BetterData\Source\RequestSource::from($r)
                ->requireNonce('shop_save')
                ->requireCapability('edit_posts')
                ->bodyOnly()
                ->into(ProductDto::class)
                ->saveAsPost(),
    ]);
});
```

### 6. Compose with better-route

`BetterData\Route\BetterRouteBridge` wires a DTO into a
better-route `Router` without making better-route a hard dependency of
this package.

```php
use BetterData\Route\BetterRouteBridge;
use BetterRoute\BetterRoute;

add_action('rest_api_init', function () {
    $router = BetterRoute::router('shop', 'v1');

    BetterRouteBridge::post(
        $router,
        '/products',
        ProductDto::class,
        function (ProductDto $dto): ProductDto {
            $id = $dto->saveAsPost();

            return $dto->with(['id' => $id]);
        },
        [
            'operationId' => 'productsCreate',
            'tags' => ['Products'],
            'envelope' => true,
            'permissionCallback' => static fn (): bool => current_user_can('edit_posts'),
        ],
    );

    BetterRouteBridge::patch(
        $router,
        '/products/(?P<id>\d+)',
        ProductDto::class,
        function (ProductDto $dto): ProductDto {
            $dto->saveAsPost(only: ['price', 'stock']);

            return $dto;
        },
        [
            'source' => 'json',
            'routeFields' => ['id'],
            'operationId' => 'productsUpdate',
            'tags' => ['Products'],
            'envelope' => true,
            'permissionCallback' => static fn (): bool => current_user_can('edit_posts'),
        ],
    );

    $router->register();
});
```

The bridge:

- hydrates and validates the DTO from query / JSON / body / URL params
- marks route-owned fields (for example `id`) as URL-authoritative and
  rejects body/query collisions
- feeds `MetaKeyRegistry::toRestArgs()` into `RouteBuilder::args()`
- feeds generated `requestSchema`, `responseSchema`, tags, scopes, and
  parameters into `RouteBuilder::meta()`
- presents returned `DataObject` values through `Presenter` with
  `PresentationContext::rest()`

For better-route's OpenAPI exporter, merge DTO schemas into the
exporter options:

```php
$components = BetterData\Route\BetterRouteBridge::openApiComponents([
    ProductDto::class,
]);

$openApi = BetterRoute::openApiExporter()->export(
    $router->contracts(true),
    ['components' => $components],
);
```

---

## Architecture at a glance

```
                      ┌──────────────┐
                      │  Consumer    │ (your plugin / theme)
                      └──────┬───────┘
                             │
         ┌───────────────────┼───────────────────┐
         ▼                   ▼                   ▼
    ┌─────────┐        ┌──────────┐        ┌──────────┐
    │ Source  │        │ DataObj  │        │ Presenter│
    │ adapter │───────►│  (DTO)   │───────►│  layer   │
    │         │        │          │        │          │
    │ PostSrc │        │ readonly │        │ REST     │
    │ UserSrc │        │ typed    │        │ admin    │
    │ TermSrc │◄───────│          │───────►│ email    │
    │ Option  │        │ Secret/  │        │ CSV      │
    │ Row     │        │ DateTime │        │ JSON     │
    │ Request │        │ Enum     │        │          │
    └────┬────┘        └────┬─────┘        └──────────┘
         │                  │
         ▼                  ▼
    ┌─────────┐        ┌──────────┐
    │ TypeCrc │        │  Sink    │
    │ coerce  │        │ adapter  │
    │ strings │        │          │
    │ → types │        │ PostSink │
    └─────────┘        │ UserSink │
                       │ TermSink │
                       │ Option   │
                       │ Row      │
                       └──────────┘
                             │
                             ▼
                       ┌──────────┐
                       │ WordPress│
                       │  (posts, │
                       │  users,  │
                       │  meta,   │
                       │  options)│
                       └──────────┘
```

---

## Core concepts

### DataObject

The base class. Subclasses declare their shape via constructor
promotion, must be `readonly`, and get for free:

| Method | What it does |
|---|---|
| `::fromArray($data)` | Hydrate from a scalar-keyed array (type coercion runs) |
| `::fromArrayValidated($data)` | Same + throws `ValidationException` if rules fail |
| `->toArray()` | Serialize to an array — Secrets redact to `'***'`, enums unwrap to scalar, DateTime to ATOM |
| `->with(['field' => $new])` | Return a new instance with selected fields replaced (preserves Secret, DateTime, Enum rich types) |
| `->validate()` | Returns a `ValidationResult` |

Type coercion handles `string → int/float/bool`, ISO strings →
`DateTimeImmutable`, scalar → `BackedEnum`, array → nested
DataObject, string → `Secret`, and — with `#[ListOf]` — arrays into
typed lists.

### Sources (read path)

| Source | Reads from |
|---|---|
| `PostSource` | `WP_Post` system fields + `post_meta` |
| `UserSource` | `WP_User` + `user_meta` |
| `TermSource` | `WP_Term` + `term_meta` |
| `OptionSource` | `wp_options` |
| `RowSource` | Any `$wpdb` row (ARRAY_A or object) |
| `RequestSource` | `WP_REST_Request` with nonce / cap / param-source guards |

All sources are WP-independent at the engine level
(`AttributeDrivenHydrator`) so you can unit-test your DTO hydration
without bootstrapping WordPress.

Add `use HasWpSources;` to a DTO for the convenience shortcuts
`::fromPost`, `::fromPosts`, `::fromUser`, `::fromUsers`, `::fromTerm`,
`::fromRow`, `::fromRows`, `::fromOption`, `::fromRequest`.

### Sinks (write path)

| Sink | Writes to |
|---|---|
| `PostSink` | `wp_insert_post` / `wp_update_post` + meta |
| `UserSink` | `wp_insert_user` / `wp_update_user` + meta (excludes `user_pass`, `user_activation_key`) |
| `TermSink` | `wp_insert_term` / `wp_update_term` + meta |
| `OptionSink` | `update_option` (+ `#[Encrypted]` at-rest support) |
| `RowSink` | `$wpdb->insert` / `$wpdb->update` |

Every sink supports:

- **Projection-first API** (`toArgs` / `toMeta` / `toArray`) — returns
  the payload without touching the DB. Unit-test friendly.
- **Partial writes via `$only: ['field', 'field']`** — only the listed
  properties are written.
- **Strict whitelist via `strict: true`** — typos in `$only` throw
  `UnknownFieldException`.
- **`skipNullDeletes: true`** — PATCH-style update where `null` in
  the DTO leaves existing meta untouched instead of deleting it.
- **`wp_slash()` on convenience methods** — backslashes in payloads
  survive the round-trip.

Add `use HasWpSinks;` for `->saveAsPost`, `->saveAsUser`,
`->saveAsTerm`, `->saveAsOption`, `->saveAsRow`.

### Presenter

Fluent builder that projects a DataObject into a context-specific
output shape.

```php
Presenter::for($product)
    ->context(PresentationContext::rest())
    ->only(['id', 'title', 'price', 'priceFormatted'], strict: true)
    ->hide('cost', when: fn ($ctx) => !$ctx->userCan('manage_options'))
    ->showOnlyFor('wholesale_price', roles: ['wholesale'])
    ->rename('post_title', 'title')
    ->compute('priceFormatted', fn ($p, $ctx) => wc_price($p->price))
    ->formatDate('publishedAt', 'F j, Y')
    ->formatCurrency('price')
    ->includeSensitive(['internalNote'])  // opt-in for Sensitive plain-string fields
    ->toJson();                            // always throws on encode failure
```

Subclass `Presenter` and override `configure()` when logic warrants a
dedicated class; `Presenter::forCollection($dtos)` replays the same
configuration over every item.

`PresentationContext` carries timezone + locale + current user + a
free-form name. Locale flows into `switch_to_locale()` for the built-in
formatters, so `withLocale('hu_HU')` on an `en_US` site produces
Hungarian month names for that render.

### Validation

Attribute-driven built-in validator. Supply your own via
`ValidationEngineInterface` if you already use Symfony Validator,
Respect, or Laravel's.

Built-in rules:

- `Required`, `Email`, `Url`, `Uuid`, `Regex`
- `Min`, `Max`, `MinLength`, `MaxLength`
- `OneOf([...])`
- `Callback` (closure — not usable as an attribute, constructed
  programmatically)

Hydration stays shape-only; validation is an explicit, separate step
(`->validate()` or the throwing `::fromArrayValidated()` shortcut).
Rules short-circuit per field: once `Required` fails, further rules
on the same field are skipped so you don't get
`Required → Email → MinLength` cascades for one empty input.

### Security

Three composable primitives, each addressing a different concern.

| Tool | Concern | Applies to |
|---|---|---|
| `Secret` (type) | In-memory leak prevention for credentials | `public Secret $apiKey` — blocks `__toString`, `json_encode`, `var_dump`, `print_r`, `serialize` |
| `#[Sensitive]` (attribute) | PII default-redaction for plain strings | `#[Sensitive] public string $ipAddress` — Presenter excludes unless `includeSensitive()` opts in |
| `#[Encrypted]` (attribute) | At-rest AES-256-GCM on storage | `#[Encrypted] public Secret $apiKey` — sink encrypts on write, source decrypts on read |

They compose naturally: `#[Encrypted] public Secret $apiKey` =
encrypted on disk + leak-proof in memory. `Secret` alone = in-memory
only (still plaintext on disk). `#[Sensitive]` on a plain string =
presentation guard only.

```php
$key = BetterData\Encryption\EncryptionEngine::generateKey();
// Put in wp-config.php:
define('BETTER_DATA_ENCRYPTION_KEY', $key);
// Optional for rotation:
define('BETTER_DATA_ENCRYPTION_KEY_PREVIOUS', $oldKey);
```

Missing key when an `#[Encrypted]` field is read/written →
`MissingEncryptionKeyException` (loud failure, never silent plaintext
storage). Tampered or corrupt ciphertext →
`DecryptionFailedException` with a generic message (no oracle leak).

See **Security model** below for the full threat model.

### Registration

`MetaKeyRegistry::register(ProductDto::class, 'post', 'product')`
walks every `#[MetaKey]`-annotated constructor parameter and calls
`register_meta()` with shape info (type, single, default, description,
sanitize_callback, auth_callback) derived from the attribute. It does
**not** register post types, taxonomies, or REST routes — those are
app-level decisions.

Two schema projections:

- `toJsonSchema($dto)` — root-object JSON Schema, drops into
  OpenAPI `components/schemas/<Name>`.
- `toRestArgs($dto)` — flat per-field map for
  `register_rest_route(['args' => ...])`. Every entry includes
  `required`, `type`, `description`, and any constraints the rule
  attributes imply (`format: email`, `enum: [...]`, `pattern`, etc.).

---

## Security model

### Threats the library addresses

- **Accidental serialization of secrets.** A Secret typed field
  survives `json_encode`, `var_dump`, `print_r`, exception stack
  traces, DataObject `toArray`, Presenter default output, and
  `serialize()` (which throws rather than redact — a silent lossy
  serialize of a credential is the worst of both outcomes).
- **Plaintext at rest.** `#[Encrypted]` on a meta- or option-backed
  field routes writes through AES-256-GCM before the value reaches
  `update_post_meta` / `update_option`. Reads decrypt transparently.
- **Client-driven id spoofing at REST.** `RequestSource::noCollision`
  blocks body params from overriding route-owned fields (`PUT
  /widgets/{id}` with `{id: 999}` in the body).
- **Missing-auth-callback footguns.** `MetaKeyRegistry` emits
  `_doing_it_wrong` when a `_`-prefixed meta is exposed via
  `showInRest` without `authCapability` (WP defaults protected meta
  auth to `__return_false`, silent 403), and again when `encrypt:
  true` meets `showInRest: true` (WP core's REST read path bypasses
  our decryption).
- **Slash-munging.** All convenience sink methods pass values through
  `wp_slash()`; projection methods leave values raw so you can
  inspect / substitute.

### Threats the library does NOT address

- **Reflection and debuggers.** PHP reflection can always read
  private properties. Extensions like Xdebug can inspect anything.
  `Secret` defends accidental leaks, not determined introspection.
- **Memory dumps / swap files.** PHP strings are immutable; zeroing
  isn't meaningful. Operate at the OS level if this is in scope.
- **Key management.** We read
  `BETTER_DATA_ENCRYPTION_KEY` from wp-config. Distribution, rotation
  schedules, vault integration, HSMs — out of scope.
- **Rate limiting, audit logs, intrusion detection.** Not our layer.

### Key rotation recipe

1. Define the current primary as `_PREVIOUS` while keeping it as
   `PRIMARY`:

   ```php
   // wp-config.php
   define('BETTER_DATA_ENCRYPTION_KEY',          'base64-new-key');
   define('BETTER_DATA_ENCRYPTION_KEY_PREVIOUS', 'base64-old-key');
   ```

2. Deploy. Writes use the new primary; reads try primary then
   previous on failure.

3. Run a one-shot migration: for every DTO that carries
   `#[Encrypted]` fields, hydrate → call `->with([...])` with no
   actual changes → save. The save re-encrypts with the new primary.

4. Once coverage is complete, remove `_PREVIOUS` from wp-config on the
   next deploy.

---

## Philosophy

- **Zero magic by default.** Attributes add metadata when metadata
  earns its keep; plain DTOs work without any. Type coercion is
  deterministic and documented.
- **Explicit security.** No "helpful" side effects around credentials.
  Reveal, encrypt, redact — every path through the type system is
  opt-in at the call site.
- **Loud failures over silent corruption.** Missing encryption keys
  throw, tampered ciphertext throws, strict whitelists throw on
  typos, collision guards throw on route-shadowing attempts.
- **Projection before persistence.** Every sink has a `toArgs` /
  `toMeta` / `toArray` that returns what the sink *would* write.
  Unit-test your wiring without a live WP.
- **Small surface, composable.** We export a handful of attributes, a
  base DataObject, and six sink/source pairs. No abstract factories,
  no service container, no event bus.

---

## Testing

The library itself runs under PHPUnit + PHPStan level 6. Consumers get
two test paths:

- **Unit** — source/sink engines are WP-independent. Hydrate test
  fixtures with a fake meta fetcher; no `WP_Post` required.
- **Integration** — the companion plugin
  [`better-data-plugin-test`][smoke] registers a realistic widget CPT,
  REST routes from `toRestArgs()`, `save_post` hook, and admin pages.
  `wp better-data test` runs the regression smoke;
  `wp better-data stress` runs the deep integration scenarios
  (RestCrudCycle, encryption round-trip, collision guards, etc.).

[smoke]: ../../wp-content/plugins/better-data-plugin-test

---

## Versioning

Semver from v1.0 onwards. Public API surface — `DataObject`,
attributes, sources, sinks, `Presenter`, `MetaKeyRegistry`,
`BetterRouteBridge` — is stable. Breaking changes require a major
bump and migration notes.

---

## License

MIT © Soczó Kristóf

---

## Related

- **`better-route`** — sibling routing library. Use
  `BetterData\Route\BetterRouteBridge` when a route should hydrate,
  validate, document, and present better-data DTOs.

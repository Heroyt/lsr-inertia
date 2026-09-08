# Inertia V3 SSR: Package Support and Research

Updated 2026-09-06. Package support below is released as **0.1.8**, tagged `v0.1.8` at
`2b693ec7651865cf6ca027f32b808872871330ce`, and published through Satis. The later research sections
preserve pre-implementation findings; their old source line numbers and statements about missing
support describe that earlier snapshot. This research note remains local and uncommitted.

## Implemented Package Interface

- `Inertia` and `InertiaFactory` use Symfony `NormalizerInterface`; the configured normalizer runs
  once per page. Native JSON encoding is reused for XHR, SSR transport and client-rendered fallback.
- `Ssr\HttpRenderer` performs one PSR-18 POST with the encoded page (no extra `{page: ...}` wrapper),
  validates status and the `{head: list<string>, body: nonempty-string}` result, and returns
  `Ssr\RenderedPage`. A successful JSON `null` means Vite warmup and selects fallback, even in strict mode.
- SSR is disabled unless a renderer is supplied. Only ordinary GET renders call Node. `X-Inertia`,
  HEAD, POST and explicit request opt-out do not call it.
- Initial HTML and JSON responses vary on `X-Inertia`. Deferred/lazy prop behavior is preserved.
- `inertiaPage` contains normalized page data. `inertiaHead` and `inertiaBody` contain template-ready
  HTML. On fallback, the body is the safe V3 JSON script plus an empty root. On success, it is the
  renderer's entire body. These three names are reserved template data, not component props.
- Renderer transport/status/shape failures use CSR fallback unless strict mode is selected.
  Existing lifecycle hooks receive caught SSR exceptions; hook failures cannot break fallback.
  Normalization, JSON encoding, template and client-programming exceptions are not swallowed.
- No Node processes, concrete HTTP client, retries, renderer-output caching, V2 mode or app build
  system are bundled into this Composer package.

Sources: [`Inertia`](src/Services/Inertia.php), [`InertiaOptions`](src/Services/InertiaOptions.php),
[`HttpRenderer`](src/Ssr/HttpRenderer.php), [`InertiaExtension`](src/DI/InertiaExtension.php).

### Nette DI configuration

With the existing extension registration, no configuration changes are needed to keep SSR disabled.
To enable it, supply an app-owned HTTP client with finite deadlines. Guzzle is an example already
installed in code-hunt-game, **not** a new package dependency:

```neon
extensions:
    inertia: Lsr\Inertia\DI\InertiaExtension

services:
    inertiaSsrClient:
        create: GuzzleHttp\Client([connect_timeout: 0.2, timeout: 1.0])
        autowired: false

inertia:
    rootId: app
    normalizationContext: []
    ssr:
        enabled: true
        client: @inertiaSsrClient
        url: http://ssr:13714/render
        throwOnError: false
```

The deadlines above illustrate configuration, not a measured production recommendation. PSR-18 has
no portable timeout option: enforce connect/total deadlines on the dedicated client. A nested Nette
Statement may also construct that client; with `client: null`, enabled SSR uses normal PSR client
autowiring. Disabled SSR registers no renderer and does not require a concrete client or PSR request
factory beyond the package's existing response/stream factory dependencies.

Use `http://127.0.0.1:13714/render` with a loopback-bound Node process in the same app container;
use service DNS on a private network for a separate Node container. Publish no renderer port.
Development may explicitly select `http://<vite-host>:<vite-port>/__inertia_ssr`; automatic hot-file
discovery is not implemented. Root ID must match the browser and Node entry configuration.

`InertiaOptions` also accepts `normalizationContext`, `jsonEncodeOptions` and `throwOnSsrError`.
Default JSON options preserve Unicode and zero fractions and substitute invalid UTF-8. Encoding
always throws on errors and escapes slashes for script safety; `JSON_FORCE_OBJECT` and
`JSON_PARTIAL_OUTPUT_ON_ERROR` are rejected because they break page representation/error semantics.
Use `ssr.throwOnError: true` in development/acceptance checks to propagate `SsrException`.

### Template cutover

In the document head:

```latte
{$inertiaHead|noescape}
```

At the app mount location:

```latte
{$inertiaBody|noescape}
```

Keep the document shell and client CSS/JS tags. **Replace**, rather than supplement, the old
page-data script and empty root when adopting `inertiaBody`; the body owns both in every mode.
Do not feed the normalized page back through a full normalizing serializer on this new path.
Only these configured, trusted renderer/package outputs should be emitted without escaping.
Avoid duplicate PHP/Vue title/meta ownership. Existing templates using `inertiaPage` still receive
that variable, but do not display SSR until they adopt the new head/body outlets.

For direct PHP construction, `InertiaFactory` takes the existing response/stream/view factories,
then `NormalizerInterface $normalizer`, optional `?HttpRenderer $ssr = null`, and optional
`InertiaOptions $options = new InertiaOptions()`. `Inertia` has the same dependencies with the
request first. The renderer takes a PSR client, request factory, stream factory and endpoint URL.
Normal Symfony `Serializer` objects implement the normalization interface and remain usable.
**Migration:** custom serializers implementing only `SerializerInterface` must supply a normalizer;
named constructor arguments `serializer:` must change to `normalizer:`. No compatibility shim or
silent substitute normalizer is provided. Controller `render()` and factory `fromRequest()` methods
are unchanged.

### Per-request opt-out

An app middleware can disable rendering through the request passed to the Inertia factory:

```php
$request = $request->withAttribute(Inertia::SSR_ENABLED_ATTRIBUTE, false);
return $handler->handle($request);
```

The key is `inertia.ssr.enabled`. It can disable a configured renderer, not enable one when global
SSR is off. Deriving policy from the request avoids stale middleware-instance flags and state leaks
in long-lived RoadRunner workers. Node remains stateless with respect to PHP sessions: it receives
only the selected page props, not forwarded browser cookies or authentication headers.

### Implementation verification and release state

- Full package suite: **66 tests, 170 assertions** passed with `vendor/bin/phpunit --no-coverage`.
- `composer phpstan` and `composer cs` passed. Composer metadata validates with existing missing-license
  and explicit-version warnings; the one new installed dependency is `psr/http-client` 1.0.3, resolved
  from cache with network disabled. Composer's network audit was consequently unavailable.
- Release manifest validation used `composer validate --no-check-publish --no-check-lock`: the ignored
  development lock is stale after the version bump. A lock-only refresh was blocked by the existing
  `roave/security-advisories` conflict with PHPCS 3.13.5; no development-tool upgrades were made.
- An isolated real HTTP smoke surface loaded this working tree, rendered through Latte and Guzzle,
  and called the installed Inertia 3.7/Vue renderer from code-hunt-game. It verified initial text/head,
  date normalization, single prop resolution, deferred metadata, JSON bypass, per-request/global opt-out,
  HEAD bypass, renderer-error fallback and strict exception propagation.
- Browser verification showed one SSR root, hydration without console warnings/errors and a working
  counter click. After stopping Node, the PHP response still supplied the V3 fallback; Vue mounted
  and the same counter worked without console warnings/errors.
- This was a disposable integration surface, not a rollout of code-hunt-game's real pages. No app
  manifests/templates, Docker configuration or Composer path repositories were changed. Container
  supervision and that app's SSR-unsafe components remain app rollout work.
- Implementation, tests and the separate version commit were pushed, with annotated tag `v0.1.8`.
  `./makePackage lsr/inertia` completed its Satis build and FTP upload; comparison with the previous
  metadata confirmed that only `lsr/inertia` changed. Public Composer metadata references the release
  commit above.
- A fresh disposable Composer installation fetched exact version 0.1.8 from `packages.laserliga.cz`.
  A PHP runtime smoke loaded that installed source and verified safe V3 fallback, preserved deferred
  props, single callback resolution and matching JSON normalization. This was not an app lock update.
- `lsr-skills` commit `613cd89e03241a4d0f5222a69cca50a62c4d2144` documents the backend, Vue and
  RoadRunner integration. All 22 skills passed validation/discovery, the documented NEON compiled
  against the package, and the pushed GitHub Actions validation passed.
- Disposable smoke installations were removed. Publisher diagnostics remain in `../ftp_input.txt`
  (credential line sanitized) and `../ftp_output.log`; neither is part of the package release.

## Historical Research

The remainder records the design investigation and source snapshots preceding implementation.
The implemented interface above is authoritative where a proposal below differs (notably the
transport accepts already-encoded normalized JSON so encoding is not duplicated on fallback).

## Summary

**Recommendation:** implement the small PHP-to-renderer HTTP seam and root-template integration
first. Keep JavaScript rendering, hydration, Vite configuration, and process supervision in the
consuming application. Laravel is a useful reference implementation, not a required dependency.
Its Artisan commands, Vite facade, Blade directives, bundle paths, and failure events are Laravel
integration choices rather than mandatory Inertia wire protocol. [protocol] [gateway] [directive]
[start-command] [vite-plugin]

### Confirmed design decisions

- Normalize the resolved page once through Symfony `NormalizerInterface`; JSON encoding is a separate
  transport/template concern. Full Symfony `serialize()` is not required.
- Support **Inertia V3 only**. No V2 mode, bootstrap detection, compatibility flags or fallback format.
- SSR is **disabled by default**. Enabled production rendering retains client-rendered fallback.
- Support **both private Docker topologies**: Node supervised alongside RoadRunner in the app container,
  or a separate app-specific Node container reached over a private network.
- `/Users/heroyt/Projects/code-hunt-game` is the actual RoadRunner/Inertia/Vue consumer for this design.
  Arena/Liga are non-consuming compatibility context, not prerequisites for an SSR pilot.

The most consequential requirements are:

1. Send the **resolved, JSON-normalized initial page object itself** to the renderer, not a wrapper
   such as `{ "page": ... }`. Preserve all page metadata. Use the same normalization semantics for
   browser page responses, SSR and CSR fallback; otherwise hydration can see different data.
   Normalization consistency, not a particular JSON encoder, is the requirement derived from Node
   consuming the supplied page and embedding that page again. [core-server] [vue-app] [ssr-body]
2. A successful result is `{ head: string[], body: string }`. In v3, `body` already contains **both**
   the initial-page JSON script and the populated application root. Output that complete body
   instead of wrapping it in another root or adding another page-data element. [ssr-body] [directive]
3. SSR failure must leave a correct CSR page available. Bound the HTTP call, validate the response,
   and share one request-local result between head and body. The timeout and response validation
   policy are LSR design choices; upstream does not supply a portable PHP client abstraction.
   [gateway] [ssr-state] [service-provider]
4. Support V3 exclusively: use the JSON script and hydration marker. Current V3 does not read the
   old `div[data-page]` attribute through `getInitialPageFromDOM`; do not implement a legacy mode.
   [dom-utils]
5. Keep the renderer private. Its default bind is `0.0.0.0:13714`, and its `/shutdown` route exits
   the process without authentication or an HTTP-method restriction. [core-server]

## Local Package Integration

This is a proposal grounded in the checked-out `lsr/inertia` 0.1.7 source, not an implemented feature.
The existing untracked [implementation plan](INERTIA_V3_IMPLEMENTATION_PLAN.md), lines 404–426, already
calls for separate SSR planning and was preserved.

### Exact insertion points

| Existing surface | Verified behavior | Proposed change |
| --- | --- | --- |
| [`Inertia::render()`](src/Services/Inertia.php), lines 88–138 | Resolves props, constructs page metadata, returns JSON early for `X-Inertia`; otherwise passes `inertiaPage` to a view factory. | Normalize the resolved page once for every representation; dispatch SSR only in the HTML branch before the outer template. Preserve controller call sites and observable JSON protocol behavior. |
| [`PropResolver`](src/Resolver/PropResolver.php), lines 29–105 and 142–164 | Initial visits omit lazy props and collect deferred metadata without evaluating deferred callbacks. Closures in arrays resolve recursively; other objects remain for serialization. | Reuse once. Never force lazy/deferred props or send raw template parameters to Node. Indexable content must be regular initial props. |
| [`InertiaFactory`](src/Factory/InertiaFactory.php), lines 16–33 | Creates a new `Inertia` instance for each request. | Inject optional immutable renderer configuration/dependencies; retain result and disable state on the request-local object, not shared DI singletons. |
| [`InertiaExtension`](src/DI/InertiaExtension.php), lines 17–33 | Registers factory and middleware, without SSR configuration. | Add opt-in configuration and conditional renderer registration. Disabled apps need neither Node nor a concrete HTTP client. |
| [`ViewFactoryInterface`](../lsr-interfaces/src/Interfaces/ViewFactoryInterface.php), lines 8–36 | Accepts an array or `TemplateParametersInterface` for template rendering. | No interface expansion needed: supply optional SSR template data while preserving `inertiaPage` and template selection. |
| [`InertiaMiddleware`](src/Middleware/InertiaMiddleware.php), lines 24–43 and 87–96 | Creates request-local Inertia; adds `Vary: X-Inertia` only on Inertia requests. | Do not dispatch Node here: the resolved page belongs to `render()`. Initial HTML also needs `Vary: X-Inertia` where caches store both representations; this is a pre-existing protocol gap, not a reason to SSR JSON requests. |
| [`InertiaLifecycleScopeInterface`](src/Lifecycle/InertiaLifecycleScopeInterface.php), lines 9–13 | Records exceptions and final prop counts. | Preserve its interface and hook isolation. A caught SSR failure needs a diagnostic without exposing private props; successful CSR fallback does not prove successful SSR. |

### Recommended module interface

Use one optional HTTP renderer accepting the **normalized page representation** and producing a typed
result (`head: list<string>`, `body: string`) or an explicit failure outcome. Encode at the HTTP seam,
not by serializing objects a second time. Use PSR-18 for transport rather than hypothetical drivers.

- Inject `Psr\Http\Client\ClientInterface` and PSR-17 request/stream factories. Declare newly used
  PSR contracts directly in Composer, but do not force a concrete Guzzle/Symfony client, Laravel,
  or a JS framework on all consumers. Guzzle is already used by the
  [arena app](../../../Apps/LaserArenaControl/composer.json), line 77, and
  [`lsr/otel`](../lsr-otel/composer.json), line 48; it is not currently an Inertia dependency.
- **Timeout ownership must be explicit:** PSR-18 has no timeout options. Configure finite
  connect/total deadlines on a dedicated concrete client in app/DI wiring. Do not advertise an
  SSR timeout setting that an arbitrary PSR-18 client cannot enforce. Inspect response statuses:
  PSR-18 returns valid 4xx/5xx responses instead of throwing
  ([PSR-18](https://www.php-fig.org/psr/psr-18/)).
- Proposed minimum configuration: `enabled` (default false), trusted full render endpoint, and
  development/test failure policy. A full endpoint accommodates production `/render` and optional
  Vite `/__inertia_ssr` without Laravel discovery assumptions. Root ID must agree across PHP,
  browser entry and server entry; it is not a PHP-only setting.
- Preserve controller `render()` and factory `fromRequest()` methods. The page pipeline should depend
  on `NormalizerInterface`, not require full `SerializerInterface`. A Symfony `Serializer` instance
  may still provide that interface; no second normalizer chain is needed. Before implementation,
  inspect and migrate direct constructor callers, custom factories and app overrides. Replacing an
  interface requirement is not automatically compatible with arbitrary custom serializers.
  DI should require concrete SSR dependencies only when enabled.
- Resolve and normalize once; perform at most one render call per initial response. Pass the same
  result to head and body. Encode only where needed, reusing JSON where appropriate; never
  serialize then decode merely to obtain normalized data. No synchronous retries or per-page health checks.
- Keep fallback scoped to the SSR operation: transport failure, renderer failure, bad status,
  invalid JSON/result shape. Do not swallow ordinary prop-resolution, serialization or template
  exceptions as though they were harmless renderer outages.
- Do not add output caching initially. A component/URL-only cache is unsafe for personalized props.
  Never store a user's result or per-request disable flag in static or long-lived singleton state.

### Template and normalization ownership

The shared value is the normalized page, available to both HTTP output and the view. The SSR result
is separate optional template data. Existing `inertiaPage` remains the page variable; a name such as
`inertiaSsr` is a proposed addition. An already-encoded JSON view value is optional, not the primary
module interface. Disabled SSR still uses the correct V3 client-rendered shell.

Conceptually:

```php
$page = $normalizer->normalize($resolvedPage, 'json', $normalizationContext);
$json = json_encode($page, JSON_THROW_ON_ERROR);
```

Use the app's actual normalizer chain and the same format/context for all representations. Preserve
configured groups, date formats, names and explicit empty-object representation (`ArrayObject` may
be used by Symfony for `{}`). Do not cast everything to arrays, force every empty list into an object,
or accidentally normalize twice. The snippet shows a minimal encoding policy, not a replacement
for consumer-specific JSON flags: preserve intentional Unicode, number and invalid-UTF-8 behavior.
For HTML script embedding, do not carry `JSON_UNESCAPED_SLASHES` through unchanged. Encoding errors
must not be hidden as renderer outages. See [Symfony normalization](https://symfony.com/doc/current/serializer.html) and
[`NormalizerInterface`](vendor/symfony/serializer/Normalizer/NormalizerInterface.php), lines 24–39.

PHP retains the document shell and client asset tags. On success it outputs the trusted renderer's
head and complete body unchanged. On fallback it emits one empty root and one safely embedded page
payload in the V3 JSON-script format. Do not wrap the returned body in another root, duplicate
the page script, or HTML-entity encode JSON inside that script. Script and attribute escaping are
different contexts. Only a configured trusted renderer may supply raw HTML. [protocol] [ssr-body]

### Executed serialization and prop-resolution probe

Ran a throwaway PHP program through stdin against the package's `vendor/autoload.php`, actual
`Inertia::render()`/`PropResolver`, Nyholm PSR-17 factories, and Symfony `Serializer` configured with
`DateTimeNormalizer`. A recording `ViewFactoryInterface` captured template data; it was not a real
app template or Node renderer. PHP 8.5.10; exit 0. No source or test files changed.

```text
Initial HTML content type: text/html; charset=UTF-8
Initial callback executions: normal=1, lazy=0, deferred=0
Initial prop keys: name, created
Deferred metadata: {"default":["later"]}
HTML inertiaPage created value type: DateTimeImmutable
plain json_encode(created): {"date":"2026-09-06 12:00:00.000000","timezone_type":1,"timezone":"+00:00"}
Symfony serialized created: "2026-09-06T12:00:00+00:00"
XHR page equals Symfony-serialized initial page: true
View calls across one HTML and one XHR render: 1
```

**Conclusion:** a canonical normalized representation is required; full Symfony serialization is not.
The probe also confirms that initial SSR can reuse current lazy/deferred semantics and the early JSON return.
It does **not** verify HTTP rendering, browser hydration, deployment readiness or existing template
safety. The local autoloaded source is the working tree; installed root-package metadata is not
evidence of a published 0.1.7 release.

### Executed normalization-only probe

A second throwaway PHP stdin probe used a Symfony `Serializer` as `NormalizerInterface` with
`DateTimeNormalizer` and `BackedEnumNormalizer`, **zero encoders**, `normalize(..., 'json', context)`,
then native `json_encode(..., JSON_THROW_ON_ERROR)`. Exit 0:

```text
Symfony encoders configured: 0
DateTimeImmutable: "2026-09-06T12:00:00+00:00"
Backed enum: "ready"
Explicit empty errors object preserved as {}: true
Closing-script text escaped in embedded JSON: true
Original text round-trips: true
```

The probe used `PRESERVE_EMPTY_OBJECTS` to preserve an explicit empty `ArrayObject`, not to convert
all empty arrays. It proves the proposed split works without Symfony's encoding layer. It does not
prove the consumer's complete normalizer configuration or browser hydration.

## Workspace Consumers and Rollout

### Actual consumer: code-hunt-game

The user identified `/Users/heroyt/Projects/code-hunt-game`; it is outside the original Laser Arena
workspace search. It is the primary integration reference, **not a hypothetical new app**. No files
in that app were edited. Its existing
[SSR feasibility research](../../../../code-hunt-game/docs/research/seo-geo-ssr.md) is related evidence,
not proof of an implemented renderer. That note discusses an app-owned view-factory adapter when
upstream support is unavailable; with the selected package work, prefer the package's HTML seam
rather than building both integrations.

| Surface | Current evidence | Required integration |
| --- | --- | --- |
| Dependencies | [composer.json](../../../../code-hunt-game/composer.json), line 58 requires `lsr/inertia ^0.1.1`; `composer.lock` pins 0.1.7. [pnpm-lock.yaml](../../../../code-hunt-game/pnpm-lock.yaml) locks `@inertiajs/vue3` 3.7.0, Vue/server-renderer 3.5.42 and Vite 8.2.2. | Use these actual V3 packages for the pilot; no Inertia/Vue migration needed. |
| Browser bootstrap | [app.ts](../../../../code-hunt-game/assets/js/app.ts), lines 6–18 uses lazy page imports and a custom `setup()` that always calls `createApp`, installs copy helpers/gettext, then mounts. | Use hydration for `data-server-rendered` roots and normal mounting on fallback, or V3 automatic setup with shared `withApp` configuration. Match plugins and layout resolution on server and client. |
| SSR build | [package.json](../../../../code-hunt-game/package.json), line 15 already has `vite build && vite build --ssr`; [vite.config.ts](../../../../code-hunt-game/vite.config.ts), lines 57–65 has client output `build` and no SSR entry. Nette entry names `app.js`, whereas source/shell use `app.ts`. | Add an explicit server entry and separate server output so SSR build cannot erase client artifacts; reconcile the entry mismatch. The script's existence is not SSR support. |
| Root and head | [index.latte](../../../../code-hunt-game/templates/pages/index.latte), lines 3–7 already emits the V3 JSON script and empty `#app`. [@layout.latte](../../../../code-hunt-game/templates/@layout.latte), lines 23–37 owns title/description/OG and client assets. | Add one SSR body outlet and a head outlet without duplicate roots/payloads. Preserve PHP-owned metadata for current pages; do not introduce duplicate Vue titles. The package must still support renderer `<Head>` output for apps/pages that use it. |
| Middleware | [inertia.neon](../../../../code-hunt-game/config/di/extensions/inertia.neon) replaces the stock middleware. [App InertiaMiddleware](../../../../code-hunt-game/src/Http/Middleware/InertiaMiddleware.php), lines 25–55 catches `DispatchBreakException`, normalizes its response and rethrows. | Preserve this exception/redirect/version handling; replacing the app override blindly is not an SSR migration. |
| Request lifetime | Installed [WithInertia](../../../../code-hunt-game/vendor/lsr/inertia/src/Http/WithInertia.php), lines 75–76 calls `fromRequest()` again instead of reusing the middleware's instance. | Derive eligibility/version consistently for every factory construction or establish explicit request reuse. Setting only the middleware instance's SSR flag would not reach the controller render. Never use process-global mutable state under RoadRunner. |

#### The app already has a serializer-backed Latte JSON filter

[LacExtension::filterJson()](../../../../code-hunt-game/src/Latte/LacExtension.php), lines 38–44 calls
the injected Symfony serializer, then escapes closing-script/HTML-sensitive sequences. Therefore
the earlier package-level raw-object probe is **not evidence of an existing date serialization bug
in code-hunt-game**. The package passes raw data, but this app normalizes through its template filter.

The proposed improvement is to normalize **once before choosing the representation**, reuse the
normalized page for XHR/SSR/template output, and avoid invoking the full normalizing serializer again
in the Inertia shell. Use an encoding-only shell path; do not globally change a `json` filter also
used by unrelated templates. Existing XML/CSV functions need no migration for SSR.

[serializer.neon](../../../../code-hunt-game/config/di/extensions/serializer.neon), lines 2–10 configures
the app's normalizer chain. Installed
[SerializerExtensions](../../../../code-hunt-game/vendor/lsr/serializer/src/DI/SerializerExtensions.php),
lines 172–178 registers the actual Symfony `Serializer` class, which also supplies `NormalizerInterface`;
reuse it rather than constructing a parallel chain. Its default encoder options at lines 107–113
include Unicode/unescaped slashes, zero-fraction preservation, exceptions and invalid-UTF-8 substitution.
Native encoding must preserve intentional output semantics, while the V3 script path must escape
slashes. Actual container compilation with the changed dependency remains an implementation check.

#### Concrete frontend work before rendering a real page

- [productCopyHtml.ts](../../../../code-hunt-game/assets/js/lib/productCopyHtml.ts), lines 19–23
  calls `DOMPurify(window)` and `document.createElement` during sanitization. The copy helper is used
  by the public logo/layout, so public SSR cannot simply reuse this path in Node. Provide an
  SSR-capable sanitizer with equivalent policy/output, or move trusted copy sanitization to its
  build/server data preparation seam. Do not bypass sanitization or defer essential public copy
  to `onMounted()` merely to conceal the problem.
- [productCopy.ts](../../../../code-hunt-game/assets/js/productCopy.ts), lines 10–17 exports a singleton
  gettext instance configured for Czech. No cross-locale leak is demonstrated with the current
  fixed locale, but request-varying locale/plugins must not mutate a shared Node singleton.
  Shared helper configuration must use the corresponding render-local instance.
- [FlashToasts.vue](../../../../code-hunt-game/assets/js/components/FlashToasts.vue) has an immediate
  watcher path using `window.setTimeout` for nonempty flash messages. Client-only toast effects
  must not run during server rendering.
- Keep component resolution lazy; an eager glob of every page would evaluate browser-only Leaflet
  imports from admin/game maps even when rendering a public page. This is not a requirement to
  restrict the package to public pages; broaden app coverage after its actual page graph is SSR-safe.
- Start validation with `Index` and its `PublicLayout`; preserve request-sensitive navigation props.
  Do not assume public routes are cache-neutral. Portal/Teleport behavior and generated IDs still
  need browser hydration verification.

#### Bundled Node can reuse RoadRunner's existing supervisor

[.rr.yaml](../../../../code-hunt-game/.rr.yaml), lines 41–46 already uses the **Service plugin** for
the scheduler. Prefer adding an app-owned Node service there over introducing another supervisor:
one process, no periodic execution timeout, restart on exit, bounded shutdown and identifiable logs.
RoadRunner documents arbitrary binaries, automatic start/stop with RoadRunner, restart configuration
and `SIGINT` on service termination. Verify the installed runtime's restart/shutdown behavior and
renderer readiness; process start order alone does not prove the HTTP listener is ready.
Source: [RoadRunner Service plugin](https://docs.roadrunner.dev/docs/plugins/service).

[docker/Dockerfile](../../../../code-hunt-game/docker/Dockerfile), lines 76–79 currently builds only
client assets. Lines 87–108 copy Node into **development**, while production at lines 110–151 copies
PHP dependencies and `/app/build`, not the Node runtime or a renderer artifact. For bundled Node,
add runtime-compatible Node libraries plus server bundle/runtime dependencies to the production
image. For split Node, build a separate renderer image from the same revision. Neither layout needs
Nginx to expose the renderer; the existing public Vite development proxy is not an SSR production port.

#### Executed installed-adapter and app-helper probes

Using Node stdin in the real app directory, called its installed `createInertiaApp`,
`createSSRApp`, `Head` and `renderToString` with an **in-memory probe component**, not an app page:

```text
Head contains probe title: true
Body contains <h1>Hello SSR</h1>: true
Body contains V3 page-data JSON script: true
Body contains data-server-rendered marker: true
Root count: 1
Exit: 0
```

Separately imported the actual app `sanitizeProductCopyHtml()` TypeScript module in Node and invoked
it with benign HTML. It threw `ReferenceError: window is not defined`, as expected by the probe;
the probe exited 0 after checking that result. This confirms the helper's current server-runtime
incompatibility. Neither probe builds/renders `Index`, starts HTTP SSR, verifies Docker lifecycle,
or proves browser hydration. No source or test files were added.


### Arena and Liga: non-consuming compatibility context

Neither primary app currently declares or installs `lsr/inertia`; neither frontend manifest declares
Vue or `@inertiajs/*`. Their current surfaces are Latte HTML with TypeScript DOM enhancements, not
Inertia SPAs. SSR support alone will not convert them.

- Arena: [Composer manifest](../../../Apps/LaserArenaControl/composer.json), lines 53–65;
  [frontend manifest](../../../Apps/LaserArenaControl/package.json), lines 16–57;
  [JS entry](../../../Apps/LaserArenaControl/assets/js/main.ts) and
  [esbuild configuration](../../../Apps/LaserArenaControl/esbuild.mjs).
- Liga: [Composer manifest](../../../Apps/LaserLiga/composer.json), lines 36–62;
  [frontend manifest](../../../Apps/LaserLiga/package.json), lines 11–53;
  [`Index::show()`](../../../Apps/LaserLiga/src/Controllers/Index.php), lines 23–39 returns a
  conventional view; [JS entry](../../../Apps/LaserLiga/assets/js/main.ts) and
  [esbuild configuration](../../../Apps/LaserLiga/esbuild.mjs).
- [`lsr/otel`](../lsr-otel/composer.json), lines 54–64 only suggests Inertia.
  [`OtelExtension`](../lsr-otel/src/DI/OtelExtension.php), lines 638–655 conditionally registers
  its lifecycle hook. This is optional instrumentation, not an app SSR consumer.

The initial workspace investigation found no Inertia mount/page markers in Arena/Liga templates and
no Inertia extension registration in their `services-common.neon`. Any future adoption there needs a
dedicated shell/bootstrap/middleware; this is not a prerequisite for the existing code-hunt-game pilot.

### Executed installed-dependency and routing probes

Loaded each app's actual Composer autoloader and queried `Composer\InstalledVersions` through PHP
CLI. Both invocations exited 0:

| Installed package | LaserArenaControl | LaserLiga |
| --- | --- | --- |
| `lsr/core` | 0.3.34 | 0.3.29 |
| `lsr/interfaces` | 0.3.19 | 0.3.15 |
| `lsr/request` | 0.3.9 | 0.3.5 |
| `lsr/inertia` | not installed | not installed |

`lsr/inertia` 0.1.7 requires `lsr/interfaces ^0.3.16` ([manifest](composer.json), lines 46–50).
Liga's installed interface package is too old; Arena meets this particular constraint. This is not a
full Composer solver check.

Arena's installed [RequestExtension](../../../Apps/LaserArenaControl/vendor/lsr/request/src/DI/RequestExtension.php),
lines 11–19 and 51–69 registers PSR request/response/stream factories. Liga's installed
[RequestExtension](../../../Apps/LaserLiga/vendor/lsr/request/src/DI/RequestExtension.php), lines 8–14
and 38–51 uses legacy LSR request/response factory interfaces and registers no stream factory in that
extension. The package's `InertiaFactory` requires PSR response/stream factories. Liga must provide
compatible factories or independently upgrade that integration before relying on autowiring.
A real app container compilation remains unverified.

A separate PHP reflection probe inspected the first parameter of both routing methods; both app
invocations exited 0:

```text
LaserArenaControl:
  Route::middleware      Psr\Http\Server\MiddlewareInterface
  RouteGroup::middleware Psr\Http\Server\MiddlewareInterface
LaserLiga:
  Route::middleware      Lsr\Core\Routing\Middleware
  RouteGroup::middleware Lsr\Core\Routing\Middleware
```

The package's `InertiaMiddleware` implements PSR middleware only. Liga's local interface extends PSR,
not vice versa. **Arena's installed signatures accept the package middleware; Liga's do not accept
it directly.** For Liga, independently upgrade routing or use an app-owned adapter implementing the
legacy interface and delegating `process()`. Do not add an `lsr/core` dependency to the framework-neutral
Inertia package solely for this consumer. Reflection proves signatures, not full route execution.
Sources: [Arena Route](../../../Apps/LaserArenaControl/vendor/lsr/routing/src/Route.php), lines 17 and
97–101; [Liga RouteGroup](../../../Apps/LaserLiga/vendor/lsr/routing/src/RouteGroup.php), lines 82–99;
[Liga Middleware](../../../Apps/LaserLiga/vendor/lsr/routing/src/Middleware.php), lines 9–11.
These vendor links describe installed consumers, not files to edit.

### Independent deployment

Required topology-neutral contract: **PHP → private app-specific Node renderer → `{head, body}` → PHP shell**.
Support both Docker layouts through the same configured endpoint; the package never starts Node.

| Supported layout | PHP endpoint example | Renderer bind | Lifecycle responsibility |
| --- | --- | --- | --- |
| Node bundled into the app container | `http://127.0.0.1:13714/render` | `127.0.0.1` | App image includes Node/runtime dependencies and SSR bundle; supervisor manages both RoadRunner and Node. |
| Separate app-specific Node container | `http://ssr:13714/render` | `0.0.0.0` inside its private container | Node is its container's foreground process; deployment manages restarts and the private app-to-renderer network. |

For the bundled layout, use explicit supervision with signal forwarding, child reaping, stdout/stderr
logging and Node restart handling. A bare `node ... &` followed by `exec rr serve` is not adequate
supervision. Node restarts should not unnecessarily restart healthy PHP workers; CSR fallback can
serve during renderer recovery. Installing Node in a build stage alone does not supply runtime Node.

For the split layout, use service DNS rather than container IPs. Do not publish renderer ports or
route them through the public ingress. Attach only the app and renderer to a dedicated network;
`internal: true` is appropriate when Node needs no outbound access. `expose` is not an access-control
rule. The PHP container must not require the SSR bundle to exist on its filesystem.

Both layouts must ship compatible browser/server bundles, observe renderer readiness and verify a
real page after deployment. Readiness is operational evidence, not a condition that defeats
client-rendered fallback. Choose per deployment, not through two PHP renderer implementations.
Sources: [Docker multi-process containers](https://docs.docker.com/engine/containers/multi-service_container/)
and [Compose networking](https://docs.docker.com/compose/how-tos/networking/).

Arena runs RoadRunner ([`.rr.yaml`](../../../Apps/LaserArenaControl/.rr.yaml), lines 4–8 and 104–137);
Liga uses PHP-FPM ([Dockerfile](../../../Apps/LaserLiga/docker/Dockerfile) and
[`start.sh`](../../../Apps/LaserLiga/start.sh)). Neither inspected startup flow launches an Inertia
renderer. Build-time Node tooling is not an SSR runtime.

Each consuming app owns its JS lockfile, component resolver, browser/SSR bundles, plugins, locale
and per-request state. Build and deploy both bundles from the same revision; restart the renderer
with that app release. Do not share one renderer/bundle between separately deployed apps. PHP asset
versioning does not detect a healthy but stale renderer. For private containers, loopback inside one
container is not reachable from another; use private networking without a public renderer port.

### Implementation sequence and acceptance

1. **Pilot in code-hunt-game.** Use its existing RoadRunner + Inertia V3 + Vue surface; select a public
   page before widening SSR coverage. Neither Arena nor Liga needs migration for this pilot.
2. **Add package HTML integration.** Canonical normalization, optional HTTP rendering, typed output
   validation, safe template data and request-local result/failure policy. Preserve controller
   behavior, prop semantics and SSR-disabled mode; migrate normalization dependencies explicitly.
3. **Add app rendering and hydration.** Use the official client adapter and framework server renderer,
   identical resolver/plugins/layout on each side, and correct fallback markup. Vite is an app build
   choice, not a required rewrite of both existing esbuild pipelines.
4. **Exercise a real renderer and browser.** Verify page text and `<Head>` in initial HTML; hydrate
   without warnings; exercise an event and navigation. Stop Node and verify correct CSR fallback
   within the configured deadline; strict mode must expose failure. Health alone is insufficient.
5. **Defend plausible regressions.** No Node call for Inertia JSON/partial/deferred/prefetch requests;
   one render/prop evaluation per HTML response; correct deferred metadata; 500/timeout/malformed
   response fallback; matching DTO/date serialization; safe `</script>` props; one root/payload;
   custom root/head behavior; no user-state or disable-flag leakage across sequential worker requests.
   These are future acceptance criteria, not tests performed by this research.
6. **Release independently.** When implementation is requested, validate via a temporary Composer
   path repository in the chosen consumer, follow the package implementation/version/tag/Satis
   workflow, then verify the published dependency. This research does not authorize installation,
   publication or lockfile changes.

The remaining app decisions are pilot-page coverage, CSP handling and latency budget; V3-only support,
normalization-first design, the consumer and both supported Docker layouts are settled.
Benchmark initial response latency and visible-content
timing before claiming a performance improvement: SSR adds a PHP→Node round-trip and render work even
when it improves time-to-content. Public indexable pages are stronger candidates than internal
dashboards ([Vue SSR trade-offs](https://vuejs.org/guide/scaling-up/ssr.html#why-ssr)).

## Evidence and Version Boundaries

Facts below are verified from primary documentation and source inspection unless labeled
**Proposal** or **Unverified**. Wire examples are derived illustrations, not captured runtime output.

| Source | Inspected revision | Release boundary |
| --- | --- | --- |
| `inertiajs/inertia`, `3.x` | `32174e7fbfa48e6ac4348c6557e1b8907d5500b2` | Package manifests report `3.7.0`; branch is four commits ahead of tag `v3.7.0` (`721eef6464ac53c84b52da2f2898d048eea1efcb`). The compared changes include Vite dev CSS collection; do not attribute that exact CSS behavior to the release without checking it. |
| `inertiajs/inertia-laravel`, `3.x` | `ad280df0c4766a9a752b14b680b0e503da47c9d3` | One commit ahead of tag `v3.3.3` (`42dccee77d7df6b2e152965434a89cafc35a2961`); comparison changes only `CHANGELOG.md`. |
| Inertia documentation | Explicit `/docs/v3/` pages | The old `/server-side-rendering` URL redirects to v3; it is not evidence for v2. |
| Vue and Vite documentation | Live official pages on research date | Toolchain requirements must additionally be checked against the consumer's lockfile. |

Sources: [core-package] [vite-package] [core-release-compare] [adapter-release-compare]. The local
package manifest is `lsr/inertia` `0.1.7`, PHP `>=8.4`, and currently declares no HTTP client dependency
([composer.json](composer.json), lines 2–4 and 46–50). The existing sibling
`INERTIA_V3_IMPLEMENTATION_PLAN.md` supplied the package-local heading/bullet convention and was not
changed; its historical implementation claims are not used as proof of current upstream behavior.

## Exact Production Renderer Contract

### Request and successful result

The stock server parses the entire request body as JSON and passes it directly to the application
render callback. Laravel sends `Http::post($url, $page)`. The PHP transport should therefore send
`Content-Type: application/json` and the serialized Inertia page object, without a second envelope.
There is no renderer-specific authentication header or protocol-version negotiation in this
implementation. `page.version` is the application's asset version, not an SSR API version.
[core-server] [gateway] [protocol]

Illustrative request:

```http
POST /render HTTP/1.1
Host: 127.0.0.1:13714
Content-Type: application/json

{"component":"Home","props":{"errors":{},"message":"Hello"},"url":"/","version":"build-42"}
```

Illustrative successful response, assuming that `Home` renders the shown heading and title:

```http
HTTP/1.1 200 OK
Content-Type: application/json
Server: Inertia.js SSR

{"head":["<title data-inertia=\"\">Home</title>"],"body":"<script data-page=\"app\" type=\"application/json\">{\"component\":\"Home\",\"props\":{\"errors\":{},\"message\":\"Hello\"},\"url\":\"\\/\",\"version\":\"build-42\"}</script><div data-server-rendered=\"true\" id=\"app\"><h1>Hello</h1></div>"}
```

After decoding the response JSON, `body` is HTML, not another JSON document. `head` is an ordered
array of HTML strings; Laravel joins it with newline characters. Neither field is a full HTML
layout. The application still supplies `<!doctype html>`, `<html>`, `<head>`, `<body>`, client asset
tags, and other permanent shell content. The stock contract is buffered JSON, not streamed HTML.
[core-server] [gateway] [directive] [ssr-body]

### Initial-page embedding and head integration

The v3 helper is exactly equivalent to:

```ts
const json = JSON.stringify(page).replace(/\//g, '\\/')
const body = `<script data-page="${id}" type="application/json">${json}</script>`
  + `<div data-server-rendered="true" id="${id}">${html}</div>`
```

The browser reads `script[data-page="<id>"][type="application/json"]` and parses its `textContent`.
The default root ID is `app`; configure the same trusted ID in the browser entry, renderer entry,
and fallback shell if changing it. It is not supplied as a separate field of the standard renderer
HTTP request. [ssr-body] [dom-utils] [vue-app]

The v3 CSR fallback is the matching JSON script followed by an **empty** root without the SSR marker:

```html
<script data-page="app" type="application/json">{"component":"Home","props":{"errors":{},"message":"Hello"},"url":"\/","version":"build-42"}</script>
<div id="app"></div>
```

The official protocol requires every `/` to be escaped in embedded JSON, preventing `</script>` in
props from terminating the element. Do **not** HTML-entity-encode JSON inside the script: script raw
text does not decode entities, so JSON parsing would break. JSON used for HTTP transport and JSON
embedded in HTML have different escaping contexts, but must represent the same normalized values.
[protocol] [ssr-body] [directive]

`<Head>` output uses `data-inertia` markers, which the client head manager uses to reconcile managed
tags. Preserve the returned markup and markers. Keep permanent shell tags separate; avoid duplicating
page titles/meta tags in PHP and Vue. The newer `serverHead` option can include server-supplied head
strings from props or a resolver, but is not necessary for basic SSR. [vue-head] [core-head]

Laravel's head and body directives both invoke `SsrState::dispatch()`. It records that dispatch has
happened **even when the result is null**, and the service is registered with request-scoped lifetime.
Thus head-first/body-second template evaluation does not cause two renders or two failing network
calls. **Proposal:** preserve that invariant in LSR, especially for persistent PHP workers; never
cache a user's SSR result or disable flag in process-global mutable state. [directive] [ssr-state]
[service-provider]

### Renderer endpoints and runtime

| Endpoint / setting | Verified stock v3 behavior | Integration consequence |
| --- | --- | --- |
| `/render` | Parses JSON page, awaits callback; success is HTTP 200 JSON. | Use a dedicated private configured URL. |
| `/health` | Returns `{ "status": "OK", "timestamp": <milliseconds> }`. | Useful liveness check, but does not prove any real component can render. |
| `/shutdown` | Exits process; in cluster mode informs primary to stop workers. | Administrative private endpoint, not an application route. |
| Unknown path | Returns `{ "status": "NOT_FOUND", "timestamp": ... }` with HTTP **200**. | A 2xx-only check is insufficient for render success. |
| Routing | Exact `request.url` lookup; no method filtering in production server. | Use exact paths without query strings; protect the entire listener, not just POST. |
| Defaults | Port `13714`, host `0.0.0.0`, clustering `false`, formatted errors `true`. | Choose loopback for same-host deployment or a private container network. |
| Cluster | Forks `availableParallelism()` workers when enabled. | Optional capacity choice, not an LSR protocol requirement. |

Source: [core-server]. Official v3 SSR docs require Node.js **22 or higher**. The current Inertia Vite
plugin peers on Vite `^7.0.0 || ^8.0.0`; current Vite docs require Node `20.19+` or `22.12+`. A deployment
using Node 22 for both therefore needs **22.12+**, not merely 22.0. The Vue adapter peers on Vue
`^3.0.0`; the actual Vue runtime/server-renderer versions must remain aligned through the app's
package lock. Laravel also documents Bun as an alternate runtime; this research has not exercised
Bun compatibility. [ssr-docs] [vite-package] [vite-getting-started] [core-package]

## Failure, Timeout, and Fallback Semantics

### Upstream behavior

- With default formatted errors, production render-callback errors return HTTP **500**, a classified
  JSON error, and console diagnostics. Fields include `error`, `type`, `hint`, `timestamp`, and
  optionally `component`, `url`, `browserApi`, `stack`, and `sourceLocation`. Types include
  `browser-api`, `component-resolution`, and `render`; the type definition also allows `unknown`.
  The dev plugin uses the same classification and fixes Vite stack traces. [core-server] [ssr-errors]
  [vite-ssr]
- Production JSON parsing occurs **before** the render-error `try` block. With `formatErrors: false`,
  render errors are rethrown. The HTTP request handler has no enclosing catch in this revision.
  **Inference:** callers cannot rely on a clean JSON 500 for every failure; malformed requests and
  unexpected failures can end differently depending on Node/runtime behavior. Treat broken/empty
  responses and connection failures as renderer failures. [core-server]
- Laravel returns no SSR result when disabled, when a required local bundle is missing, when response
  JSON is falsey, or after an ordinary transport exception. HTTP 4xx/5xx and connection exceptions
  trigger `SsrRenderFailed`; `throw_on_error=true` converts those failures into `SsrException`.
  This strict mode is recommended in upstream docs for tests, not production. [gateway] [ssr-docs]
- Laravel's parser is permissive: absent `head` becomes `[]`, absent `body` becomes `''`; it is not a
  reference for strict response-schema validation. A syntactically valid unknown-route response
  could become an empty successful SSR response rather than the intended CSR fallback. The dev
  plugin checks that `body` is a string but does not comprehensively validate all head elements.
  [gateway] [vite-ssr] [core-server]
- The gateway contains **no SSR-specific timeout or retry configuration**. It calls `Http::post`
  directly. The inspected Laravel 13.x HTTP client's defaults are a 10-second connect timeout,
  30-second request timeout, and one attempt; these are framework defaults, can be customized by
  an application, and are not an Inertia wire guarantee. The Node server has no application render
  deadline/cancellation protocol. Laravel's `StartSsr` process timeout is disabled because it runs a
  daemon; that is unrelated to HTTP rendering timeout. [gateway] [laravel-http] [start-command]

### Proposed LSR policy, not upstream requirements

- SSR is opt-in and only attempted for the final initial-HTML page path. Existing Inertia JSON
  navigation, partial reloads, deferred prop loading, redirect/version-control responses, and
  response statuses remain the backend's normal protocol handling. Do not render XHR payloads to
  HTML or eagerly resolve deferred/lazy props merely to make SSR appear more complete. [protocol]
- Resolve and normalize the page once through the app's `NormalizerInterface` chain with JSON format
  and matching context. Use the normalized representation for XHR, renderer transport and fallback.
  Native `json_encode` is suitable after normalization; never bypass it for raw DTOs or dates.
- Use a bounded, configurable connect/total timeout appropriate to the app's initial-page latency
  target; no automatic retries in the request path. Choosing concrete durations requires an app
  latency/deployment decision, not copying Laravel's 30-second default.
- Accept only a valid successful JSON object with `head` as an array of strings and `body` as a
  nonempty string. Preserve `head: []` as valid. Treat `null`, malformed JSON, wrong field types,
  unsuccessful HTTP statuses, and unreachable/timed-out renderers as no SSR result.
- Keep a correct ordinary CSR shell on failure and allow an application-level diagnostic hook and
  optional strict development mode. Do not expose renderer stacks, source paths, or raw private
  props in the public response. A failed renderer must not change a successful page into an empty
  document or an unrelated error response by default.

## V3 Development Vite Contract Versus Laravel Coupling

### What the Inertia Vite plugin actually implements

`@inertiajs/vite` is framework-agnostic with respect to PHP and detects Vue/React/Svelte from imports.
It registers a **POST** middleware at `/__inertia_ssr` on the existing Vite dev server, not on port
13714. Its `ssr.port`, `ssr.host`, and `ssr.cluster` options configure the **production bootstrap**;
Vite's own server configuration controls dev networking. [vite-plugin] [vite-ssr]

For each development request it:

1. Parses the posted page JSON.
2. Loads the SSR entry with `server.ssrLoadModule(entry)`.
3. Awaits the entry module's default export and requires it to be a render function.
4. Calls that function with the page and expects `{ head, body }`.
5. Prepends CSS link tags gathered from Vite's SSR module graph to `head`, then returns JSON.
   This CSS behavior is branch-pinned: the compared branch includes a post-`v3.7.0` change to the CSS
   collector. [vite-ssr] [vite-css] [core-release-compare]

It auto-detects `resources/js/ssr.{ts,tsx,js,jsx}`, then `src/ssr.{ts,tsx,js,jsx}`, then the corresponding
`app` entry candidates. An explicit entry must exist. With no discovered entry there is no SSR
middleware. While the module graph warms up the middleware returns JSON **`null`** so the backend can
fall back to CSR, not an error that warrants repeated retries. [vite-ssr] [vite-plugin]

For a supported top-level `createInertiaApp()` call, the plugin transforms the entry into a render
function factory and conditionally starts `createServer` only when `import.meta.env.PROD` is true.
For a supported manual `createServer(callback, options)` statement it extracts the callback,
conditionally starts the server, and exports the callback. These transforms are syntactic tooling
contracts, not something PHP should reproduce. An arbitrary module that starts its own HTTP server
on import is not automatically a safe dev SSR entry. [vite-transform] [vite-vue]

### What Laravel adds

Laravel chooses the dev endpoint when `Vite::isRunningHot()` is true. Its URL is:

```text
trim(config('inertia.ssr.hot_url') ?: contents_of(Vite::hotFile())) + '/__inertia_ssr'
```

It otherwise chooses the configured production base URL plus `/render`. The `hot_url` override is
used **inside hot mode**; setting it alone does not select that mode. Hot rendering skips the local
production bundle existence check. [gateway]

The inspected Inertia plugin does not write a Laravel hot file or provide a PHP discovery API.
Therefore, installing `@inertiajs/vite` alone does not give an LSR app Laravel-style endpoint discovery.
**Proposal:** start with explicit renderer endpoint configuration; optional later dev integration can
consume the app's existing Vite discovery mechanism and select `/__inertia_ssr`. In split host/container
setups distinguish the PHP-reachable renderer origin from the browser-reachable Vite asset origin.
The dev CSS collector derives asset origins from `server.origin` or Vite's resolved URLs, not from the
PHP gateway's hot URL. [vite-plugin] [gateway] [vite-css]

Build output paths are also not universally Laravel-shaped. Laravel's bundle detector checks an
explicit configured path, then `bootstrap/ssr/{ssr,app}.{js,mjs}`, then `public/js/{ssr,app}.js`.
Those filesystem guesses and `ensure_bundle_exists` are Laravel choices. The Vite plugin sets SSR
input and sourcemap options; it does not establish an LSR deployment directory. For remote renderer
containers, requiring the PHP host to possess the SSR bundle would incorrectly disable rendering.
[bundle-detector] [vite-plugin] [gateway]

## Application Entry, Hydration, and Assets

### Minimal framework-neutral route

**Proposal:** a consuming app can use the documented manual Vue SSR entry, build it with Vite's SSR
build mode, and run its generated entry directly with Node under its existing process manager.
Neither Laravel nor the Inertia Vite plugin is necessary for this HTTP contract. If the plugin is
present but automatic SSR handling is unwanted, its `ssr: false` option leaves manual setup possible.
[ssr-docs] [vite-ssr-guide]

The essential manual entry shape is upstream's documented API:

```ts
import { createInertiaApp } from '@inertiajs/vue3'
import createServer from '@inertiajs/vue3/server'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const pages = import.meta.glob('./Pages/**/*.vue')

createServer(page => createInertiaApp({
  page,
  render: renderToString,
  resolve: name => pages[`./Pages/${name}.vue`](),
  setup: ({ App, props, plugin }) =>
    createSSRApp({ render: () => h(App, props) }).use(plugin),
}), { host: '127.0.0.1', port: 13714 })
```

This is an explanatory skeleton, not a proposed package-owned app entry: the actual page paths,
plugins, layouts, localization, stores, and private-network bind belong to each consumer. A private
container deployment may need a different bind address than loopback. [ssr-docs] [vue-ssr]

On the browser side, a manual `setup` callback must hydrate rendered markup with `createSSRApp`, not
unconditionally mount `createApp` over it. A callback can choose `createSSRApp` when the root has
`data-server-rendered` and `createApp` otherwise, matching v3's default behavior and keeping clean CSR
fallback. With the v3 automatic app setup, this selection is already performed by `createInertiaApp`;
`withApp(app, { ssr, page })` is available for symmetric plugin configuration. A custom `setup`
callback takes responsibility for that behavior. [vue-app] [ssr-docs] [vue-ssr]

### Assets and SSR-safe app behavior

- Build both browser and server bundles from the same revision. Vite's SSR output can be loaded
  directly in production; Vite's module loader is a development facility. Vite externalizes
  dependencies by default, so deploying only the generated entry may not be sufficient: package
  runtime dependencies must be available unless the app deliberately bundles them. [vite-ssr-guide]
- SSR `head` does not replace normal asset manifest integration. The PHP shell must still emit
  the client entry, CSS, and required imported CSS. In development this includes the Vite HMR client
  and browser entry; in production use the client manifest. Optional per-page preload support can
  use Vite's SSR manifest, generated by the **client** build, but is not part of `{ head, body }`'s
  mandatory wire schema. [vite-backend] [vite-ssr-guide]
- The same component, props, layouts, and initial plugin state must produce matching server/client
  markup. Browser APIs belong in client-only lifecycle hooks; random values, different time zones,
  and invalid HTML nesting are common hydration mismatch causes. [vue-ssr]
- Create the app and user-specific stores per request. Long-lived Node module singletons must not
  accumulate one user's authentication/state into another user's render. Timers created during
  SSR setup are not cleaned up by unmount hooks, which do not run there. [vue-ssr]
- Vue Teleports need explicit SSR-context handling and shell insertion; stock `{ head, body }` does
  not promise an arbitrary teleport map. Client-only Teleports are the simpler alternative.
  Custom DOM directives need SSR-specific behavior when their attributes matter to initial HTML.
  [vue-ssr] [vue-app]

## Security and Deployment Constraints

1. **Private service boundary.** Use loopback or private service networking/firewall rules. The stock
   listener has no authentication, method restrictions, request body size cap in its body reader,
   or render cancellation mechanism. Public exposure permits unauthenticated rendering workload and
   process shutdown. These are observed implementation properties; any authentication/proxy/resource
   limit policy would be a deployment addition. [core-server]
2. **Trusted configuration only.** The SSR URL, dev discovery file, and root ID should come from app
   configuration, never request-controlled data. **Proposal:** do not forward browser cookies or
   authorization headers to a renderer that only needs the already-authorized page props. Protect
   internal configuration to avoid an SSRF/data-exfiltration path.
3. **Rendered HTML is trusted code output, not sanitized user content.** PHP must intentionally emit
   returned head/body as markup. Only configured trusted renderers may supply it. Applications still
   need safe Vue templates, appropriately sanitized explicit HTML, and correct script/attribute
   escaping. Protect error logs and source maps: classified renderer errors include stacks/source
   paths. CSP compatibility, script nonces, and allowed asset origins need application-level review;
   the stock initial-page JSON script helper itself has no nonce parameter. [ssr-body] [ssr-errors]
   [vue-head] [core-head]
4. **Supervise and restart on deployment.** Run the compiled SSR entry as a long-lived background
   process/container; restart it when deploying new server code. Laravel's command ultimately starts
   `[runtime, bundle]`, so LSR need not clone Artisan to run the renderer. Health checks should verify
   `/health`, and deployment verification should additionally render a real page; health alone does
   not check component availability, asset alignment, or hydration. [start-command] [ssr-docs]
   [core-server]
5. **Keep versions and data isolated.** Coordinate PHP page contract, client bundle, SSR bundle, and
   private renderer routing within each app deployment. **Proposal:** do not cache personalized SSR
   HTML globally by URL; any future cache must account for the full authorized page state and build
   version. Clustering is optional and should follow actual workload needs, not be required by LSR.

## Responsibility Boundary and Scope Choices

| Concern | Minimal LSR responsibility | App / Node / deployment responsibility |
| --- | --- | --- |
| Page data | Resolve initial page once; preserve metadata; use canonical normalization, then JSON encoding where needed. | Provide SSR-safe props/components and matching normalizer context/state. |
| Transport | Optional renderer call, exact page JSON, bounded request, validated `{ head, body }`, failure-to-CSR policy. | Expose private production `/render` or optional dev `/__inertia_ssr`. |
| HTML | Request-local render result, safe CSR JSON script fallback, raw trusted SSR head/body insertion without duplicate roots. | Own document shell, root ID agreement, assets, CSP, app-specific head policy. |
| Rendering | Do not implement JS evaluation in PHP or resolve app components in the package. | `createInertiaApp`, Vue `renderToString`, component resolution, app/store creation per request. |
| Hydration | Deliver exactly the page and markup used by SSR. | Hydrate SSR root; mount ordinary CSR fallback; keep plugins/layouts identical. |
| Development | Optional endpoint-selection seam; no mandatory Laravel discovery convention. | Vite plugin or manual development renderer and reachable URLs. |
| Production | Renderer URL/configuration; no mandatory bundle filesystem check on PHP hosts. | Build both bundles, package runtime dependencies, supervise/restart/private-network renderer. |

**Optional v3 plugin parity**, not required for a correct minimal integration: automatic dev endpoint
selection, framework-specific hot-file compatibility, detailed structured failure events/strict mode,
route exclusions, bundle detection helpers, SSR process management commands, clustering configuration,
and production preload optimization. The JS Vite plugin's transforms/error classification/CSS collection
already belong upstream; reproducing them inside LSR would add a second implementation without making
the PHP protocol more correct. [vite-plugin] [gateway] [ssr-docs]

### V3-Only Support

V3 is the only supported client/server protocol for this integration. The SSR success body and CSR
fallback both use the V3 JSON-script bootstrap; preserve `data-server-rendered` on success. There is
no V2 configuration switch, runtime major detection, legacy embedding or V2 acceptance matrix.
Browser and renderer dependencies must use compatible V3 versions from the consuming app's lockfile.
The earlier V2 comparison is intentionally removed following the explicit scope decision.
Sources: [vue-app] [dom-utils] [ssr-body] [protocol].

## Decisions and Unverified Items Before Implementation

- In code-hunt-game, which public pages should be enabled initially, and what first-response budget
  should configure the dedicated HTTP client's connect/total deadlines?
- Which existing LSR transport/DI convention should own an HTTP implementation, and which connection
  and total timeout defaults fit that consumer? The package currently has no HTTP-client dependency.
- Is minimal explicit endpoint configuration sufficient, or is the product asking for automatic Vite
  dev discovery? If automatic, use the actual app's asset integration rather than assuming Laravel.
- Which of the two supported Docker layouts should each deployment select? Both remain supported;
  concrete supervision, image packaging and private networking must be exercised for each.
- Application-specific CSP, locale/time-zone handling, custom root IDs, stores, browser-only plugins,
  Teleports, and performance under concurrent rendering remain unverified. These require a real app
  smoke run after implementation, not further inference from the protocol.

## Primary Sources

All source links below are revision-pinned except the explicitly labeled live Laravel framework
reference and live official documentation.

[protocol]: https://inertiajs.com/docs/v3/core-concepts/the-protocol
[ssr-docs]: https://inertiajs.com/docs/v3/advanced/server-side-rendering
[core-server]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/core/src/server.ts
[ssr-body]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/core/src/ssrUtils.ts
[dom-utils]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/core/src/domUtils.ts
[vue-app]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vue3/src/createInertiaApp.ts
[vue-head]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vue3/src/head.ts
[core-head]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/core/src/head.ts
[ssr-errors]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/core/src/ssrErrors.ts
[core-package]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vue3/package.json
[vite-package]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vite/package.json
[vite-plugin]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vite/src/index.ts
[vite-ssr]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vite/src/ssr.ts
[vite-transform]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vite/src/ssrTransform.ts
[vite-vue]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vite/src/frameworks/vue.ts
[vite-css]: https://github.com/inertiajs/inertia/blob/32174e7fbfa48e6ac4348c6557e1b8907d5500b2/packages/vite/src/css.ts
[gateway]: https://github.com/inertiajs/inertia-laravel/blob/ad280df0c4766a9a752b14b680b0e503da47c9d3/src/Ssr/HttpGateway.php
[directive]: https://github.com/inertiajs/inertia-laravel/blob/ad280df0c4766a9a752b14b680b0e503da47c9d3/src/Directive.php
[ssr-state]: https://github.com/inertiajs/inertia-laravel/blob/ad280df0c4766a9a752b14b680b0e503da47c9d3/src/Ssr/SsrState.php
[service-provider]: https://github.com/inertiajs/inertia-laravel/blob/ad280df0c4766a9a752b14b680b0e503da47c9d3/src/ServiceProvider.php
[bundle-detector]: https://github.com/inertiajs/inertia-laravel/blob/ad280df0c4766a9a752b14b680b0e503da47c9d3/src/Ssr/BundleDetector.php
[start-command]: https://github.com/inertiajs/inertia-laravel/blob/ad280df0c4766a9a752b14b680b0e503da47c9d3/src/Commands/StartSsr.php
[laravel-http]: https://github.com/laravel/framework/blob/13.x/src/Illuminate/Http/Client/PendingRequest.php
[core-release-compare]: https://github.com/inertiajs/inertia/compare/v3.7.0...32174e7fbfa48e6ac4348c6557e1b8907d5500b2
[adapter-release-compare]: https://github.com/inertiajs/inertia-laravel/compare/v3.3.3...ad280df0c4766a9a752b14b680b0e503da47c9d3
[vue-ssr]: https://vuejs.org/guide/scaling-up/ssr.html
[vite-ssr-guide]: https://vite.dev/guide/ssr
[vite-backend]: https://vite.dev/guide/backend-integration
[vite-getting-started]: https://vite.dev/guide/

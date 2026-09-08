# Inertia v3 Adapter Implementation Plan

This plan maps the current `lsr/inertia` package to the Inertia.js v3 server-side protocol and
breaks the missing features into compatible implementation phases.

## Current State

As of `lsr/inertia` `0.1.6`, the package provides a backward-compatible Inertia v3 adapter
foundation:

- `Inertia::render()` builds a page object with `component`, `props`, `url`, and `version`.
- Initial visits render HTML through the configured template; Inertia XHR visits return JSON.
- `LazyProp` can skip expensive props on initial visits and resolve them for partial reloads.
- `AlwaysProp`, `DeferredProp`, `MergeProp`, `DeepMergeProp`, and `OnceProp` model the implemented
  v3 prop metadata.
- `X-Inertia-Partial-Data`, `X-Inertia-Partial-Except`, and `X-Inertia-Except-Once-Props` are
  supported.
- Middleware adds Inertia headers for XHR visits, varies on `X-Inertia`, handles asset version
  conflicts with full request URIs, and converts mutating `302` redirects to `303`.
- `WithInertia` exposes controller-facing helpers intended for use from the `lsr/core` base
  controller.

Remaining larger gaps are shared props, history flags, redirect helpers, infinite scroll, validation
error bags, and root-template/SSR improvements.

## Implementation Status

Published release:

- `0.1.6`
- Tag: `v0.1.6`
- Release commit: `6cf5a9a`
- Published to `packages.laserliga.cz` via `./makePackage lsr/inertia`

Completed commits:

- `fa421ea` - Phase 1 protocol headers and partial-except support.
- `58f8892` - Phase 2 resolver foundation and Phase 3 deferred props.
- `37cda99` - Phase 4 merge/deep-merge metadata.
- `f9545ff` - Phase 5 once props.
- `236bec9` - `WithInertia` controller trait helpers.

Current validation status at publish time:

- `composer test` passed: 23 tests, 53 assertions.
- `composer phpstan` passed.
- `composer validate` passed with existing package warnings about missing license and committed
  `version`.
- Focused PHPCS for changed files passed.
- Full `composer cs` still fails on pre-existing style issues in untouched `DI` and `Factory` files.

## Design Goals

- Keep all additions backward compatible with the current public API.
- Avoid hard dependencies on application-specific services.
- Keep server-side protocol handling framework-agnostic and PSR-friendly.
- Add small, typed data objects for protocol concepts instead of growing `Inertia::render()` into one
  large conditional method.
- Make prop resolution deterministic and testable.
- Ship each phase as a patch or minor package release depending on API surface.

## Phase 1: Protocol Correctness

Status: completed in `fa421ea`, published in `0.1.6`.

### Implement

- Change response variance from `Vary: Accept` to `Vary: X-Inertia`.
- Ensure `X-Inertia: true` is present on every JSON Inertia response.
- Return the full request URI in `X-Inertia-Location` on asset version conflicts.
- Add support for `X-Inertia-Partial-Except`.
- Keep `X-Inertia-Partial-Data` behavior and component-name guard.
- Preserve existing `302` to `303` conversion for `PUT`, `PATCH`, and `DELETE`.

### API Impact

- No new public API required.
- Behavior-only compatibility fixes.

### Tests

- Inertia XHR response includes `X-Inertia: true`.
- Inertia XHR response includes `Vary: X-Inertia` and preserves existing `Vary` values.
- Version mismatch on `GET` returns `409` and full `X-Inertia-Location`.
- Partial `only` returns only listed props for the matching component.
- Partial `except` excludes listed props for the matching component.
- Partial headers for a different component are ignored.

## Phase 2: Prop Resolution Pipeline

Status: completed in `58f8892`, published in `0.1.6`.

### Implement

Introduce a dedicated resolver responsible for:

- Filtering framework-only props such as `page`, `app`, and `request`.
- Deciding which props should be included for full, partial-only, partial-except, deferred, and once
  requests.
- Resolving closures and prop wrapper objects.
- Returning both resolved props and page metadata.

Implemented classes:

- `Data\InertiaPropInterface`
- `Data\LazyProp`
- `Data\AlwaysProp`
- `Resolver\PropResolver`
- `Resolver\ResolvedPageProps`
- `Http\InertiaRequest`

### API Impact

- Keep existing `LazyProp` behavior.
- Add convenience methods:
  - `Inertia::lazy(callable $callback): LazyProp`
  - `Inertia::always(mixed|callable $value): AlwaysProp`

### Tests

- Plain closures resolve only when included.
- `LazyProp` is omitted on full visits and resolved when explicitly requested.
- `AlwaysProp` is included on full and partial reload responses.
- Nested closures continue to resolve where current behavior supports them.

## Phase 3: Deferred Props

Status: completed in `58f8892`, published in `0.1.6`.

### Implement

Add deferred prop support:

- `Inertia::defer(callable $callback, string $group = 'default', bool $rescue = false): DeferredProp`
- Omit deferred props from initial full page props.
- Add `deferredProps` page metadata grouped by request group.
- Resolve deferred props when requested by partial reload.
- When `rescue` is enabled and resolution throws, omit the prop and add the key to `rescuedProps`.

Implemented classes:

- `Data\DeferredProp`

Note: a separate `DeferredPropGroup` class was not needed; groups are represented directly in
`deferredProps` metadata by `PropResolver`.

### API Impact

- New optional API only.
- Existing `LazyProp` remains valid.

### Tests

- Initial response omits deferred prop value and includes `deferredProps`.
- Deferred props resolve on matching partial reload.
- Group names are reflected in `deferredProps`.
- Rescue-enabled failures populate `rescuedProps`.
- Non-rescued failures bubble normally.

## Phase 4: Merge Props

Status: completed in `37cda99`, published in `0.1.6`.

### Implement

Add prop wrappers that emit v3 merge metadata:

- `Inertia::merge(mixed|callable $value): MergeProp`
- `Inertia::deepMerge(mixed|callable $value): DeepMergeProp`
- `MergeProp::append(string|array|null $path = null, ?string $matchOn = null)`
- `MergeProp::prepend(string|array|null $path = null, ?string $matchOn = null)`
- `DeepMergeProp::matchOn(string|array $paths)`

Populate page metadata:

- `mergeProps`
- `prependProps`
- `deepMergeProps`
- `matchPropsOn`

### API Impact

- New optional API only.
- Requires clear path semantics for root props vs nested paths.

### Tests

- Root append adds prop key to `mergeProps`.
- Root prepend adds prop key to `prependProps`.
- Nested append/prepend emits dotted paths.
- Match-on values are emitted as dotted paths.
- Deep merge emits `deepMergeProps`.

## Phase 5: Once Props

Status: completed in `f9545ff`, published in `0.1.6`.

### Implement

Add once props:

- `Inertia::once(callable $callback, ?string $key = null): OnceProp`
- `OnceProp::fresh(bool $fresh = true)`
- `OnceProp::until(DateTimeInterface|DateInterval|int|null $expiresAt)`
- Handle `X-Inertia-Except-Once-Props`.
- Always include once-prop metadata in `onceProps`.
- Skip resolving and omit prop values when the client already has a non-expired once prop unless:
  - the prop is explicitly requested by partial reload,
  - `fresh()` is active,
  - the server-side expiration has passed.

### API Impact

- New optional API only.
- Needs timestamp normalization in milliseconds for protocol compatibility.

### Tests

- First response resolves once prop and includes `onceProps`.
- Later request with `X-Inertia-Except-Once-Props` omits the value but keeps metadata.
- Explicit partial reload forces resolution.
- `fresh()` forces resolution.
- Expired props resolve again.
- Custom once keys can differ from prop names.

## WithInertia Controller Trait

Status: completed in `236bec9`, published in `0.1.6`.

The `WithInertia` trait is intended to be added to the `lsr/core` base `Controller` so app
controllers can use Inertia directly without manually resolving `InertiaFactoryInterface`.

Implemented methods:

- `inertia(string $component, array|TemplateParametersInterface|null $parameters = null, string|UriInterface|null $url = null, string $template = 'pages/index'): ResponseInterface`
- `inertiaLazy(callable $callback): LazyProp`
- `inertiaAlways(mixed $value): AlwaysProp`
- `inertiaDefer(callable $callback, string $group = 'default', bool $rescue = false): DeferredProp`
- `inertiaMerge(mixed $value): MergeProp`
- `inertiaDeepMerge(mixed $value): DeepMergeProp`
- `inertiaOnce(callable $callback, ?string $key = null): OnceProp`
- `inertiaService(): Inertia`

Compatibility notes:

- Existing `inertia(string $component)` calls remain valid and still render with controller `$params`.
- The optional `$parameters`, `$url`, and `$template` arguments allow controller-level overrides
  without changing the existing call pattern.
- Helper names are prefixed with `inertia*` to reduce the chance of collisions with existing
  controller methods such as `merge()`, `once()`, or `defer()`.

Tests:

- Rendering uses controller `$params` by default.
- Explicit parameters override controller `$params`.
- Every trait helper returns the expected prop wrapper type.

## Phase 6: Shared Props

### Implement

Add shared prop registration:

- `Inertia::share(string|array $key, mixed|callable $value): void`
- `Inertia::getShared(): array`
- Merge shared props into each render response before request-local props.
- Track top-level shared keys in `sharedProps`.
- Decide whether shared data belongs on the request-scoped `Inertia` instance, a singleton manager,
  or both.

Recommended approach:

- Create a shared `InertiaManager` service for app-wide shared props.
- Keep request-specific render state on `Services\Inertia`.
- Let `InertiaFactory` inject the manager into each request instance.

### API Impact

- New optional API.
- Requires DI extension config for static/global shared props if desired.

### Tests

- Shared props are included in every page.
- Request-local props override shared props by key.
- Shared closures resolve lazily.
- `sharedProps` lists shared top-level keys.

## Phase 7: History Flags

### Implement

Add history encryption and clearing flags:

- `Inertia::encryptHistory(bool $encrypt = true): self`
- `Inertia::clearHistory(bool $clear = true): self`
- DI config:
  - `history.encrypt: bool`

Populate page metadata only when true:

- `encryptHistory`
- `clearHistory`

### API Impact

- New optional API and config.

### Tests

- Global encryption config adds `encryptHistory`.
- Per-request call can enable encryption.
- Per-request call can disable global encryption.
- Clearing history adds `clearHistory`.
- False flags are omitted from the page object.

## Phase 8: Redirect Helpers

### Implement

Add helpers for Inertia-specific redirects:

- `Inertia::location(string|UriInterface $url): ResponseInterface`
  - Returns `409 Conflict`.
  - Adds `X-Inertia-Location`.
  - Does not include `X-Inertia` on the final response.
- Fragment-preserving redirect support:
  - Either a response wrapper/decorator or middleware handling for redirect responses.
  - Support `X-Inertia-Redirect` when preserving fragments across redirects.
  - Add `preserveFragment` page metadata where appropriate.

### API Impact

- New optional API.
- Fragment preservation depends on how LSR routing/redirect responses expose target URLs.

### Tests

- External location response has `409` and `X-Inertia-Location`.
- Middleware strips `X-Inertia` from external redirect responses.
- Fragment preservation emits the expected protocol header or page metadata.
- Mutating redirects still become `303`.

## Phase 9: Infinite Scroll

### Implement

Add an infinite-scroll wrapper:

- `Inertia::scroll(mixed|callable $paginator, string $dataPath = 'data', string $pageName = 'page'): ScrollProp`

Populate metadata:

- `scrollProps`
- `mergeProps` for the scroll data path.

The hard part is paginator normalization. Since this package is framework-agnostic, support should
start with a documented array shape:

```php
[
    'data' => [...],
    'current_page' => 1,
    'prev_page_url' => null,
    'next_page_url' => '/users?page=2',
]
```

Later, adapters can be added for known paginator interfaces if LSR apps standardize on one.

### API Impact

- New optional API.
- Needs documented supported paginator shapes.

### Tests

- Scroll prop emits `scrollProps`.
- Scroll prop emits merge metadata for the configured data path.
- Previous, next, current page, and page name are normalized.
- Append/prepend intent honors `X-Inertia-Infinite-Scroll-Merge-Intent`.

## Phase 10: Validation And Error Bags

### Implement

Evaluate how validation errors are currently represented in LSR apps and add protocol support:

- Ensure `errors` is always present in props as an object or array.
- Support `X-Inertia-Error-Bag`.
- Provide an error bag resolver extension point.

### API Impact

- New optional integration point.
- Depends on `lsr/request` or current validation/session conventions.

### Tests

- Empty errors are present.
- Named error bag is selected from request header.
- Validation redirects preserve error data if the framework has flash/session support.

## Phase 11: Root Template Data And SSR

### Implement

Root template support:

- Configurable root template path.
- Configurable root element ID.
- Optional extra root template data.
- Confirm the page object is escaped and embedded as JSON in the expected v3 shape.

SSR support should be planned separately because it likely needs an HTTP/Node SSR service boundary.

### API Impact

- DI config additions only for root template behavior.
- SSR would be a larger feature and should not be mixed into the core protocol work.

### Tests

- Root template receives `inertiaPage`.
- Root element ID can be configured if the template uses package-provided data.
- JSON payload is safely embeddable.

## Proposed Release Slices

### Patch: Protocol compatibility

- Completed in `0.1.6`.
- Phase 1 behavior fixes.

### Minor: Prop resolver foundation

- Completed in `0.1.6` as a patch update because the API is additive and backward compatible.
- Phase 2 internal resolver and helper methods.

### Minor: Deferred and once props

- Completed in `0.1.6` as a patch update because the API is additive and backward compatible.
- Phases 3 and 5.

### Minor: Merge and infinite scroll

- Phase 4 completed in `0.1.6`.
- Phase 9 remains open.

### Minor: Shared props, history, redirects

- Phases 6, 7, and 8.
- Adds framework integration APIs.

### Follow-up: Validation and SSR

- Phases 10 and 11.
- Needs app-level conventions before implementation.

## Backward Compatibility Notes

- Keep `Inertia::render()` signature compatible.
- Keep `LazyProp` working as-is.
- Do not require apps to use static facade-style APIs.
- New APIs should be additive and injectable through existing `InertiaFactoryInterface`.
- If a singleton manager is introduced, keep request-scoped `Inertia` instances as the render entry
  point.
- Avoid changing the default template name unless a major release is planned.
- Keep `WithInertia::inertia(string $component)` valid for existing controllers.
- Prefer adding new trait helpers with `inertia*` prefixes to avoid collisions in downstream
  controller classes.

## Validation Checklist

For every implementation slice:

- `composer test`
- `composer phpstan`
- `composer cs`
- Add PHPUnit coverage for every protocol header and page-object metadata field touched.
- Validate at least one consuming app through a temporary Composer path repository when a feature is
  wired into an app.
- For published releases, commit implementation first, then make a separate version bump commit, tag
  it, push, and publish via `Libraries/lsr-packages/makePackage`.

## References

- Inertia v3 protocol: https://inertiajs.com/docs/v3/core-concepts/the-protocol
- Partial reloads: https://inertiajs.com/docs/v3/data-props/partial-reloads
- Deferred props: https://inertiajs.com/docs/v3/data-props/deferred-props
- Merging props: https://inertiajs.com/docs/v3/data-props/merging-props
- Once props: https://inertiajs.com/docs/v3/data-props/once-props
- Redirects: https://inertiajs.com/docs/v3/the-basics/redirects
- Infinite scroll: https://inertiajs.com/docs/v3/data-props/infinite-scroll
- History encryption: https://inertiajs.com/docs/v3/security/history-encryption

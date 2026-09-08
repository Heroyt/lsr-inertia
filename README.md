# LSR Inertia

`lsr/inertia` is a PHP Inertia adapter providing PSR-15 middleware, page rendering, prop helpers and an optional HTTP SSR bridge. It returns JSON for Inertia requests and renders an application-owned HTML template for ordinary requests. It does not provide a JavaScript client, frontend build or SSR server.

## Requirements

- PHP `>=8.4`.
- LSR Interfaces `^0.3.16` and Symfony Serializer `^8|^7|^6`.
- PSR HTTP client `^1`, HTTP factory `^1`, HTTP message `^1|^2` and HTTP server middleware `^1` contracts.
- Concrete PSR response/stream factories, an `Lsr\Interfaces\ViewFactoryInterface` implementation and a Symfony `NormalizerInterface` service supplied by the application.
- Nette DI is needed when using the included DI extension; it is a development dependency here, not a required runtime dependency. Latte is also a development dependency, not a mandatory rendering engine.
- SSR additionally needs a PSR-18 HTTP client, PSR request factory and separately operated compatible renderer.
- No PHP extensions are explicitly required by this package's manifest; dependencies may add platform requirements.

## Installation

```shell
composer require lsr/inertia
```

## Application integration

For applications using Nette DI, register the extension alongside the application's factory, view and normalizer services:

```neon
extensions:
    inertia: Lsr\Inertia\DI\InertiaExtension

inertia:
    rootId: app
    normalizationContext: []
```

The extension registers `Lsr\Inertia\Factory\InertiaFactoryInterface` and `Lsr\Inertia\Middleware\InertiaMiddleware`. Add the middleware to the application's actual PSR-15 pipeline before handlers that render Inertia pages; registering a service alone does not install it in a pipeline.

The middleware puts a request-specific `Lsr\Inertia\Services\Inertia` instance in the request attribute `inertia`. In a handler receiving that decorated request:

```php
use Lsr\Inertia\Middleware\InertiaMiddleware;
use Lsr\Inertia\Services\Inertia;
use Psr\Http\Message\ServerRequestInterface;

/** @var ServerRequestInterface $request */
$inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
if (!$inertia instanceof Inertia) {
    throw new LogicException('Inertia middleware must run before this handler.');
}

return $inertia->render('Welcome', ['message' => 'Hello from PHP']);
```

The application must supply the `Welcome` frontend component and the root template. The default template name is `pages/index`; the fourth `render()` argument selects another template. `render()` returns a PSR response, leaving emission to the application.

Alternatively, inject `InertiaFactoryInterface` and call `fromRequest($request)`. Each call creates a new service, so changes to one instance, including its public `version` property, do not carry over to another. The optional [`WithInertia`](src/Http/WithInertia.php) controller trait expects `$request`, `$params` and an injected factory; it uses this factory path.

## Root template and protocol behavior

The view receives `inertiaPage`, `inertiaHead` and `inertiaBody` in addition to the supplied template parameters. Insert the generated head and body HTML without double-escaping, using the rendering engine's trusted-HTML mechanism. Escape any unrelated application content normally.

The client-rendered fallback body contains a JSON `<script data-page="app" type="application/json">` followed by `<div id="app"></div>` (with the configured root ID). This is the adapter's current V3-style bootstrap, not the older root `data-page` attribute format; align the JavaScript client with it. SSR HTML comes from the configured renderer and must be trusted.

`render()` emits `Vary: X-Inertia` for HTML and JSON responses. For Inertia requests, middleware also handles asset-version mismatches on GET with a `409` location response and changes `302` redirects after PUT/PATCH/DELETE to `303`. To enable version checks, set `version` on the same middleware-created instance before returning the response.

Prop helpers include `lazy()`, `always()`, `defer()`, `merge()`, `deepMerge()` and `once()`. Partial reload and prop metadata handling live in [PropResolver](src/Resolver/PropResolver.php); see the [prop classes](src/Data) and [service tests](tests/Services/InertiaTest.php) for their specific options and request-header behavior.

## Optional server-side rendering

SSR is disabled by default. With the required client and factory services available:

```neon
inertia:
    ssr:
        enabled: true
        url: http://127.0.0.1:13714/render
        client: @ssrHttpClient
        throwOnError: false
```

`ssrHttpClient` is an application-owned PSR-18 client; configure its timeouts in that service. If `client` is omitted, DI must resolve an autowired client. Use a trusted, application-configured HTTP(S) URL, never a URL supplied by a request.

The bridge POSTs the normalized page JSON and accepts JSON containing `head` (a list of HTML strings) and `body` (a nonempty HTML string), or JSON `null` to decline rendering. The returned body must include the client bootstrap and populated root. SSR runs only for non-Inertia GET renders; the request attribute `Inertia::SSR_ENABLED_ATTRIBUTE` set to `false` disables it for that request. Recognized SSR failures fall back to client rendering unless `throwOnError` is enabled.

See [InertiaExtension](src/DI/InertiaExtension.php), [InertiaFactory](src/Factory/InertiaFactory.php), [Inertia](src/Services/Inertia.php) and [HttpRenderer](src/Ssr/HttpRenderer.php) for the exact integration boundaries.

## Development

CI runs the checks below on PHP 8.4 and 8.5. From a package checkout:

```shell
composer install --prefer-dist --no-interaction --no-progress
composer cs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --no-coverage
```

`composer cs` checks coding style without changing files. Run `composer cs:fix` (or `composer cbf`) to apply PHP CS Fixer rules from [.php-cs-fixer.php](.php-cs-fixer.php).

The suite uses Nyholm PSR-7, Nette DI and Latte development dependencies and does not need an SSR server. Install the DOM, mbstring, XML and XMLWriter extensions for PHPUnit. The `composer test` script enables Xdebug coverage; the CI command explicitly disables coverage collection.

## AI coding assistance

See [LSR Skills](https://github.com/Heroyt/lsr-skills) for AI agent skills for working with the LSR framework.

## License

Licensed under the [MIT License](LICENSE).

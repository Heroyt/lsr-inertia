<?php
declare(strict_types=1);

namespace Lsr\Inertia\Middleware;

use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Inertia\Services\Inertia;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class InertiaMiddleware implements MiddlewareInterface
{

    public const string INERTIA_ATTRIBUTE = 'inertia';

    public function __construct(
        private readonly InertiaFactoryInterface $inertiaFactory,
        private readonly string $attributeKey = self::INERTIA_ATTRIBUTE,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $inertia = $this->inertiaFactory->fromRequest($request);
        $request = $request->withAttribute($this->attributeKey, $inertia);

        $response = $handler->handle($request);
        if ( ! $request->hasHeader('X-Inertia')) {
            return $response;
        }

        $response = $this->withVaryAccept($response)
            ->withHeader('X-Inertia', 'true');

        $response = $this->checkVersion($request, $response, $inertia);
        $response = $this->changeRedirectCode($request, $response);

        if ($response->getStatusCode() !== 409 && $response->hasHeader('X-Inertia-Location')) {
            return $response->withoutHeader('X-Inertia-Location');
        }

        return $response;
    }

    private function checkVersion(ServerRequestInterface $request, ResponseInterface $response, Inertia $inertia): ResponseInterface {
        if (
            $inertia->version !== null
            && $request->getMethod() === 'GET'
            && $request->getHeaderLine('X-Inertia-Version') !== $inertia->version
        ) {
            return $response
                ->withStatus(409)
                ->withHeader('X-Inertia-Location', $request->getUri()->getPath());
        }

        return $response;
    }

    private function changeRedirectCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        if (
            $response->getStatusCode() === 302
            && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            return $response->withStatus(303);
        }

        // For External redirects
        // https://inertiajs.com/redirects#external-redirects
        if (
            $response->getStatusCode() === 409
            && $response->hasHeader('X-Inertia-Location')
        ) {
            return $response->withoutHeader('X-Inertia');
        }

        return $response;
    }

    private function withVaryAccept(ResponseInterface $response): ResponseInterface {
        foreach ($response->getHeader('Vary') as $value) {
            $values = array_map('trim', explode(',', strtolower($value)));
            if (in_array('accept', $values, true)) {
                return $response;
            }
        }

        return $response->withAddedHeader('Vary', 'Accept');
    }
}
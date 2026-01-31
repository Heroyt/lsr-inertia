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
        private readonly string                  $attributeKey = self::INERTIA_ATTRIBUTE,
    )
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $inertia = $this->inertiaFactory->fromRequest($request);

        $request = $request->withAttribute($this->attributeKey, $inertia);

        if (!$request->hasHeader('X-Inertia')) {
            return $handler->handle($request);
        }

        /** @var ResponseInterface */
        $response = $handler->handle($request)
            ->withAddedHeader('Vary', 'Accept')
            ->withAddedHeader('X-Inertia', 'true');

        $response = $this->checkVersion($request, $response, $inertia);
        $response = $this->changeRedirectCode($request, $response);

        return $response;
    }

    private function checkVersion(ServerRequestInterface $request, ResponseInterface $response, Inertia $inertia): ResponseInterface
    {
        if (
            'GET' === $request->getMethod()
            && $request->getHeader('X-Inertia-Version') !== $inertia->version
        ) {
            return $response->withAddedHeader('X-Inertia-Location', $request->getUri()->getPath());
        }

        return $response;
    }

    private function changeRedirectCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$request->hasHeader('X-Inertia')) {
            return $response;
        }

        if (
            302 === $response->getStatusCode()
            && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'])
        ) {
            return $response->withStatus(303);
        }

        // For External redirects
        // https://inertiajs.com/redirects#external-redirects
        if (
            409 === $response->getStatusCode()
            && $response->hasHeader('X-Inertia-Location')
        ) {
            return $response->withoutHeader('X-Inertia');
        }

        return $response;
    }
}
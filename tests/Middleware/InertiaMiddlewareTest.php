<?php

declare(strict_types=1);

namespace Tests\Middleware;

use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Inertia\Middleware\InertiaMiddleware;
use Lsr\Inertia\Services\Inertia;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use Tests\Fixtures\CallbackRequestHandler;
use Tests\Fixtures\StringViewFactory;

class InertiaMiddlewareTest extends TestCase
{
    private Psr17Factory $psr17Factory;

    protected function setUp(): void {
        $this->psr17Factory = new Psr17Factory();
    }

    public function testInertiaResponseVariesOnInertiaHeader(): void {
        $middleware = $this->createMiddleware();
        $request = $this->psr17Factory
            ->createServerRequest('GET', 'https://example.test/users')
            ->withHeader('X-Inertia', 'true');

        $response = $middleware->process($request, new CallbackRequestHandler(
            fn(): \Psr\Http\Message\ResponseInterface => $this->psr17Factory
                ->createResponse()
                ->withHeader('Vary', 'Accept'),
        ));

        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
        self::assertSame(['Accept', 'X-Inertia'], $response->getHeader('Vary'));
    }

    public function testVersionMismatchReturnsConflictWithFullLocation(): void {
        $middleware = $this->createMiddleware('new-version');
        $request = $this->psr17Factory
            ->createServerRequest('GET', 'https://example.test/users?active=1')
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', 'old-version');

        $response = $middleware->process($request, new CallbackRequestHandler(
            fn(): \Psr\Http\Message\ResponseInterface => $this->psr17Factory->createResponse(),
        ));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('https://example.test/users?active=1', $response->getHeaderLine('X-Inertia-Location'));
        self::assertSame('', $response->getHeaderLine('X-Inertia'));
    }

    public function testExistingInertiaVaryHeaderIsNotDuplicated(): void {
        $middleware = $this->createMiddleware();
        $request = $this->psr17Factory
            ->createServerRequest('GET', 'https://example.test/users')
            ->withHeader('X-Inertia', 'true');

        $response = $middleware->process($request, new CallbackRequestHandler(
            fn(): \Psr\Http\Message\ResponseInterface => $this->psr17Factory
                ->createResponse()
                ->withHeader('Vary', 'X-Inertia'),
        ));

        self::assertSame(['X-Inertia'], $response->getHeader('Vary'));
    }

    private function createMiddleware(?string $version = null): InertiaMiddleware {
        return new InertiaMiddleware(new class ($this->psr17Factory, $version) implements InertiaFactoryInterface {
            public function __construct(
                private readonly Psr17Factory $psr17Factory,
                private readonly ?string $version,
            ) {
            }

            public function fromRequest(ServerRequestInterface $request): Inertia {
                $inertia = new Inertia(
                    $request,
                    $this->psr17Factory,
                    $this->psr17Factory,
                    new StringViewFactory(),
                    new Serializer([], [new JsonEncoder()]),
                );
                $inertia->version = $this->version;

                return $inertia;
            }
        });
    }
}

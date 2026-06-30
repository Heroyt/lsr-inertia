<?php

declare(strict_types=1);

namespace Tests\Services;

use Lsr\Inertia\Data\LazyProp;
use Lsr\Inertia\Services\Inertia;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use Tests\Fixtures\StringViewFactory;

class InertiaTest extends TestCase
{
    private Psr17Factory $psr17Factory;

    protected function setUp(): void {
        $this->psr17Factory = new Psr17Factory();
    }

    public function testJsonResponseIncludesInertiaHeader(): void {
        $response = $this->createInertia([
            'X-Inertia' => 'true',
        ])->render('Users/Index', [
            'users' => ['John'],
        ]);

        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testPartialOnlyReturnsRequestedPropsForMatchingComponent(): void {
        $response = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data' => 'users',
        ])->render('Users/Index', [
            'users' => ['John'],
            'companies' => ['Acme'],
        ]);

        $props = $this->getProps($response);

        self::assertSame(['John'], $props['users']);
        self::assertArrayNotHasKey('companies', $props);
    }

    public function testPartialExceptExcludesRequestedPropsForMatchingComponent(): void {
        $response = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Except' => 'companies',
        ])->render('Users/Index', [
            'users' => ['John'],
            'companies' => ['Acme'],
        ]);

        $props = $this->getProps($response);

        self::assertSame(['John'], $props['users']);
        self::assertArrayNotHasKey('companies', $props);
    }

    public function testPartialHeadersForDifferentComponentAreIgnored(): void {
        $response = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Companies/Index',
            'X-Inertia-Partial-Data' => 'users',
        ])->render('Users/Index', [
            'users' => ['John'],
            'companies' => new LazyProp(static fn(): array => ['Acme']),
        ]);

        $props = $this->getProps($response);

        self::assertSame(['John'], $props['users']);
        self::assertArrayNotHasKey('companies', $props);
    }

    public function testClosuresResolveWhenIncluded(): void {
        $response = $this->createInertia([
            'X-Inertia' => 'true',
        ])->render('Users/Index', [
            'users' => static fn(): array => ['John'],
            'nested' => [
                'companies' => static fn(): array => ['Acme'],
            ],
        ]);

        $props = $this->getProps($response);

        self::assertSame(['John'], $props['users']);
        self::assertSame(['Acme'], $props['nested']['companies']);
    }

    public function testAlwaysPropIsIncludedOnPartialReloads(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data' => 'users',
        ]);

        $response = $inertia->render('Users/Index', [
            'users' => ['John'],
            'stats' => $inertia->always(static fn(): array => ['count' => 10]),
            'companies' => ['Acme'],
        ]);

        $props = $this->getProps($response);

        self::assertSame(['John'], $props['users']);
        self::assertSame(['count' => 10], $props['stats']);
        self::assertArrayNotHasKey('companies', $props);
    }

    public function testDeferredPropsAreOmittedFromInitialResponseWithMetadata(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->render('Users/Index', [
            'users' => ['John'],
            'permissions' => $inertia->defer(static fn(): array => ['edit']),
            'teams' => $inertia->defer(static fn(): array => ['Blue'], 'attributes'),
        ]);

        $page = $this->getPage($response);

        self::assertSame(['John'], $page['props']['users']);
        self::assertArrayNotHasKey('permissions', $page['props']);
        self::assertArrayNotHasKey('teams', $page['props']);
        self::assertSame([
            'default' => ['permissions'],
            'attributes' => ['teams'],
        ], $page['deferredProps']);
    }

    public function testDeferredPropsResolveOnMatchingPartialReload(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data' => 'permissions',
        ]);

        $response = $inertia->render('Users/Index', [
            'users' => ['John'],
            'permissions' => $inertia->defer(static fn(): array => ['edit']),
        ]);

        $page = $this->getPage($response);

        self::assertSame(['edit'], $page['props']['permissions']);
        self::assertArrayNotHasKey('users', $page['props']);
        self::assertArrayNotHasKey('deferredProps', $page);
    }

    public function testRescuedDeferredPropsAreReportedWhenResolutionFails(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data' => 'permissions',
        ]);

        $response = $inertia->render('Users/Index', [
            'permissions' => $inertia->defer(
                static fn(): array => throw new \RuntimeException('Failed to load permissions.'),
                rescue: true,
            ),
        ]);

        $page = $this->getPage($response);

        self::assertArrayNotHasKey('permissions', $page['props']);
        self::assertSame(['permissions'], $page['rescuedProps']);
    }

    /**
     * @param array<string, string> $headers
     */
    private function createInertia(array $headers): Inertia {
        $request = $this->psr17Factory->createServerRequest('GET', 'https://example.test/users?active=1');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return new Inertia(
            $request,
            $this->psr17Factory,
            $this->psr17Factory,
            new StringViewFactory(),
            new Serializer([], [new JsonEncoder()]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getProps(\Psr\Http\Message\ResponseInterface $response): array {
        return $this->getPage($response)['props'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPage(\Psr\Http\Message\ResponseInterface $response): array {
        /** @var array<string, mixed> $page */
        $page = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $page;
    }
}

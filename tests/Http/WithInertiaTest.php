<?php

declare(strict_types=1);

namespace Tests\Http;

use Lsr\Inertia\Data\AlwaysProp;
use Lsr\Inertia\Data\DeepMergeProp;
use Lsr\Inertia\Data\DeferredProp;
use Lsr\Inertia\Data\LazyProp;
use Lsr\Inertia\Data\MergeProp;
use Lsr\Inertia\Data\OnceProp;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Fixtures\InertiaControllerStub;
use Tests\Fixtures\InertiaFactoryStub;

final class WithInertiaTest extends TestCase
{
    private Psr17Factory $psr17Factory;

    protected function setUp(): void {
        $this->psr17Factory = new Psr17Factory();
    }

    public function testRendersUsingControllerParams(): void {
        $controller = $this->createController([
            'X-Inertia' => 'true',
        ], [
            'users' => ['John'],
        ]);

        $props = $this->getProps($controller->render('Users/Index'));

        self::assertSame(['John'], $props['users']);
    }

    public function testRenderParametersCanBeOverridden(): void {
        $controller = $this->createController([
            'X-Inertia' => 'true',
        ], [
            'users' => ['John'],
        ]);

        $props = $this->getProps($controller->renderWithParameters('Users/Index', [
            'users' => ['Jane'],
        ]));

        self::assertSame(['Jane'], $props['users']);
    }

    public function testExposesInertiaPropHelpers(): void {
        $controller = $this->createController();

        self::assertInstanceOf(LazyProp::class, $controller->lazy(static fn(): array => []));
        self::assertInstanceOf(AlwaysProp::class, $controller->always([]));
        self::assertInstanceOf(DeferredProp::class, $controller->defer(static fn(): array => []));
        self::assertInstanceOf(MergeProp::class, $controller->merge([]));
        self::assertInstanceOf(DeepMergeProp::class, $controller->deepMerge([]));
        self::assertInstanceOf(OnceProp::class, $controller->once(static fn(): array => []));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     */
    private function createController(array $headers = [], array $params = []): InertiaControllerStub {
        $request = $this->psr17Factory->createServerRequest('GET', 'https://example.test/users');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return new InertiaControllerStub(
            new InertiaFactoryStub($this->psr17Factory),
            $request,
            $params,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getProps(ResponseInterface $response): array {
        /** @var array{props: array<string, mixed>} $page */
        $page = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $page['props'];
    }
}

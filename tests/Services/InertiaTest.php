<?php

declare(strict_types=1);

namespace Tests\Services;

use Lsr\Inertia\Data\LazyProp;
use Lsr\Inertia\Services\Inertia;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
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

    public function testMergePropAddsRootMergeMetadata(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->render('Feed/Index', [
            'posts' => $inertia->merge([
                ['id' => 1, 'title' => 'First post'],
            ]),
        ]);

        $page = $this->getPage($response);

        self::assertSame([['id' => 1, 'title' => 'First post']], $page['props']['posts']);
        self::assertSame(['posts'], $page['mergeProps']);
    }

    public function testMergePropSupportsNestedAppendPrependAndMatching(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->render('Feed/Index', [
            'feed' => $inertia
                ->merge([
                    'posts' => [['id' => 1]],
                    'notifications' => [['uuid' => 'a']],
                ])
                ->append('posts', matchOn: 'id')
                ->prepend(['notifications' => 'uuid']),
        ]);

        $page = $this->getPage($response);

        self::assertSame(['feed.posts'], $page['mergeProps']);
        self::assertSame(['feed.notifications'], $page['prependProps']);
        self::assertSame(['feed.posts.id', 'feed.notifications.uuid'], $page['matchPropsOn']);
    }

    public function testDeepMergePropAddsMetadataAndMatchPaths(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->render('Chat/Index', [
            'chat' => $inertia
                ->deepMerge([
                    'messages' => [['id' => 1]],
                ])
                ->matchOn('messages.id'),
        ]);

        $page = $this->getPage($response);

        self::assertSame(['chat'], $page['deepMergeProps']);
        self::assertSame(['chat.messages.id'], $page['matchPropsOn']);
    }

    public function testOncePropResolvesAndAddsMetadata(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->render('Billing/Plans', [
            'plans' => $inertia->once(static fn(): array => ['Basic']),
        ]);

        $page = $this->getPage($response);

        self::assertSame(['Basic'], $page['props']['plans']);
        self::assertSame([
            'plans' => [
                'prop' => 'plans',
                'expiresAt' => null,
            ],
        ], $page['onceProps']);
    }

    public function testOncePropSkipsAlreadyLoadedValueButKeepsMetadata(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Except-Once-Props' => 'plans',
        ]);

        $response = $inertia->render('Billing/Upgrade', [
            'plans' => $inertia->once(static fn(): array => ['Basic']),
            'currentPlan' => 'Basic',
        ]);

        $page = $this->getPage($response);

        self::assertArrayNotHasKey('plans', $page['props']);
        self::assertSame('Basic', $page['props']['currentPlan']);
        self::assertSame([
            'plans' => [
                'prop' => 'plans',
                'expiresAt' => null,
            ],
        ], $page['onceProps']);
    }

    public function testExplicitPartialReloadResolvesOnceProp(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Billing/Plans',
            'X-Inertia-Partial-Data' => 'plans',
            'X-Inertia-Except-Once-Props' => 'plans',
        ]);

        $response = $inertia->render('Billing/Plans', [
            'plans' => $inertia->once(static fn(): array => ['Basic']),
        ]);

        $page = $this->getPage($response);

        self::assertSame(['Basic'], $page['props']['plans']);
    }

    public function testFreshOncePropForcesResolution(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Except-Once-Props' => 'plans',
        ]);

        $response = $inertia->render('Billing/Plans', [
            'plans' => $inertia->once(static fn(): array => ['Basic'])->fresh(),
        ]);

        $page = $this->getPage($response);

        self::assertSame(['Basic'], $page['props']['plans']);
    }

    public function testExpiredOncePropForcesResolutionAndSupportsCustomKey(): void {
        $inertia = $this->createInertia([
            'X-Inertia' => 'true',
            'X-Inertia-Except-Once-Props' => 'billing.plans',
        ]);

        $response = $inertia->render('Billing/Plans', [
            'plans' => $inertia->once(static fn(): array => ['Basic'], 'billing.plans')->until(-1),
        ]);

        $page = $this->getPage($response);

        self::assertSame(['Basic'], $page['props']['plans']);
        self::assertSame('plans', $page['onceProps']['billing.plans']['prop']);
        self::assertIsInt($page['onceProps']['billing.plans']['expiresAt']);
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
    private function getProps(ResponseInterface $response): array {
        return $this->getPage($response)['props'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPage(ResponseInterface $response): array {
        /** @var array<string, mixed> $page */
        $page = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $page;
    }
}

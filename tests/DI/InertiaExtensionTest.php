<?php

declare(strict_types=1);

namespace Tests\DI;

use Closure;
use DateTimeImmutable;
use Lsr\Inertia\DI\InertiaExtension;
use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Inertia\Ssr\SsrException;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerBuilder;
use Nette\DI\ContainerLoader;
use Nette\DI\Definitions\Statement;
use Nette\DI\ServiceCreationException;
use Nette\Utils\FileSystem;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

final class InertiaExtensionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/lsr-inertia-di-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
    }

    protected function tearDown(): void {
        FileSystem::delete($this->directory);
    }

    public function testDisabledSsrIgnoresClientAndHonorsRenderingOptionsWithoutRequestFactory(): void {
        $container = $this->createContainer([
            'rootId' => 'portal',
            'normalizationContext' => [DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'],
            'jsonEncodeOptions' => JSON_PRESERVE_ZERO_FRACTION,
            'ssr' => ['client' => '@unregisteredClient'],
        ]);
        $factory = $container->getByType(InertiaFactoryInterface::class);
        $response = $factory->fromRequest(new ServerRequest('GET', 'https://example.test/'))->render('Home', [
            'date' => new DateTimeImmutable('2026-09-06T12:30:00+00:00'),
            'name' => 'Žofie',
            'amount' => 1.0,
        ]);
        $html = (string) $response->getBody();

        self::assertStringContainsString('id="portal"', $html);
        self::assertStringContainsString('"date":"2026-09-06"', $html);
        self::assertStringContainsString('"name":"\\u017dofie"', $html);
        self::assertStringContainsString('"amount":1.0', $html);
    }

    public function testEnabledSsrRequiresClient(): void {
        $this->expectException(ServiceCreationException::class);
        $this->expectExceptionMessage(ClientInterface::class);

        $this->createContainer(['ssr' => ['enabled' => true]], requestFactory: true);
    }

    public function testEnabledSsrAutowiresApplicationClient(): void {
        $container = $this->createContainer(
            ['ssr' => ['enabled' => true]],
            static function (ContainerBuilder $builder): void {
                $builder->addDefinition('applicationClient')->setFactory(RecordingClient::class);
            },
            requestFactory: true,
        );
        $response = $container->getByType(InertiaFactoryInterface::class)
            ->fromRequest(new ServerRequest('GET', 'https://example.test/'))
            ->render('Home');

        self::assertStringContainsString('<main>Rendered by dedicated client</main>', (string) $response->getBody());
    }

    public function testDedicatedClientReferenceDoesNotOverrideGlobalClientOrLeakRequestState(): void {
        $container = $this->createContainer(
            ['ssr' => [
                'enabled' => true,
                'url' => 'https://renderer.internal/custom/render',
                'client' => '@dedicatedClient',
            ]],
            static function (ContainerBuilder $builder): void {
                $builder->addDefinition('applicationClient')->setFactory(RecordingClient::class, ['null']);
                $builder->addDefinition('dedicatedClient')->setFactory(RecordingClient::class)->setAutowired(false);
            },
            requestFactory: true,
        );
        $factory = $container->getByType(InertiaFactoryInterface::class);
        $first = $factory->fromRequest(new ServerRequest('GET', 'https://example.test/first'));
        $first->version = 'first-request-version';
        $response = $first->render('First');
        $factory->fromRequest(new ServerRequest('GET', 'https://example.test/second'))->render('Second');

        self::assertStringContainsString('<title>Dedicated SSR</title>', (string) $response->getBody());
        self::assertStringContainsString('<main>Rendered by dedicated client</main>', (string) $response->getBody());
        /** @var RecordingClient $dedicatedClient */
        $dedicatedClient = $container->getService('dedicatedClient');
        self::assertCount(2, $dedicatedClient->requests);
        self::assertSame('https://renderer.internal/custom/render', (string) $dedicatedClient->requests[0]->getUri());
        $firstPage = json_decode((string) $dedicatedClient->requests[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $secondPage = json_decode((string) $dedicatedClient->requests[1]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('first-request-version', $firstPage['version']);
        self::assertNull($secondPage['version']);
        self::assertSame('Second', $secondPage['component']);
        /** @var RecordingClient $applicationClient */
        $applicationClient = $container->getByType(ClientInterface::class);
        self::assertSame([], $applicationClient->requests);
    }

    public function testDedicatedClientStatementAndStrictErrorOptionAreUsed(): void {
        $container = $this->createContainer(
            ['ssr' => [
                'enabled' => true,
                'client' => new Statement(RecordingClient::class, ['[]']),
                'throwOnError' => true,
            ]],
            static function (ContainerBuilder $builder): void {
                $builder->addDefinition('applicationClient')->setFactory(RecordingClient::class);
            },
            requestFactory: true,
        );

        $this->expectException(SsrException::class);
        $container->getByType(InertiaFactoryInterface::class)
            ->fromRequest(new ServerRequest('GET', 'https://example.test/'))
            ->render('Home');
    }

    /** @param array<string, mixed> $config */
    private function createContainer(
        array $config,
        ?Closure $configure = null,
        bool $requestFactory = false,
    ): Container {
        $loader = new ContainerLoader($this->directory, true);
        /** @var class-string<Container> $containerClass */
        $containerClass = $loader->load(function (Compiler $compiler) use ($config, $configure, $requestFactory): void {
            $compiler->addExtension('inertia', new InertiaExtension());
            $compiler->addConfig(['inertia' => $config]);
            $builder = $compiler->getContainerBuilder();
            $builder->addDefinition('responseFactory')->setFactory([DiTestServices::class, 'responseFactory']);
            $builder->addDefinition('streamFactory')->setFactory([DiTestServices::class, 'streamFactory']);
            $builder->addDefinition('normalizer')->setFactory([DiTestServices::class, 'normalizer']);
            $builder->addDefinition('viewFactory')->setFactory(RenderingViewFactory::class);
            if ($requestFactory) {
                $builder->addDefinition('requestFactory')
                    ->setType(RequestFactoryInterface::class)
                    ->setFactory(Psr17Factory::class)
                    ->setAutowired([RequestFactoryInterface::class]);
            }
            if ($configure !== null) {
                $configure($builder);
            }
        }, $this->directory);
        return new $containerClass();
    }
}

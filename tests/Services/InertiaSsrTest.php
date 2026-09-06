<?php

declare(strict_types=1);

namespace Tests\Services;

use ArrayObject;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Lsr\Inertia\Services\Inertia;
use Lsr\Inertia\Services\InertiaOptions;
use Lsr\Inertia\Ssr\HttpRenderer;
use Lsr\Inertia\Ssr\SsrException;
use Lsr\Interfaces\TemplateParametersInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;
use Tests\Fixtures\InertiaViewFactory;

final class InertiaSsrTest extends TestCase
{
    public function testFallbackSafelyEmbedsNormalizedPageWithoutAnEncoder(): void {
        $inertia = $this->inertia(options: new InertiaOptions(
            rootId: 'custom"root',
            normalizationContext: [DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'],
            jsonEncodeOptions: JSON_UNESCAPED_SLASHES,
        ));
        $calls = 0;
        $response = $inertia->render('Index', [
            'created' => new DateTimeImmutable('2026-09-06T12:00:00+00:00'),
            'message' => '</script><script>alert("injected")</script>',
            'sequence' => static function () use (&$calls): int {
                return ++$calls;
            },
            'later' => $inertia->defer(static fn() => throw new \RuntimeException('Must remain deferred.')),
        ]);
        $document = $this->document($response);
        $scripts = $document->getElementsByTagName('script');
        self::assertCount(1, $scripts);
        $script = $scripts->item(0);
        self::assertSame('custom"root', $script->getAttribute('data-page'));
        $page = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-06', $page['props']['created']);
        self::assertSame('</script><script>alert("injected")</script>', $page['props']['message']);
        self::assertSame(1, $page['props']['sequence']);
        self::assertSame(1, $calls);
        self::assertArrayNotHasKey('later', $page['props']);
        self::assertSame(['default' => ['later']], $page['deferredProps']);
        self::assertNotNull($document->getElementById('custom"root'));
        self::assertSame('X-Inertia', $response->getHeaderLine('Vary'));
    }

    public function testRendererHeadAndCompleteBodyAppearOnceInTheDocument(): void {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->willReturn($this->rendererResponse());
        $response = $this->inertia($client)->render('Index', ['message' => 'Rendered']);
        $document = $this->document($response);
        $xpath = new DOMXPath($document);
        self::assertSame('Rendered title', $document->getElementsByTagName('title')->item(0)->textContent);
        self::assertSame('Rendered content', $document->getElementsByTagName('h1')->item(0)->textContent);
        self::assertSame(1, $xpath->query('//*[@id="app"]')->length);
        self::assertSame(1, $xpath->query('//script[@data-page="app"]')->length);
        self::assertSame('true', $document->getElementById('app')->getAttribute('data-server-rendered'));
    }

    public function testFailureFallsBackAndDoesNotLeakToTheNextRequest(): void {
        $factory = new Psr17Factory();
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::exactly(2))->method('sendRequest')->willReturn(
            $factory->createResponse(500),
            $this->rendererResponse(),
        );
        $renderer = new HttpRenderer($client, $factory, $factory);
        $first = $this->inertia(renderer: $renderer)->render('Index', ['message' => 'First visitor']);
        $second = $this->inertia(renderer: $renderer)->render('Index', ['message' => 'Second visitor']);
        $fallback = $this->document($first);
        self::assertFalse($fallback->getElementById('app')->hasAttribute('data-server-rendered'));
        self::assertSame('First visitor', $this->page($first)['props']['message']);
        $heading = $this->document($second)->getElementsByTagName('h1')->item(0);
        self::assertSame('Rendered content', $heading->textContent);
    }

    public function testStrictModePropagatesRendererFailure(): void {
        $factory = new Psr17Factory();
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($factory->createResponse(500));
        $this->expectException(SsrException::class);
        $this->inertia($client, options: new InertiaOptions(throwOnSsrError: true))->render('Index');
    }

    #[DataProvider('bypassedRequests')]
    public function testNonEligibleRequestsNeverContactTheRenderer(string $method, bool $xhr, bool $enabled): void {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'https://example.test/');
        if ($xhr) {
            $request = $request->withHeader('X-Inertia', 'true');
        }
        $request = $request->withAttribute(Inertia::SSR_ENABLED_ATTRIBUTE, $enabled);
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('sendRequest');
        $response = $this->inertia($client, request: $request)->render('Index', ['message' => 'Available']);
        if ($xhr) {
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            $page = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Available', $page['props']['message']);
        } else {
            self::assertSame('Available', $this->page($response)['props']['message']);
        }
    }

    public static function bypassedRequests(): iterable {
        yield 'Inertia navigation' => ['GET', true, true];
        yield 'HEAD' => ['HEAD', false, true];
        yield 'POST' => ['POST', false, true];
        yield 'request opt-out' => ['GET', false, false];
    }

    public function testGeneratedTemplateDataNeverBecomesPropsWhenParametersAreReused(): void {
        $parameters = new class (['message' => 'Visitor']) extends ArrayObject implements TemplateParametersInterface {
            public function getProps(): array {
                return $this->getArrayCopy();
            }

            public function jsonSerialize(): array {
                return $this->getArrayCopy();
            }
        };
        $inertia = $this->inertia();
        $inertia->render('Index', $parameters);
        $response = $inertia->render('Index', $parameters);
        self::assertSame(['message' => 'Visitor'], $this->page($response)['props']);
    }

    public function testNormalizationErrorsDoNotBecomeSuccessfulFallbackResponses(): void {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('sendRequest');
        $this->expectException(\Symfony\Component\Serializer\Exception\NotNormalizableValueException::class);
        $this->inertia($client)->render('Index', ['unsupported' => new \stdClass()]);
    }

    private function inertia(
        ?ClientInterface $client = null,
        ?ServerRequestInterface $request = null,
        InertiaOptions $options = new InertiaOptions(),
        ?HttpRenderer $renderer = null,
    ): Inertia {
        $factory = new Psr17Factory();
        return new Inertia(
            $request ?? $factory->createServerRequest('GET', 'https://example.test/'),
            $factory,
            $factory,
            new InertiaViewFactory(),
            new Serializer([new DateTimeNormalizer()]),
            $renderer ?? ($client === null ? null : new HttpRenderer($client, $factory, $factory)),
            $options,
        );
    }

    private function rendererResponse(): ResponseInterface {
        $factory = new Psr17Factory();
        return $factory->createResponse()->withBody($factory->createStream(json_encode([
            'head' => ['<title data-inertia="">Rendered title</title>'],
            'body' => '<script data-page="app" type="application/json">'
                . '{"component":"Index","props":{},"url":"/","version":null}</script>'
                . '<div data-server-rendered="true" id="app"><h1>Rendered content</h1></div>',
        ], JSON_THROW_ON_ERROR)));
    }

    private function document(ResponseInterface $response): DOMDocument {
        $document = new DOMDocument();
        $document->loadHTML((string) $response->getBody());
        return $document;
    }

    private function page(ResponseInterface $response): array {
        $document = $this->document($response);
        $script = $document->getElementsByTagName('script')->item(0);
        return json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Ssr;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Lsr\Inertia\Ssr\HttpRenderer;
use Lsr\Inertia\Ssr\SsrException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class HttpRendererTest extends TestCase
{
    private Psr17Factory $psr17Factory;

    protected function setUp(): void {
        $this->psr17Factory = new Psr17Factory();
    }

    public function testRendersUsingExactConfiguredEndpointAndEncodedPage(): void {
        $pageJson = '{"component":"Home","props":{"ratio":1.0},"url":"/"}';
        $url = 'https://ssr.example.test:13714/dev/render?mode=development';
        $head = ['<title>Home</title>', '<meta name="description" content="Hello">'];
        $body = '<div id="app"><h1>Home</h1></div>';
        $response = $this->psr17Factory->createResponse()->withBody(
            $this->psr17Factory->createStream(json_encode(['head' => $head, 'body' => $body], JSON_THROW_ON_ERROR)),
        );
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->with(self::callback(
            static function (RequestInterface $request) use ($url, $pageJson): bool {
                self::assertSame('POST', $request->getMethod());
                self::assertSame($url, (string) $request->getUri());
                self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
                self::assertSame('application/json', $request->getHeaderLine('Accept'));
                self::assertSame($pageJson, (string) $request->getBody());
                return true;
            },
        ))->willReturn($response);

        $page = $this->createRenderer($client, $url)->render($pageJson);

        self::assertNotNull($page);
        self::assertSame($head, $page->head);
        self::assertSame($body, $page->body);
    }

    public function testAcceptsEmptyHead(): void {
        $page = $this->rendererRespondingWith(200, '{"head":[],"body":"<div id=app></div>"}')->render('{}');

        self::assertNotNull($page);
        self::assertSame([], $page->head);
        self::assertSame('<div id=app></div>', $page->body);
    }

    public function testSuccessfulJsonNullSignalsWarmup(): void {
        self::assertNull($this->rendererRespondingWith(200, 'null')->render('{}'));
    }

    #[DataProvider('invalidResponseProvider')]
    public function testRejectsUnsuccessfulOrInvalidResponses(int $status, string $body): void {
        $renderer = $this->rendererRespondingWith($status, $body);

        $this->expectException(SsrException::class);

        $renderer->render('{}');
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function invalidResponseProvider(): iterable {
        yield 'wrong endpoint response' => [200, '{"status":"OK","timestamp":123}'];
        yield 'server error with plausible page' => [500, '{"head":[],"body":"<div>Error</div>"}'];
        yield 'unsuccessful null is not warmup' => [503, 'null'];
        yield 'redirect is not a render' => [302, '{"head":[],"body":"<div>Home</div>"}'];
        yield 'empty success is not warmup' => [204, ''];
        yield 'array is not a rendered page object' => [200, '[[],"<div>Home</div>"]'];
        yield 'scalar is not a rendered page object' => [200, 'false'];
        yield 'missing head' => [200, '{"body":"<div>Home</div>"}'];
        yield 'missing body' => [200, '{"head":[]}'];
        yield 'head must be a list not an empty object' => [200, '{"head":{},"body":"<div>Home</div>"}'];
        yield 'numeric head object' => [200, '{"head":{"0":"<title>Home</title>"},"body":"<div>Home</div>"}'];
        yield 'head entries must be strings' => [200, '{"head":["<title>Home</title>",null],"body":"<div>Home</div>"}'];
        yield 'empty body cannot hydrate' => [200, '{"head":[],"body":""}'];
        yield 'body must be a string' => [200, '{"head":[],"body":123}'];
    }

    public function testMalformedJsonPreservesDecodeFailureWithoutExposingResponse(): void {
        $renderer = $this->rendererRespondingWith(200, '<html>private renderer diagnostics</html>');

        try {
            $renderer->render('{}');
            self::fail('Expected malformed JSON to be rejected.');
        } catch (SsrException $exception) {
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());
            self::assertStringNotContainsString('private renderer diagnostics', $exception->getMessage());
        }
    }

    public function testNetworkFailureIsWrappedWithoutRetryOrExposingPrivateDetails(): void {
        $message = 'private endpoint and request data';
        $failure = new class ($message) extends RuntimeException implements ClientExceptionInterface {
        };
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->willThrowException($failure);

        try {
            $this->createRenderer($client)->render('{}');
            self::fail('Expected the network failure to be wrapped.');
        } catch (SsrException $exception) {
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringNotContainsString($message, $exception->getMessage());
        }
    }

    public function testProgrammingErrorsPropagateUnchanged(): void {
        $failure = new LogicException('Client implementation bug');
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->willThrowException($failure);

        try {
            $this->createRenderer($client)->render('{}');
            self::fail('Expected the programming error to propagate.');
        } catch (LogicException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    #[DataProvider('invalidUrlProvider')]
    public function testRejectsInvalidConfiguredEndpointsBeforeSending(string $url): void {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('sendRequest');

        $this->expectException(InvalidArgumentException::class);

        $this->createRenderer($client, $url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrlProvider(): iterable {
        yield 'unsupported scheme' => ['file:///render'];
        yield 'missing host' => ['http:///render'];
        yield 'relative endpoint' => ['/render'];
        yield 'invalid host' => ['http://invalid host/render'];
        yield 'userinfo' => ['http://user:password@localhost:13714/render'];
        yield 'username without password' => ['http://user@localhost:13714/render'];
        yield 'fragment' => ['http://localhost:13714/render#ignored'];
    }

    private function rendererRespondingWith(int $status, string $body): HttpRenderer {
        $response = $this->psr17Factory->createResponse($status)
            ->withBody($this->psr17Factory->createStream($body));
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->willReturn($response);
        return $this->createRenderer($client);
    }

    private function createRenderer(
        ClientInterface $client,
        string $url = 'http://127.0.0.1:13714/render',
    ): HttpRenderer {
        return new HttpRenderer($client, $this->psr17Factory, $this->psr17Factory, $url);
    }
}

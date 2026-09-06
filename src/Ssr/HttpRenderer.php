<?php

declare(strict_types=1);

namespace Lsr\Inertia\Ssr;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use stdClass;

final readonly class HttpRenderer
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private string $url = 'http://127.0.0.1:13714/render',
    ) {
        $parts = parse_url($url);
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || $parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['pass'])
            || isset($parts['user'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Use an HTTP(S) SSR URL without userinfo or fragment.');
        }
    }

    public function render(string $pageJson): ?RenderedPage {
        $request = $this->requestFactory->createRequest('POST', $this->url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($pageJson));

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new SsrException('The SSR request failed.', 0, $exception);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new SsrException('The SSR endpoint returned an unsuccessful HTTP status.');
        }

        $responseJson = (string) $response->getBody();
        try {
            $page = json_decode($responseJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SsrException('The SSR endpoint returned malformed JSON.', 0, $exception);
        }

        if ($page === null) {
            return null;
        }

        if (
            !$page instanceof stdClass
            || !isset($page->head, $page->body)
            || !is_array($page->head)
            || !array_is_list($page->head)
            || !is_string($page->body)
            || $page->body === ''
        ) {
            throw new SsrException('The SSR endpoint returned an invalid rendered page.');
        }

        foreach ($page->head as $entry) {
            if (!is_string($entry)) {
                throw new SsrException('The SSR endpoint returned an invalid rendered page.');
            }
        }

        /** @var list<string> $head */
        $head = $page->head;
        return new RenderedPage($head, $page->body);
    }
}

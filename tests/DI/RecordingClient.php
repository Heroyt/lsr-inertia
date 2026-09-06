<?php

declare(strict_types=1);

namespace Tests\DI;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(
        private readonly string $responseBody = '{"head":["<title>Dedicated SSR</title>"],'
        . '"body":"<main>Rendered by dedicated client</main>"}',
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface {
        $this->requests[] = $request;
        return new Response(200, ['Content-Type' => 'application/json'], $this->responseBody);
    }
}

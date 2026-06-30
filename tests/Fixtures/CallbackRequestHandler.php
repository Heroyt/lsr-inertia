<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CallbackRequestHandler implements RequestHandlerInterface
{
    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $callback
     */
    public function __construct(
        private readonly Closure $callback,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface {
        return ($this->callback)($request);
    }
}

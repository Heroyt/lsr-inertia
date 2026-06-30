<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Inertia\Services\Inertia;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;

final readonly class InertiaFactoryStub implements InertiaFactoryInterface
{
    public function __construct(
        private Psr17Factory $psr17Factory,
    ) {
    }

    public function fromRequest(ServerRequestInterface $request): Inertia {
        return new Inertia(
            $request,
            $this->psr17Factory,
            $this->psr17Factory,
            new StringViewFactory(),
            new Serializer([], [new JsonEncoder()]),
        );
    }
}

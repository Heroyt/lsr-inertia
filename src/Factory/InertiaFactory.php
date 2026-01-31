<?php
declare(strict_types=1);

namespace Lsr\Inertia\Factory;

use Lsr\Inertia\Services\Inertia;
use Lsr\Interfaces\ViewFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Serializer\SerializerInterface;

readonly class InertiaFactory implements InertiaFactoryInterface
{

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface   $streamFactory,
        private ViewFactoryInterface     $viewFactory,
        private SerializerInterface      $serializer,
    )
    {
    }

    public function fromRequest(ServerRequestInterface $request): Inertia
    {
        return new Inertia(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            $this->viewFactory,
            $this->serializer,
        );
    }
}
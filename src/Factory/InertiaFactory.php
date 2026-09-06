<?php

declare(strict_types=1);

namespace Lsr\Inertia\Factory;

use Lsr\Inertia\Services\Inertia;
use Lsr\Inertia\Services\InertiaOptions;
use Lsr\Inertia\Ssr\HttpRenderer;
use Lsr\Interfaces\ViewFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

readonly class InertiaFactory implements InertiaFactoryInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface   $streamFactory,
        private ViewFactoryInterface     $viewFactory,
        private NormalizerInterface      $normalizer,
        private ?HttpRenderer            $ssr = null,
        private InertiaOptions           $options = new InertiaOptions(),
    ) {
    }

    public function fromRequest(ServerRequestInterface $request): Inertia {
        return new Inertia(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            $this->viewFactory,
            $this->normalizer,
            $this->ssr,
            $this->options,
        );
    }
}

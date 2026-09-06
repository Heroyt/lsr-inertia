<?php

declare(strict_types=1);

namespace Lsr\Inertia\Ssr;

final readonly class RenderedPage
{
    /**
     * @param list<string> $head
     */
    public function __construct(
        public array $head,
        public string $body,
    ) {
    }
}

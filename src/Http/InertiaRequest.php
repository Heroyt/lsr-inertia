<?php

declare(strict_types=1);

namespace Lsr\Inertia\Http;

use Psr\Http\Message\ServerRequestInterface;

final readonly class InertiaRequest
{
    public function __construct(
        private ServerRequestInterface $request,
    ) {
    }

    public function isPartialFor(string $component): bool {
        return $this->request->getHeaderLine('X-Inertia-Partial-Component') === $component
            && ($this->hasOnly() || $this->hasExcept());
    }

    public function hasOnly(): bool {
        return $this->request->hasHeader('X-Inertia-Partial-Data');
    }

    public function hasExcept(): bool {
        return $this->request->hasHeader('X-Inertia-Partial-Except');
    }

    /**
     * @return string[]
     */
    public function only(): array {
        return $this->getHeaderValues('X-Inertia-Partial-Data');
    }

    /**
     * @return string[]
     */
    public function except(): array {
        return $this->getHeaderValues('X-Inertia-Partial-Except');
    }

    /**
     * @return string[]
     */
    private function getHeaderValues(string $header): array {
        return array_values(
            array_filter(
                array_map('trim', explode(',', $this->request->getHeaderLine($header))),
                static fn(string $value): bool => $value !== '',
            ),
        );
    }
}

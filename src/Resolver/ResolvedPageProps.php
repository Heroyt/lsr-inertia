<?php

declare(strict_types=1);

namespace Lsr\Inertia\Resolver;

final readonly class ResolvedPageProps
{
    /**
     * @param array<string, mixed> $props
     * @param array<non-empty-string, list<string>> $deferredProps
     * @param list<string> $rescuedProps
     */
    public function __construct(
        public array $props,
        public array $deferredProps = [],
        public array $rescuedProps = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageMetadata(): array {
        $metadata = [];

        if ($this->deferredProps !== []) {
            $metadata['deferredProps'] = $this->deferredProps;
        }

        if ($this->rescuedProps !== []) {
            $metadata['rescuedProps'] = $this->rescuedProps;
        }

        return $metadata;
    }
}

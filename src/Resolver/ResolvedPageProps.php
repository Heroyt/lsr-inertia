<?php

declare(strict_types=1);

namespace Lsr\Inertia\Resolver;

final readonly class ResolvedPageProps
{
    /**
     * @param array<string, mixed> $props
     * @param array<non-empty-string, list<string>> $deferredProps
     * @param list<string> $rescuedProps
     * @param list<string> $mergeProps
     * @param list<string> $prependProps
     * @param list<string> $deepMergeProps
     * @param list<string> $matchPropsOn
     */
    public function __construct(
        public array $props,
        public array $deferredProps = [],
        public array $rescuedProps = [],
        public array $mergeProps = [],
        public array $prependProps = [],
        public array $deepMergeProps = [],
        public array $matchPropsOn = [],
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

        if ($this->mergeProps !== []) {
            $metadata['mergeProps'] = $this->mergeProps;
        }

        if ($this->prependProps !== []) {
            $metadata['prependProps'] = $this->prependProps;
        }

        if ($this->deepMergeProps !== []) {
            $metadata['deepMergeProps'] = $this->deepMergeProps;
        }

        if ($this->matchPropsOn !== []) {
            $metadata['matchPropsOn'] = $this->matchPropsOn;
        }

        return $metadata;
    }
}

<?php

declare(strict_types=1);

namespace Lsr\Inertia\Services;

use InvalidArgumentException;

final readonly class InertiaOptions
{
    public const int DEFAULT_JSON_OPTIONS = JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<string, mixed> $normalizationContext
     */
    public function __construct(
        public string $rootId = 'app',
        public array $normalizationContext = [],
        public int $jsonEncodeOptions = self::DEFAULT_JSON_OPTIONS,
        public bool $throwOnSsrError = false,
    ) {
        if ($this->rootId === '' || preg_match('/[\s\x00]/', $this->rootId) === 1) {
            throw new InvalidArgumentException('The Inertia root ID must be nonempty and contain no whitespace.');
        }
        if (($this->jsonEncodeOptions & (JSON_FORCE_OBJECT | JSON_PARTIAL_OUTPUT_ON_ERROR)) !== 0) {
            throw new InvalidArgumentException('Inertia JSON cannot force objects or allow partial output.');
        }
    }
}

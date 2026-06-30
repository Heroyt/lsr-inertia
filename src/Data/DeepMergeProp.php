<?php

declare(strict_types=1);

namespace Lsr\Inertia\Data;

final class DeepMergeProp implements InertiaPropInterface
{
    /** @var list<string> */
    private array $matchOn = [];

    public function __construct(
        private readonly mixed $value,
    ) {
    }

    /**
     * @param string|list<string> $paths
     */
    public function matchOn(string|array $paths): self {
        foreach ((array) $paths as $path) {
            $this->matchOn[] = $path;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMatchOn(): array {
        return $this->matchOn;
    }

    public function resolve(): mixed {
        return is_callable($this->value) ? call_user_func($this->value) : $this->value;
    }
}

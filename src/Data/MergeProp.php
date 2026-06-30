<?php

declare(strict_types=1);

namespace Lsr\Inertia\Data;

final class MergeProp implements InertiaPropInterface
{
    /** @var list<string|null> */
    private array $appendPaths = [null];

    /** @var list<string|null> */
    private array $prependPaths = [];

    /** @var list<string> */
    private array $matchOn = [];

    private bool $customized = false;

    public function __construct(
        private readonly mixed $value,
    ) {
    }

    /**
     * @param string|array<int|string, string>|null $path
     */
    public function append(string|array|null $path = null, ?string $matchOn = null): self {
        $this->configure($this->appendPaths, $path, $matchOn);

        return $this;
    }

    /**
     * @param string|array<int|string, string>|null $path
     */
    public function prepend(string|array|null $path = null, ?string $matchOn = null): self {
        $this->configure($this->prependPaths, $path, $matchOn);

        return $this;
    }

    /**
     * @return list<string|null>
     */
    public function getAppendPaths(): array {
        return $this->appendPaths;
    }

    /**
     * @return list<string|null>
     */
    public function getPrependPaths(): array {
        return $this->prependPaths;
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

    /**
     * @param list<string|null> $target
     * @param string|array<int|string, string>|null $path
     */
    private function configure(array &$target, string|array|null $path, ?string $matchOn): void {
        if (!$this->customized) {
            $this->appendPaths = [];
            $this->customized = true;
        }

        if (is_array($path)) {
            foreach ($path as $propPath => $propMatchOn) {
                if (is_string($propPath)) {
                    $target[] = $propPath;
                    $this->matchOn[] = $propPath . '.' . $propMatchOn;
                    continue;
                }

                $target[] = $propMatchOn;
            }
            return;
        }

        $target[] = $path;
        if ($matchOn !== null) {
            $this->matchOn[] = ($path !== null ? $path . '.' : '') . $matchOn;
        }
    }
}

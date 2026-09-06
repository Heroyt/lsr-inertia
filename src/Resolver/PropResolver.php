<?php

declare(strict_types=1);

namespace Lsr\Inertia\Resolver;

use Closure;
use Lsr\Inertia\Data\AlwaysProp;
use Lsr\Inertia\Data\DeepMergeProp;
use Lsr\Inertia\Data\DeferredProp;
use Lsr\Inertia\Data\InertiaPropInterface;
use Lsr\Inertia\Data\LazyProp;
use Lsr\Inertia\Data\MergeProp;
use Lsr\Inertia\Data\OnceProp;
use Lsr\Inertia\Http\InertiaRequest;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class PropResolver
{
    public function __construct(
        private ServerRequestInterface $request,
    ) {
    }

    /**
     * @param array<string, mixed> $props
     */
    public function resolve(string $component, array $props): ResolvedPageProps {
        $props = $this->filterFrameworkProps($props);
        $request = new InertiaRequest($this->request);
        $isPartial = $request->isPartialFor($component);
        $only = array_flip($request->only());
        $except = array_flip($request->except());

        $resolved = [];
        $deferred = [];
        $rescued = [];
        $merge = [];
        $prepend = [];
        $deepMerge = [];
        $matchOn = [];
        $once = [];
        $exceptOnce = array_flip($request->exceptOnceProps());

        foreach ($props as $key => $prop) {
            if ($prop instanceof AlwaysProp) {
                $resolved[$key] = $this->resolveValue($prop);
                continue;
            }

            if ($isPartial && !$this->shouldIncludePartialProp($key, $only, $except, $request)) {
                continue;
            }

            if (!$isPartial && $prop instanceof LazyProp) {
                continue;
            }

            if (!$isPartial && $prop instanceof DeferredProp) {
                $deferred[$prop->getGroup()][] = $key;
                continue;
            }

            if ($prop instanceof DeferredProp && $prop->shouldRescue()) {
                try {
                    $resolved[$key] = $this->resolveValue($prop);
                } catch (Throwable) {
                    $rescued[] = $key;
                }
                continue;
            }

            if ($prop instanceof OnceProp) {
                $onceKey = $prop->getKey($key);
                $once[$onceKey] = [
                    'prop' => $key,
                    'expiresAt' => $prop->getExpiresAt(),
                ];

                if (
                    !$this->isExplicitlyRequested($key, $only, $request)
                    && isset($exceptOnce[$onceKey])
                    && !$prop->shouldFresh()
                    && !$prop->isExpired()
                ) {
                    continue;
                }
            }

            if ($prop instanceof MergeProp) {
                array_push($merge, ...$this->prefixPaths($key, $prop->getAppendPaths()));
                array_push($prepend, ...$this->prefixPaths($key, $prop->getPrependPaths()));
                array_push($matchOn, ...$this->prefixStringPaths($key, $prop->getMatchOn()));
            }

            if ($prop instanceof DeepMergeProp) {
                $deepMerge[] = $key;
                array_push($matchOn, ...$this->prefixStringPaths($key, $prop->getMatchOn()));
            }

            $resolved[$key] = $this->resolveValue($prop);
        }

        return new ResolvedPageProps($resolved, $deferred, $rescued, $merge, $prepend, $deepMerge, $matchOn, $once);
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    private function filterFrameworkProps(array $props): array {
        unset(
            $props['page'],
            $props['app'],
            $props['request'],
            $props['inertiaPage'],
            $props['inertiaHead'],
            $props['inertiaBody'],
        );

        return $props;
    }

    /**
     * @param array<string, int> $only
     * @param array<string, int> $except
     */
    private function shouldIncludePartialProp(string $key, array $only, array $except, InertiaRequest $request): bool {
        if ($request->hasOnly()) {
            return isset($only[$key]);
        }

        if ($request->hasExcept()) {
            return !isset($except[$key]);
        }

        return true;
    }

    /**
     * @param array<string, int> $only
     */
    private function isExplicitlyRequested(string $key, array $only, InertiaRequest $request): bool {
        return $request->hasOnly() && isset($only[$key]);
    }

    private function resolveValue(mixed $value): mixed {
        if ($value instanceof InertiaPropInterface) {
            return $this->resolveNestedValue($value->resolve());
        }

        return $this->resolveNestedValue($value);
    }

    private function resolveNestedValue(mixed $value): mixed {
        if ($value instanceof Closure) {
            return $this->resolveNestedValue($value());
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->resolveNestedValue($nestedValue);
        }

        return $value;
    }

    /**
     * @param list<string|null> $paths
     *
     * @return list<string>
     */
    private function prefixPaths(string $key, array $paths): array {
        return array_map(
            static fn(?string $path): string => $path === null ? $key : $key . '.' . $path,
            $paths,
        );
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function prefixStringPaths(string $key, array $paths): array {
        return array_map(
            static fn(string $path): string => $key . '.' . $path,
            $paths,
        );
    }
}

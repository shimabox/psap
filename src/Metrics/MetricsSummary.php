<?php

declare(strict_types=1);

namespace Bobsap\Metrics;

/**
 * 全コンポーネントの D（主系列からの距離）値を統計的にまとめた値オブジェクト。
 */
final readonly class MetricsSummary
{
    private function __construct(
        public float $meanDistance,
        public float $varianceDistance,
    ) {
    }

    /**
     * @param list<ComponentMetrics> $metrics
     */
    public static function from(array $metrics): self
    {
        $count = count($metrics);
        if ($count === 0) {
            return new self(0.0, 0.0);
        }

        $distances = array_map(
            static fn (ComponentMetrics $metrics): float => $metrics->distance,
            $metrics,
        );

        $mean = array_sum($distances) / $count;

        // 母分散（全コンポーネントを母集団とみなす）
        $variance = array_sum(array_map(
            static fn (float $distance): float => ($distance - $mean) ** 2,
            $distances,
        )) / $count;

        return new self($mean, $variance);
    }
}

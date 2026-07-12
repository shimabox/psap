<?php

declare(strict_types=1);

namespace Bobsap\Metrics;

/**
 * 評価可能なコンポーネントのD（主系列からの距離）を統計的にまとめた値オブジェクト。
 * 評価可能なコンポーネントがない場合、平均と分散はnullになる。
 */
final readonly class MetricsSummary
{
    private function __construct(
        public ?float $meanDistance,
        public ?float $varianceDistance,
    ) {
    }

    /**
     * @param list<ComponentMetrics> $metrics
     */
    public static function from(array $metrics): self
    {
        $evaluableMetrics = array_values(array_filter(
            $metrics,
            static fn (ComponentMetrics $componentMetrics): bool => $componentMetrics->dependencyMetricsEvaluable,
        ));
        $count = count($evaluableMetrics);
        if ($count === 0) {
            return new self(null, null);
        }

        $distances = array_map(
            static fn (ComponentMetrics $metrics): float => $metrics->distance,
            $evaluableMetrics,
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

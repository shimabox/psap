<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use Psap\Component\Component;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\MetricsSummary;
use Psap\Metrics\Zone;

// MetricsSummary::from() の D 値の平均・分散（母分散）計算のテスト。
final class MetricsSummaryTest extends TestCase
{
    public function testMeanAndVarianceAreNullForEmptyMetrics(): void
    {
        $summary = MetricsSummary::from([]);

        self::assertNull($summary->meanDistance);
        self::assertNull($summary->varianceDistance);
    }

    public function testCalculatesMeanAndVariance(): void
    {
        $metrics = [
            $this->metricsWithDistance(0.0),
            $this->metricsWithDistance(0.5),
            $this->metricsWithDistance(1.0),
        ];

        $summary = MetricsSummary::from($metrics);

        // 平均 = (0.0 + 0.5 + 1.0) / 3 = 0.5
        self::assertEqualsWithDelta(0.5, $summary->meanDistance, 0.0001);
        // 母分散 = ((0.5)^2 + 0^2 + (0.5)^2) / 3 = 0.5 / 3
        self::assertEqualsWithDelta(0.5 / 3, $summary->varianceDistance, 0.0001);
    }

    public function testExcludesMetricsThatCannotEvaluateComponentDependencies(): void
    {
        $summary = MetricsSummary::from([$this->metricsWithDistance(0.75, evaluable: false)]);

        self::assertNull($summary->meanDistance);
        self::assertNull($summary->varianceDistance);
    }

    private function metricsWithDistance(float $distance, bool $evaluable = true): ComponentMetrics
    {
        $component = new Component('App\\Dummy', []);

        return new ComponentMetrics(
            component: $component,
            ca: 0,
            ce: 0,
            instability: 0.0,
            abstractness: 0.0,
            distance: $distance,
            zone: Zone::None,
            dependencyMetricsEvaluable: $evaluable,
        );
    }
}

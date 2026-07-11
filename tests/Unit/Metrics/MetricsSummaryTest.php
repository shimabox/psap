<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Metrics;

use Bobsap\Component\Component;
use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\MetricsSummary;
use Bobsap\Metrics\Zone;
use PHPUnit\Framework\TestCase;

// MetricsSummary::from() の D 値の平均・分散（母分散）計算のテスト。
final class MetricsSummaryTest extends TestCase
{
    public function testMeanAndVarianceAreZeroForEmptyMetrics(): void
    {
        $summary = MetricsSummary::from([]);

        self::assertSame(0.0, $summary->meanDistance);
        self::assertSame(0.0, $summary->varianceDistance);
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

    private function metricsWithDistance(float $distance): ComponentMetrics
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
        );
    }
}

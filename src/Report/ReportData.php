<?php

declare(strict_types=1);

namespace Bobsap\Report;

use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\MetricsSummary;

/**
 * レンダラー（Reporter）共通の入力データ。
 *
 * ComponentMetrics 経由で Component / ClassInfo までたどれるため、
 * Phase 4 の依存グラフ描画（Mermaid / PlantUML）もこの型をそのまま入力にできる。
 */
final readonly class ReportData
{
    /**
     * @param list<ComponentMetrics> $componentMetrics
     * @param list<string> $warnings Analyzer が発したパース警告等
     */
    public function __construct(
        public array $componentMetrics,
        public MetricsSummary $summary,
        public array $warnings,
    ) {
    }
}

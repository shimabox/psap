<?php

declare(strict_types=1);

namespace Bobsap\Report;

use Bobsap\Component\Component;
use Bobsap\Component\DependencyGraph;
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
    public DependencyGraph $dependencyGraph;

    /**
     * @param list<ComponentMetrics> $componentMetrics
     * @param list<string> $warnings Analyzer が発したパース警告等
     * @param list<list<string>> $cycles 循環依存（ADP違反）。各要素は循環しているコンポーネント名のリスト
     */
    public function __construct(
        public array $componentMetrics,
        public MetricsSummary $summary,
        public array $warnings,
        public array $cycles = [],
        ?DependencyGraph $dependencyGraph = null,
    ) {
        $components = array_map(
            static fn (ComponentMetrics $metrics): Component => $metrics->component,
            $componentMetrics,
        );
        $this->dependencyGraph = $dependencyGraph ?? DependencyGraph::fromComponents($components);
    }

    /**
     * @param list<string> $cycle
     * @return list<array{0: string, 1: string}>
     */
    public function edgesInCycle(array $cycle): array
    {
        return array_values(array_filter(
            $this->dependencyGraph->edges,
            static fn (array $edge): bool => in_array($edge[0], $cycle, true) && in_array($edge[1], $cycle, true),
        ));
    }
}

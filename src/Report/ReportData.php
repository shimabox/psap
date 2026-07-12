<?php

declare(strict_types=1);

namespace Bobsap\Report;

use Bobsap\Baseline\CycleBaselineComparison;
use Bobsap\Component\Component;
use Bobsap\Component\CyclePathFinder;
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

    /** @var list<list<string>> 始点を末尾にも含む代表循環経路 */
    public array $cyclePaths;

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
        public ?int $namespaceDepth = null,
        public ?CycleBaselineComparison $cycleBaselineComparison = null,
    ) {
        $components = array_map(
            static fn (ComponentMetrics $metrics): Component => $metrics->component,
            $componentMetrics,
        );
        $this->dependencyGraph = $dependencyGraph ?? DependencyGraph::fromComponents($components);
        $this->cyclePaths = (new CyclePathFinder())->find($this->dependencyGraph, $cycles);
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

    /**
     * @return list<array{
     *     path: list<string>,
     *     dependencies: list<array{
     *         from: string,
     *         to: string,
     *         classDependencies: list<array{from: string, to: string}>,
     *     }>,
     * }>
     */
    public function cyclePathDetails(): array
    {
        return array_map(
            static fn (array $group): array => [
                'path' => $group['representativePath'],
                'dependencies' => $group['dependencies'],
            ],
            $this->cycleGroups(),
        );
    }

    /**
     * @return list<array{
     *     components: list<string>,
     *     componentCount: int,
     *     namespaceRelation: 'hierarchical'|'peer',
     *     representativePath: list<string>,
     *     omittedComponents: list<string>,
     *     dependencies: list<array{
     *         from: string,
     *         to: string,
     *         classDependencies: list<array{from: string, to: string}>,
     *     }>,
     * }>
     */
    public function cycleGroups(): array
    {
        $groups = [];
        foreach ($this->cycles as $index => $components) {
            $path = $this->cyclePaths[$index] ?? [];
            $pathComponents = array_values(array_unique($path));
            $groups[] = [
                'components' => $components,
                'componentCount' => count($components),
                'namespaceRelation' => $this->namespaceRelation($components),
                'representativePath' => $path,
                'omittedComponents' => array_values(array_diff($components, $pathComponents)),
                'dependencies' => $this->dependenciesInPath($path),
            ];
        }

        return $groups;
    }

    /**
     * @param list<string> $cycle
     * @return 'hierarchical'|'peer'
     */
    private function namespaceRelation(array $cycle): string
    {
        foreach ($this->edgesInCycle($cycle) as [$from, $to]) {
            if ($this->isNamespaceParent($from, $to) || $this->isNamespaceParent($to, $from)) {
                return 'hierarchical';
            }
        }

        return 'peer';
    }

    private function isNamespaceParent(string $parent, string $child): bool
    {
        return str_starts_with($child, $parent . '\\');
    }

    /**
     * @param list<string> $path
     * @return list<array{
     *     from: string,
     *     to: string,
     *     classDependencies: list<array{from: string, to: string}>,
     * }>
     */
    private function dependenciesInPath(array $path): array
    {
        $detailsByEdge = [];
        foreach ($this->dependencyGraph->edgeDetails as $detail) {
            $detailsByEdge[$detail['from'] . "\0" . $detail['to']] = $detail;
        }

        $dependencies = [];
        for ($index = 0; isset($path[$index + 1]); $index++) {
            $from = $path[$index];
            $to = $path[$index + 1];
            $dependencies[] = $detailsByEdge[$from . "\0" . $to] ?? [
                'from' => $from,
                'to' => $to,
                'classDependencies' => [],
            ];
        }

        return $dependencies;
    }
}

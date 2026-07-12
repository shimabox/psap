<?php

declare(strict_types=1);

namespace Bobsap\Component;

use Bobsap\Analyzer\DependencyEvidence;

/**
 * コンポーネント間依存グラフの値オブジェクト。
 *
 * ノード = コンポーネント名、エッジ = コンポーネント間の依存（[from, to] のペア）。
 * PlantUmlReporter のエッジ描画と CycleDetector の循環検出の両方から使われる。
 */
final readonly class DependencyGraph
{
    /**
     * @param list<string> $nodes コンポーネント名一覧（ソート済み）
     * @param list<array{0: string, 1: string}> $edges [from, to] のペア一覧（ソート済み・重複なし）
     * @param list<array{from: string, to: string, classDependencies: list<array{
     *     from: string,
     *     to: string,
     *     evidence: list<array{kind: string, file: string, line: int}>
     * }>}> $edgeDetails
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $edgeDetails = [],
    ) {
    }

    /**
     * クラス単位の依存を「コンポーネント名ペア」に集約し重複排除してグラフを作る。
     * 対応表にない FQCN（解析対象外への依存）・自コンポーネント内依存は無視する。
     *
     * @param list<Component> $components
     */
    public static function fromComponents(array $components): self
    {
        $classByFqcn = self::buildClassMap($components);

        $seenPairs = [];
        $edges = [];
        /** @var array<string, array<string, array{
         *     from: string,
         *     to: string,
         *     evidence: list<array{kind: string, file: string, line: int}>
         * }>> $classDependenciesByPair */
        $classDependenciesByPair = [];
        foreach ($components as $component) {
            $fromName = $component->name;
            foreach ($component->classInfos as $classInfo) {
                foreach ($classInfo->dependencies as $dependency) {
                    $target = $classByFqcn[strtolower($dependency)] ?? null;
                    if ($target === null || $target['component'] === $fromName) {
                        continue;
                    }

                    $toName = $target['component'];
                    $pairKey = $fromName . '|' . $toName;
                    if (!isset($seenPairs[$pairKey])) {
                        $seenPairs[$pairKey] = true;
                        $edges[] = [$fromName, $toName];
                        $classDependenciesByPair[$pairKey] = [];
                    }

                    $classPairKey = strtolower($classInfo->fqcn) . '|' . strtolower($target['fqcn']);
                    $evidence = array_map(
                        static fn (DependencyEvidence $item): array => [
                            'kind' => $item->kind->value,
                            'file' => $item->file,
                            'line' => $item->line,
                        ],
                        array_values(array_filter(
                            $classInfo->dependencyEvidence,
                            static fn (DependencyEvidence $item): bool => strcasecmp(
                                $item->targetFqcn,
                                $target['fqcn'],
                            ) === 0,
                        )),
                    );
                    usort(
                        $evidence,
                        static fn (array $a, array $b): int => [
                            $a['file'],
                            $a['line'],
                            $a['kind'],
                        ] <=> [
                            $b['file'],
                            $b['line'],
                            $b['kind'],
                        ],
                    );
                    $classDependenciesByPair[$pairKey][$classPairKey] = [
                        'from' => $classInfo->fqcn,
                        'to' => $target['fqcn'],
                        'evidence' => $evidence,
                    ];
                }
            }
        }

        // コンポーネント名でソートして順序を安定させる（表示・アルゴリズムどちらの用途でも決定的にするため）
        usort($edges, static fn (array $a, array $b): int => $a <=> $b);

        $edgeDetails = [];
        foreach ($edges as [$from, $to]) {
            $classDependencies = array_values($classDependenciesByPair[$from . '|' . $to]);
            usort($classDependencies, static fn (array $a, array $b): int => $a <=> $b);
            $edgeDetails[] = [
                'from' => $from,
                'to' => $to,
                'classDependencies' => $classDependencies,
            ];
        }

        $nodes = array_map(static fn (Component $component): string => $component->name, $components);
        sort($nodes);

        return new self($nodes, $edges, $edgeDetails);
    }

    /**
     * クラスの FQCN → 所属コンポーネント名 の対応表を作る（MetricsCalculator と同じ考え方）。
     *
     * @param list<Component> $components
     * @return array<string, array{component: string, fqcn: string}>
     */
    private static function buildClassMap(array $components): array
    {
        $map = [];
        foreach ($components as $component) {
            foreach ($component->classInfos as $classInfo) {
                $map[strtolower($classInfo->fqcn)] = [
                    'component' => $component->name,
                    'fqcn' => $classInfo->fqcn,
                ];
            }
        }

        return $map;
    }
}

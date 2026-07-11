<?php

declare(strict_types=1);

namespace Bobsap\Component;

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
     */
    public function __construct(
        public array $nodes,
        public array $edges,
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
        $componentNameByFqcn = self::buildComponentNameMap($components);

        $seenPairs = [];
        $edges = [];
        foreach ($components as $component) {
            $fromName = $component->name;
            foreach ($component->classInfos as $classInfo) {
                foreach ($classInfo->dependencies as $dependency) {
                    $toName = $componentNameByFqcn[strtolower($dependency)] ?? null;
                    if ($toName === null || $toName === $fromName) {
                        continue;
                    }

                    $pairKey = $fromName . '|' . $toName;
                    if (isset($seenPairs[$pairKey])) {
                        continue;
                    }
                    $seenPairs[$pairKey] = true;

                    $edges[] = [$fromName, $toName];
                }
            }
        }

        // コンポーネント名でソートして順序を安定させる（表示・アルゴリズムどちらの用途でも決定的にするため）
        usort($edges, static fn (array $a, array $b): int => $a <=> $b);

        $nodes = array_map(static fn (Component $component): string => $component->name, $components);
        sort($nodes);

        return new self($nodes, $edges);
    }

    /**
     * クラスの FQCN → 所属コンポーネント名 の対応表を作る（MetricsCalculator と同じ考え方）。
     *
     * @param list<Component> $components
     * @return array<string, string>
     */
    private static function buildComponentNameMap(array $components): array
    {
        $map = [];
        foreach ($components as $component) {
            foreach ($component->classInfos as $classInfo) {
                $map[strtolower($classInfo->fqcn)] = $component->name;
            }
        }

        return $map;
    }
}

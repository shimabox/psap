<?php

declare(strict_types=1);

namespace Psap\Component;

/**
 * DependencyGraph から循環依存（ADP違反）を検出する。
 *
 * アルゴリズム: Tarjan の強連結成分（SCC）分解（再帰版）。
 * 各ノードに発見順序 index と、そのノードから到達できる最小の index（lowlink）を割り当てていき、
 * lowlink === index になったノードが見つかった時点で、探索スタックに積まれている
 * ノードのうちそのノードまでを1つの SCC として切り出す。計算量 O(V+E)。
 *
 * サイズ2以上の SCC のみを「循環」として返す（サイズ1は自己ループのない単独ノードで、
 * Analyzer は自己参照を除去しているため循環とはみなさない）。
 */
final class CycleDetector
{
    /** @var array<string, list<string>> ノード名 → 隣接ノード名一覧 */
    private array $adjacency = [];

    /** @var array<string, int> ノード名 → 発見順序 */
    private array $index = [];

    /** @var array<string, int> ノード名 → 到達可能な最小 index */
    private array $lowlink = [];

    /** @var array<string, bool> ノード名 → 探索スタックに積まれているか */
    private array $onStack = [];

    /** @var list<string> 探索スタック */
    private array $stack = [];

    private int $nextIndex = 0;

    /** @var list<list<string>> 検出した SCC 一覧（サイズ1も含む、フィルタ前） */
    private array $sccs = [];

    /**
     * @return list<list<string>> 循環ごとのコンポーネント名リスト（各リスト内・リスト間ともソート済み）
     */
    public function detect(DependencyGraph $graph): array
    {
        $this->reset($graph);

        foreach ($graph->nodes as $node) {
            if (!isset($this->index[$node])) {
                $this->strongConnect($node);
            }
        }

        $cycles = array_values(array_filter(
            $this->sccs,
            static fn (array $scc): bool => count($scc) >= 2,
        ));

        foreach ($cycles as &$cycle) {
            sort($cycle);
        }
        unset($cycle);

        usort($cycles, static fn (array $a, array $b): int => $a <=> $b);

        return $cycles;
    }

    private function reset(DependencyGraph $graph): void
    {
        $this->adjacency = [];
        foreach ($graph->nodes as $node) {
            $this->adjacency[$node] = [];
        }
        foreach ($graph->edges as [$from, $to]) {
            $this->adjacency[$from][] = $to;
        }

        $this->index = [];
        $this->lowlink = [];
        $this->onStack = [];
        $this->stack = [];
        $this->nextIndex = 0;
        $this->sccs = [];
    }

    private function strongConnect(string $node): void
    {
        $this->index[$node] = $this->nextIndex;
        $this->lowlink[$node] = $this->nextIndex;
        $this->nextIndex++;
        $this->stack[] = $node;
        $this->onStack[$node] = true;

        foreach ($this->adjacency[$node] ?? [] as $neighbor) {
            if (!isset($this->index[$neighbor])) {
                $this->strongConnect($neighbor);
                $this->lowlink[$node] = min($this->lowlink[$node], $this->lowlink[$neighbor]);
            } elseif ($this->onStack[$neighbor] ?? false) {
                $this->lowlink[$node] = min($this->lowlink[$node], $this->index[$neighbor]);
            }
        }

        if ($this->lowlink[$node] !== $this->index[$node]) {
            return;
        }

        $scc = [];
        do {
            /** @var string $member */
            $member = array_pop($this->stack);
            $this->onStack[$member] = false;
            $scc[] = $member;
        } while ($member !== $node);

        $this->sccs[] = $scc;
    }
}

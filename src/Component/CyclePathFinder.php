<?php

declare(strict_types=1);

namespace Bobsap\Component;

/**
 * 強連結成分ごとに、実際に一周する最短の代表経路を選ぶ。
 */
final class CyclePathFinder
{
    /**
     * @param list<list<string>> $cycles
     * @return list<list<string>> 始点を末尾にも含む循環経路
     */
    public function find(DependencyGraph $graph, array $cycles): array
    {
        $adjacency = $this->buildAdjacency($graph);
        $paths = [];

        foreach ($cycles as $cycle) {
            $path = $this->shortestCycle($cycle, $adjacency);
            if ($path !== []) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return array<string, list<string>>
     */
    private function buildAdjacency(DependencyGraph $graph): array
    {
        $adjacency = array_fill_keys($graph->nodes, []);
        foreach ($graph->edges as [$from, $to]) {
            $adjacency[$from][] = $to;
        }

        foreach ($adjacency as &$neighbors) {
            sort($neighbors);
        }
        unset($neighbors);

        return $adjacency;
    }

    /**
     * @param list<string> $cycle
     * @param array<string, list<string>> $adjacency
     * @return list<string>
     */
    private function shortestCycle(array $cycle, array $adjacency): array
    {
        sort($cycle);
        $members = array_fill_keys($cycle, true);
        $best = [];

        foreach ($cycle as $start) {
            foreach ($adjacency[$start] ?? [] as $neighbor) {
                if (!isset($members[$neighbor])) {
                    continue;
                }

                $returnPath = $this->shortestPath($neighbor, $start, $adjacency, $members);
                if ($returnPath === []) {
                    continue;
                }

                $candidate = [$start, ...$returnPath];
                if ($best === [] || count($candidate) < count($best) || (count($candidate) === count($best) && $candidate < $best)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * @param array<string, list<string>> $adjacency
     * @param array<string, true> $members
     * @return list<string>
     */
    private function shortestPath(string $from, string $to, array $adjacency, array $members): array
    {
        $queue = [[$from]];
        $visited = [$from => true];

        for ($index = 0; isset($queue[$index]); $index++) {
            $path = $queue[$index];
            $current = $path[array_key_last($path)];
            if ($current === $to) {
                return $path;
            }

            foreach ($adjacency[$current] ?? [] as $neighbor) {
                if (!isset($members[$neighbor]) || isset($visited[$neighbor])) {
                    continue;
                }

                $visited[$neighbor] = true;
                $queue[] = [...$path, $neighbor];
            }
        }

        return [];
    }
}

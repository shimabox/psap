<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Component;

use Bobsap\Component\CycleDetector;
use Bobsap\Component\DependencyGraph;
use PHPUnit\Framework\TestCase;

// CycleDetector: Tarjan の強連結成分（SCC）検出による循環依存（ADP違反）検出のテスト。
// サイズ1のSCC（循環していない単独ノード）は結果に含まれないことも確認する
final class CycleDetectorTest extends TestCase
{
    public function testReturnsEmptyArrayWhenNoCycleExists(): void
    {
        $graph = new DependencyGraph(['A', 'B', 'C'], [['A', 'B'], ['B', 'C']]);

        $cycles = (new CycleDetector())->detect($graph);

        self::assertSame([], $cycles);
    }

    public function testDetectsTwoNodeMutualDependency(): void
    {
        $graph = new DependencyGraph(['A', 'B'], [['A', 'B'], ['B', 'A']]);

        $cycles = (new CycleDetector())->detect($graph);

        self::assertSame([['A', 'B']], $cycles);
    }

    public function testDetectsThreeNodeCycle(): void
    {
        $graph = new DependencyGraph(['A', 'B', 'C'], [['A', 'B'], ['B', 'C'], ['C', 'A']]);

        $cycles = (new CycleDetector())->detect($graph);

        self::assertSame([['A', 'B', 'C']], $cycles);
    }

    public function testDetectsMultipleIndependentCycles(): void
    {
        $graph = new DependencyGraph(
            ['A', 'B', 'C', 'D', 'E'],
            [['A', 'B'], ['B', 'A'], ['C', 'D'], ['D', 'E'], ['E', 'C']],
        );

        $cycles = (new CycleDetector())->detect($graph);

        self::assertSame([['A', 'B'], ['C', 'D', 'E']], $cycles);
    }

    public function testExcludesNodesNotPartOfAnyCycle(): void
    {
        // B<->C は循環だが、A と D はそれぞれ一方向にしか関与しないので循環に含まれない
        $graph = new DependencyGraph(
            ['A', 'B', 'C', 'D'],
            [['A', 'B'], ['B', 'C'], ['C', 'B'], ['C', 'D']],
        );

        $cycles = (new CycleDetector())->detect($graph);

        self::assertSame([['B', 'C']], $cycles);
    }
}

<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Psap\Component\CyclePathFinder;
use Psap\Component\DependencyGraph;

final class CyclePathFinderTest extends TestCase
{
    public function testReturnsConcreteClosedPath(): void
    {
        $graph = new DependencyGraph(
            ['A', 'B', 'C'],
            [['A', 'B'], ['B', 'C'], ['C', 'A']],
        );

        $paths = (new CyclePathFinder())->find($graph, [['A', 'B', 'C']]);

        self::assertSame([['A', 'B', 'C', 'A']], $paths);
    }

    public function testChoosesShortestPathFromBranchedStronglyConnectedComponent(): void
    {
        $graph = new DependencyGraph(
            ['A', 'B', 'C', 'D'],
            [['A', 'B'], ['A', 'C'], ['B', 'A'], ['C', 'D'], ['D', 'A']],
        );

        $paths = (new CyclePathFinder())->find($graph, [['A', 'B', 'C', 'D']]);

        self::assertSame([['A', 'B', 'A']], $paths);
    }

    public function testBreaksEqualLengthTiesLexicographically(): void
    {
        $graph = new DependencyGraph(
            ['A', 'B', 'C'],
            [['A', 'B'], ['A', 'C'], ['B', 'A'], ['C', 'A']],
        );

        $paths = (new CyclePathFinder())->find($graph, [['A', 'B', 'C']]);

        self::assertSame([['A', 'B', 'A']], $paths);
    }

    public function testReturnsOnePathForEachCycle(): void
    {
        $graph = new DependencyGraph(
            ['A', 'B', 'C', 'D', 'E'],
            [['A', 'B'], ['B', 'A'], ['C', 'D'], ['D', 'E'], ['E', 'C']],
        );

        $paths = (new CyclePathFinder())->find($graph, [['A', 'B'], ['C', 'D', 'E']]);

        self::assertSame([['A', 'B', 'A'], ['C', 'D', 'E', 'C']], $paths);
    }
}

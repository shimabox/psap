<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Component;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\TypeKind;
use Bobsap\Component\Component;
use Bobsap\Component\DependencyGraph;
use PHPUnit\Framework\TestCase;

// DependencyGraph: Component[] からのノード/エッジ導出のテスト。
// 導出ロジックはもともと PlantUmlReporter の private メソッドにあったものと同一
// （Phase 6 で共有クラスへ抽出。エッジ導出テストもここへ移設した）
final class DependencyGraphTest extends TestCase
{
    public function testNodesAreSortedByComponentName(): void
    {
        $components = [
            new Component('App\\Infra', []),
            new Component('App\\Domain', []),
        ];

        $graph = DependencyGraph::fromComponents($components);

        self::assertSame(['App\\Domain', 'App\\Infra'], $graph->nodes);
    }

    public function testAggregatesMultipleClassDependenciesIntoSingleEdge(): void
    {
        // App\Domain の2クラスがどちらも App\Infra 内のクラスに依存している
        $domain = new Component('App\\Domain', [
            new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', ['App\\Infra\\UserRepository']),
            new ClassInfo('App\\Domain\\Order', TypeKind::ConcreteClass, '/dummy.php', ['App\\Infra\\UserRepository']),
        ]);
        $infra = new Component('App\\Infra', [
            new ClassInfo('App\\Infra\\UserRepository', TypeKind::ConcreteClass, '/dummy.php', []),
        ]);

        $graph = DependencyGraph::fromComponents([$domain, $infra]);

        self::assertSame([['App\\Domain', 'App\\Infra']], $graph->edges);
        self::assertSame(
            [[
                'from' => 'App\\Domain',
                'to' => 'App\\Infra',
                'classDependencies' => [
                    ['from' => 'App\\Domain\\Order', 'to' => 'App\\Infra\\UserRepository'],
                    ['from' => 'App\\Domain\\User', 'to' => 'App\\Infra\\UserRepository'],
                ],
            ]],
            $graph->edgeDetails,
        );
    }

    public function testIgnoresDependenciesOutsideAnalyzedComponents(): void
    {
        $domain = new Component('App\\Domain', [
            new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', ['Vendor\\SomeLib\\Thing']),
        ]);

        $graph = DependencyGraph::fromComponents([$domain]);

        self::assertSame([], $graph->edges);
    }

    public function testIgnoresIntraComponentDependencies(): void
    {
        $domain = new Component('App\\Domain', [
            new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', ['App\\Domain\\Address']),
            new ClassInfo('App\\Domain\\Address', TypeKind::ConcreteClass, '/dummy.php', []),
        ]);

        $graph = DependencyGraph::fromComponents([$domain]);

        self::assertSame([], $graph->edges);
    }

    public function testEdgesAreSortedByFromThenToNameForStability(): void
    {
        $a = new Component('App\\A', [
            new ClassInfo('App\\A\\X', TypeKind::ConcreteClass, '/dummy.php', ['App\\C\\X', 'App\\B\\X']),
        ]);
        $b = new Component('App\\B', [
            new ClassInfo('App\\B\\X', TypeKind::ConcreteClass, '/dummy.php', ['App\\A\\X']),
        ]);
        $c = new Component('App\\C', [
            new ClassInfo('App\\C\\X', TypeKind::ConcreteClass, '/dummy.php', []),
        ]);

        $graph = DependencyGraph::fromComponents([$a, $b, $c]);

        self::assertSame(
            [['App\\A', 'App\\B'], ['App\\A', 'App\\C'], ['App\\B', 'App\\A']],
            $graph->edges,
        );
    }

    public function testDeduplicatesEdgesRegardlessOfDependencyOrder(): void
    {
        $a = new Component('App\\A', [
            new ClassInfo('App\\A\\X', TypeKind::ConcreteClass, '/dummy.php', ['App\\B\\X']),
            new ClassInfo('App\\A\\Y', TypeKind::ConcreteClass, '/dummy.php', ['App\\B\\X']),
        ]);
        $b = new Component('App\\B', [
            new ClassInfo('App\\B\\X', TypeKind::ConcreteClass, '/dummy.php', []),
        ]);

        $graph = DependencyGraph::fromComponents([$a, $b]);

        self::assertSame([['App\\A', 'App\\B']], $graph->edges);
    }
}

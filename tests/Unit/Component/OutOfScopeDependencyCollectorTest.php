<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Psap\Analyzer\ClassInfo;
use Psap\Analyzer\DependencyEvidence;
use Psap\Analyzer\DependencyKind;
use Psap\Analyzer\TypeKind;
use Psap\Component\Component;
use Psap\Component\OutOfScopeDependencyCollector;
use Psap\Component\OutOfScopeDependencyComponent;
use Psap\Component\OutOfScopeDependencyEvidence;
use Psap\Component\OutOfScopeDependencyGroup;
use Psap\Report\ReportData;

// OutOfScopeDependencyCollector: ビルトイン除外・名前空間グルーピング・決定的ソート・
// 証拠なし依存の保持・全体 distinct 件数のテスト
final class OutOfScopeDependencyCollectorTest extends TestCase
{
    public function testCollectsDependenciesThatAreNotPartOfTheAnalyzedSet(): void
    {
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', [
                    'App\\Domain\\Order',
                    'Vendor\\Support\\Facades\\DB',
                ], [
                    new DependencyEvidence('Vendor\\Support\\Facades\\DB', DependencyKind::StaticCall, 'Http/Controller.php', 12),
                ]),
            ]),
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', []),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(1, $report->dependencyCount());
        self::assertSame(1, $report->groupCount());
        self::assertSame('Vendor\\Support\\Facades', $report->groups[0]->namespace);
        self::assertSame(['Vendor\\Support\\Facades\\DB'], $report->groups[0]->targetFqcns);
        self::assertSame(['App\\Http\\Controller'], $report->groups[0]->sourceFqcns);
        self::assertCount(1, $report->components);
        self::assertSame('App\\Http', $report->components[0]->name);
    }

    public function testExcludesPhpBuiltInTypes(): void
    {
        $components = [
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', [
                    'Exception',
                    'RuntimeException',
                    'Countable',
                    'ArrayAccess',
                    'DateTimeImmutable',
                    'Attribute',
                    'Vendor\\Support\\Facades\\DB',
                ]),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(1, $report->dependencyCount());
        self::assertSame(['Vendor\\Support\\Facades\\DB'], $report->groups[0]->targetFqcns);
    }

    public function testDoesNotTriggerAutoloadWhileDetectingBuiltInTypes(): void
    {
        $components = [
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', [
                    'Exception',
                    'Vendor\\Support\\Facades\\DB',
                    'Psap\\Report\\ReportData',
                ]),
            ]),
        ];

        // 集計に必要な psap 自身のクラスを先に読み込ませ、
        // 検知対象が「解析対象外 FQCN の判定による autoload」だけになるようにする
        (new OutOfScopeDependencyCollector())->collect($components);

        $requested = [];
        $spy = static function (string $class) use (&$requested): void {
            $requested[] = $class;
        };
        spl_autoload_register($spy);

        try {
            // キャッシュを効かせないように毎回新しい Collector で判定させる
            (new OutOfScopeDependencyCollector())->collect($components);
        } finally {
            spl_autoload_unregister($spy);
        }

        self::assertSame([], $requested);
    }

    public function testKeepsLoadedUserlandClassesBecauseTheyAreNotInternal(): void
    {
        // 読み込み済みの userland クラスは isInternal() === false なので除外されない
        self::assertTrue(class_exists(ReportData::class));

        $components = [
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', ['Psap\\Report\\ReportData']),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(['Psap\\Report\\ReportData'], $report->groups[0]->targetFqcns);
    }

    public function testGroupsGlobalClassesUnderGlobalNamespace(): void
    {
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', ['GlobalFacade']),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(OutOfScopeDependencyGroup::GLOBAL_NAMESPACE, $report->groups[0]->namespace);
        self::assertSame('(global)', $report->groups[0]->namespace);
    }

    public function testKeepsDependenciesWithoutEvidence(): void
    {
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', ['Vendor\\Support\\Facades\\DB']),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(1, $report->dependencyCount());
        self::assertSame(['Vendor\\Support\\Facades\\DB'], $report->groups[0]->targetFqcns);
        self::assertSame([], $report->groups[0]->evidence);
    }

    public function testCountsProjectWideDistinctFqcnInsteadOfSummingComponents(): void
    {
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', [
                    'Vendor\\Support\\Facades\\DB',
                    'Vendor\\Support\\Facades\\Cache',
                ]),
            ]),
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', ['Vendor\\Support\\Facades\\DB']),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        // コンポーネント別は 2 + 1 = 3 件だが、全体では DB が重複するので distinct 2 件
        self::assertSame(3, array_sum(array_map(
            static fn (OutOfScopeDependencyComponent $component): int => $component->dependencyCount(),
            $report->components,
        )));
        self::assertSame(2, $report->dependencyCount());
        self::assertSame(2, $report->groups[0]->sourceCount());
    }

    public function testDoesNotDoubleCountFqcnsThatDifferOnlyInCase(): void
    {
        // PHP のクラス名は大文字小文字を区別しないため、クラス名・名前空間どちらの
        // 表記ゆれも同一の依存として1グループ・1件に集計する
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', [
                    'Vendor\\Support\\Facades\\DB',
                    'Vendor\\Support\\Facades\\db',
                    'vendor\\support\\facades\\DB',
                    'VENDOR\\SUPPORT\\FACADES\\Db',
                ]),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(1, $report->groupCount());
        self::assertSame(1, $report->dependencyCount());
        // 表示は最初に現れた表記を採用する
        self::assertSame('Vendor\\Support\\Facades', $report->groups[0]->namespace);
        self::assertSame(['Vendor\\Support\\Facades\\DB'], $report->groups[0]->targetFqcns);
        self::assertSame(1, $report->components[0]->groupCount());
        self::assertSame(1, $report->components[0]->dependencyCount());
    }

    public function testDoesNotDoubleCountAcrossComponentsWhenNamespaceCaseDiffers(): void
    {
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', ['Vendor\\Support\\Facades\\DB']),
            ]),
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', ['vendor\\support\\facades\\db']),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(1, $report->groupCount());
        self::assertSame(1, $report->dependencyCount());
        self::assertSame(2, $report->groups[0]->sourceCount());
        self::assertSame(['Vendor\\Support\\Facades\\DB'], $report->groups[0]->targetFqcns);
    }

    public function testDoesNotDoubleCountReferencingClassesThatDifferOnlyInCase(): void
    {
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', ['Vendor\\Log\\Logger'], [
                    new DependencyEvidence('Vendor\\Log\\Logger', DependencyKind::StaticCall, 'Http/Controller.php', 12),
                    // 同じ依存を別表記で拾っても証拠は重複させない
                    new DependencyEvidence('vendor\\log\\logger', DependencyKind::StaticCall, 'Http/Controller.php', 12),
                ]),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(1, $report->dependencyCount());
        self::assertSame(1, $report->groups[0]->sourceCount());
        self::assertCount(1, $report->groups[0]->evidence);
    }

    public function testEvidenceFqcnsUseTheSameSpellingAsTargetsAndSources(): void
    {
        // 表記ゆれがあっても、証拠の FQCN は targets / sources と完全一致する表記になる。
        // ここがずれると完全一致で突き合わせる機械処理から証拠が孤立する
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', ['Vendor\\Pkg\\Thing', 'vendor\\pkg\\thing'], [
                    new DependencyEvidence('Vendor\\Pkg\\Thing', DependencyKind::StaticCall, 'Http/Controller.php', 12),
                    new DependencyEvidence('vendor\\pkg\\thing', DependencyKind::New, 'Http/Controller.php', 30),
                ]),
                $this->classInfo('app\\http\\controller2', ['VENDOR\\PKG\\THING'], [
                    new DependencyEvidence('VENDOR\\PKG\\THING', DependencyKind::ClassConstant, 'Http/Controller2.php', 8),
                ]),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);
        $group = $report->groups[0];

        self::assertSame(['Vendor\\Pkg\\Thing'], $group->targetFqcns);
        self::assertSame(['App\\Http\\Controller', 'app\\http\\controller2'], $group->sourceFqcns);

        $evidenceTargets = array_values(array_unique(array_map(
            static fn (OutOfScopeDependencyEvidence $evidence): string => $evidence->targetFqcn,
            $group->evidence,
        )));
        $evidenceSources = array_values(array_unique(array_map(
            static fn (OutOfScopeDependencyEvidence $evidence): string => $evidence->sourceFqcn,
            $group->evidence,
        )));
        sort($evidenceTargets);
        sort($evidenceSources);

        self::assertSame(['Vendor\\Pkg\\Thing'], $evidenceTargets);
        self::assertSame(['App\\Http\\Controller', 'app\\http\\controller2'], $evidenceSources);
        self::assertSame([], array_diff($evidenceTargets, $group->targetFqcns));
        self::assertSame([], array_diff($evidenceSources, $group->sourceFqcns));
    }

    public function testEvidenceFqcnsAreCanonicalizedInComponentGroupsToo(): void
    {
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', ['Vendor\\Pkg\\Thing'], [
                    new DependencyEvidence('Vendor\\Pkg\\Thing', DependencyKind::StaticCall, 'Http/Controller.php', 12),
                ]),
            ]),
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', ['vendor\\pkg\\thing'], [
                    new DependencyEvidence('vendor\\pkg\\thing', DependencyKind::New, 'Domain/Order.php', 20),
                ]),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        // 全体グループでは App\Http が先に現れるので Vendor\Pkg\Thing が canonical
        self::assertSame(['Vendor\\Pkg\\Thing'], $report->groups[0]->targetFqcns);
        self::assertSame(
            ['Vendor\\Pkg\\Thing', 'Vendor\\Pkg\\Thing'],
            array_map(
                static fn (OutOfScopeDependencyEvidence $evidence): string => $evidence->targetFqcn,
                $report->groups[0]->evidence,
            ),
        );

        // コンポーネント別グループはそれぞれの先勝ち表記に揃う（targets と必ず一致する）
        foreach ($report->components as $component) {
            foreach ($component->groups as $group) {
                foreach ($group->evidence as $evidence) {
                    self::assertContains($evidence->targetFqcn, $group->targetFqcns);
                    self::assertContains($evidence->sourceFqcn, $group->sourceFqcns);
                }
            }
        }
    }

    public function testSortsGroupsByCountDescendingThenNamespaceAscending(): void
    {
        $components = [
            $this->component('App\\Http', [
                $this->classInfo('App\\Http\\Controller', [
                    'Zeta\\One',
                    'Alpha\\One',
                    'Beta\\One',
                    'Beta\\Two',
                ]),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertSame(
            ['Beta', 'Alpha', 'Zeta'],
            array_map(static fn (OutOfScopeDependencyGroup $group): string => $group->namespace, $report->groups),
        );
    }

    public function testProducesIdenticalResultsForShuffledInput(): void
    {
        $classInfos = [
            $this->classInfo('App\\Http\\A', ['Beta\\One', 'Alpha\\One'], [
                new DependencyEvidence('Beta\\One', DependencyKind::New, 'Http/A.php', 30),
                new DependencyEvidence('Beta\\One', DependencyKind::StaticCall, 'Http/A.php', 10),
            ]),
            $this->classInfo('App\\Http\\B', ['Beta\\Two', 'Alpha\\One']),
        ];

        $first = (new OutOfScopeDependencyCollector())->collect([$this->component('App\\Http', $classInfos)]);
        $second = (new OutOfScopeDependencyCollector())->collect([
            $this->component('App\\Http', array_reverse($classInfos)),
        ]);

        self::assertEquals($first, $second);
        // 証拠は file / line / kind の順で決定的に並ぶ
        self::assertSame(10, $first->groups[0]->evidence[0]->line);
        self::assertSame(30, $first->groups[0]->evidence[1]->line);
    }

    public function testReturnsEmptyReportWhenEverythingIsInScope(): void
    {
        $components = [
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', ['App\\Domain\\Item']),
                $this->classInfo('App\\Domain\\Item', []),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertTrue($report->isEmpty());
        self::assertSame(0, $report->dependencyCount());
        self::assertSame(0, $report->groupCount());
        self::assertSame([], $report->components);
    }

    public function testMatchesInScopeClassesCaseInsensitively(): void
    {
        $components = [
            $this->component('App\\Domain', [
                $this->classInfo('App\\Domain\\Order', ['app\\domain\\item']),
                $this->classInfo('App\\Domain\\Item', []),
            ]),
        ];

        $report = (new OutOfScopeDependencyCollector())->collect($components);

        self::assertTrue($report->isEmpty());
    }

    /**
     * @param list<ClassInfo> $classInfos
     */
    private function component(string $name, array $classInfos): Component
    {
        return new Component($name, $classInfos);
    }

    /**
     * @param list<string> $dependencies
     * @param list<DependencyEvidence> $evidence
     */
    private function classInfo(string $fqcn, array $dependencies, array $evidence = []): ClassInfo
    {
        return new ClassInfo($fqcn, TypeKind::ConcreteClass, '/p/' . $fqcn . '.php', $dependencies, $evidence);
    }
}

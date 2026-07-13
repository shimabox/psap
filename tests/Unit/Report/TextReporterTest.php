<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Psap\Analyzer\ClassInfo;
use Psap\Analyzer\TypeKind;
use Psap\Baseline\CycleBaselineComparison;
use Psap\Component\Component;
use Psap\Component\DependencyGraph;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\MetricsSummary;
use Psap\Metrics\Zone;
use Psap\Report\ReportData;
use Psap\Report\TextReporter;

// TextReporter: 表形式の出力・ゾーン警告・統計行・クラス一覧・verbose のテスト
final class TextReporterTest extends TestCase
{
    public function testRendersHeaderAndTableRows(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new TextReporter())->render($data);

        self::assertStringContainsString('psap - Stable Abstractions Principle metrics', $output);
        self::assertStringContainsString('Component', $output);
        self::assertStringContainsString('Classes', $output);
        self::assertStringContainsString('Ca', $output);
        self::assertStringContainsString('Ce', $output);
        self::assertStringContainsString('App\\Domain', $output);
        // I/A/D は小数2桁
        self::assertStringContainsString('0.20', $output);
        self::assertStringContainsString('0.75', $output);
        self::assertStringContainsString('0.05', $output);
    }

    public function testMarksPainZoneWithWarning(): void
    {
        $metrics = [
            $this->metrics('App\\Legacy', ca: 6, ce: 1, instability: 0.14, abstractness: 0.0, distance: 0.86, zone: Zone::Pain),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new TextReporter())->render($data);

        self::assertStringContainsString('⚠ 苦痛ゾーン', $output);
    }

    public function testMarksUselessZoneWithWarning(): void
    {
        $metrics = [
            $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 1.0, distance: 0.9, zone: Zone::Useless),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new TextReporter())->render($data);

        self::assertStringContainsString('⚠ 無駄ゾーン', $output);
    }

    public function testNoZoneMarkerWhenZoneIsNone(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new TextReporter())->render($data);

        self::assertStringNotContainsString('⚠', $output);
    }

    public function testRendersStatisticsLine(): void
    {
        $metrics = [
            $this->metrics('App\\A', ca: 0, ce: 0, instability: 0.0, abstractness: 0.0, distance: 0.3, zone: Zone::None),
            $this->metrics('App\\B', ca: 0, ce: 0, instability: 0.0, abstractness: 0.0, distance: 0.3, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new TextReporter())->render($data);

        self::assertStringContainsString('Statistics: mean(D)=0.30, variance(D)=0.00', $output);
    }

    public function testShowsClassListOnlyForZoneComponentsByDefault(): void
    {
        $painComponent = $this->metrics(
            'App\\Legacy',
            ca: 6,
            ce: 1,
            instability: 0.14,
            abstractness: 0.0,
            distance: 0.86,
            zone: Zone::Pain,
            classInfos: [new ClassInfo('App\\Legacy\\OrderManager', TypeKind::ConcreteClass, '/dummy.php', [])],
        );
        $healthyComponent = $this->metrics(
            'App\\Domain',
            ca: 8,
            ce: 2,
            instability: 0.2,
            abstractness: 0.75,
            distance: 0.05,
            zone: Zone::None,
            classInfos: [new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', [])],
        );
        $data = new ReportData([$painComponent, $healthyComponent], MetricsSummary::from([$painComponent, $healthyComponent]), []);

        $output = (new TextReporter(verbose: false))->render($data);

        self::assertStringContainsString('Classes in App\\Legacy:', $output);
        self::assertStringContainsString('App\\Legacy\\OrderManager (concrete)', $output);
        self::assertStringNotContainsString('Classes in App\\Domain:', $output);
    }

    public function testShowsAllClassListsWhenVerbose(): void
    {
        $healthyComponent = $this->metrics(
            'App\\Domain',
            ca: 8,
            ce: 2,
            instability: 0.2,
            abstractness: 0.75,
            distance: 0.05,
            zone: Zone::None,
            classInfos: [new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', [])],
        );
        $data = new ReportData([$healthyComponent], MetricsSummary::from([$healthyComponent]), []);

        $output = (new TextReporter(verbose: true))->render($data);

        self::assertStringContainsString('Classes in App\\Domain:', $output);
        self::assertStringContainsString('App\\Domain\\User (concrete)', $output);
    }

    public function testRendersCyclesSectionAfterStatisticsWhenCyclesExist(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None),
            $this->metrics('App\\Infra', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None),
        ];
        $graph = new DependencyGraph(
            ['App\\Domain', 'App\\Infra'],
            [['App\\Domain', 'App\\Infra'], ['App\\Infra', 'App\\Domain']],
            [
                [
                    'from' => 'App\\Domain',
                    'to' => 'App\\Infra',
                    'classDependencies' => [[
                        'from' => 'App\\Domain\\Order',
                        'to' => 'App\\Infra\\Repository',
                        'evidence' => [[
                            'kind' => 'parameter_type',
                            'file' => 'Domain/Order.php',
                            'line' => 12,
                        ]],
                    ]],
                ],
                [
                    'from' => 'App\\Infra',
                    'to' => 'App\\Domain',
                    'classDependencies' => [[
                        'from' => 'App\\Infra\\Repository',
                        'to' => 'App\\Domain\\Order',
                        'evidence' => [],
                    ]],
                ],
            ],
        );
        $data = new ReportData($metrics, MetricsSummary::from($metrics), [], [['App\\Domain', 'App\\Infra']], $graph);

        $output = (new TextReporter())->render($data);

        self::assertStringContainsString('Cycles (ADP violation):', $output);
        self::assertStringContainsString('Representative shortest path: App\\Domain -> App\\Infra -> App\\Domain', $output);
        self::assertStringContainsString('2 components, peer namespaces', $output);
        self::assertStringContainsString('App\\Domain\\Order -> App\\Infra\\Repository', $output);
        self::assertStringContainsString('parameter_type at Domain/Order.php:12', $output);
        self::assertStringContainsString('App\\Infra\\Repository -> App\\Domain\\Order', $output);
        // 統計行の後にセクションが出ること
        $statisticsPosition = strpos($output, 'Statistics: mean(D)=');
        $cyclesPosition = strpos($output, 'Cycles (ADP violation):');
        self::assertNotFalse($statisticsPosition);
        self::assertNotFalse($cyclesPosition);
        self::assertGreaterThan($statisticsPosition, $cyclesPosition);
    }

    public function testRendersShortestRepresentativePathForBranchedCycle(): void
    {
        $metrics = [
            $this->metrics('App\\A', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None),
            $this->metrics('App\\B', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None),
            $this->metrics('App\\C', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None),
        ];
        $graph = new DependencyGraph(
            ['App\\A', 'App\\B', 'App\\C'],
            [['App\\A', 'App\\B'], ['App\\A', 'App\\C'], ['App\\B', 'App\\A'], ['App\\C', 'App\\A']],
        );
        $data = new ReportData($metrics, MetricsSummary::from($metrics), [], [['App\\A', 'App\\B', 'App\\C']], $graph);

        $output = (new TextReporter())->render($data);

        self::assertStringContainsString('Representative shortest path: App\\A -> App\\B -> App\\A', $output);
        self::assertStringContainsString('Omitted from path (1): App\\C', $output);
        self::assertStringNotContainsString('App\\A -> App\\C', $output);
        self::assertStringNotContainsString('App\\C -> App\\A', $output);
    }

    public function testOmitsCyclesSectionWhenNoCyclesExist(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new TextReporter())->render($data);

        self::assertStringNotContainsString('Cycles', $output);
    }

    public function testRendersUnavailableDependencyMetricsAsNotApplicable(): void
    {
        $metrics = [
            $this->metrics('App', 0, 0, 0.0, 0.25, 0.75, Zone::None, dependencyMetricsEvaluable: false),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new TextReporter())->render($data);

        self::assertMatchesRegularExpression('/App\s+0\s+N\/A\s+N\/A\s+N\/A\s+0\.25\s+N\/A/', $output);
        self::assertStringContainsString('Statistics: mean(D)=N/A, variance(D)=N/A', $output);
    }

    public function testAppendsWarningsAtEnd(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), ['パースエラーのためスキップしました: /path/to/Broken.php']);

        $output = (new TextReporter())->render($data);

        self::assertStringContainsString('パースエラーのためスキップしました: /path/to/Broken.php', $output);
    }

    public function testRendersCycleBaselineComparison(): void
    {
        $data = new ReportData(
            [],
            MetricsSummary::from([]),
            [],
            cycleBaselineComparison: new CycleBaselineComparison(
                newCycles: [['App\\A', 'App\\B']],
                resolvedCycles: [['App\\C', 'App\\D']],
            ),
        );

        $output = (new TextReporter())->render($data);

        self::assertStringContainsString('Cycle baseline comparison:', $output);
        self::assertStringContainsString('New cycles: 1', $output);
        self::assertStringContainsString('+ App\\A, App\\B', $output);
        self::assertStringContainsString('Resolved cycles: 1', $output);
        self::assertStringContainsString('- App\\C, App\\D', $output);
    }

    public function testColumnsAlignAcrossRowsWithDifferentNameLengths(): void
    {
        // Component 列の幅は最長のコンポーネント名に合わせて広がり、
        // ヘッダ行・各データ行の全体の長さが揃う（= 各列が縦に整列する）ことを確認する
        $short = $this->metrics(
            'App\\A',
            ca: 1,
            ce: 1,
            instability: 0.5,
            abstractness: 0.5,
            distance: 0.0,
            zone: Zone::None,
            classInfos: [new ClassInfo('App\\A\\X', TypeKind::ConcreteClass, '/dummy.php', [])],
        );
        $long = $this->metrics(
            'App\\VeryLongComponentNameHere',
            ca: 1,
            ce: 1,
            instability: 0.5,
            abstractness: 0.5,
            distance: 0.0,
            zone: Zone::None,
            classInfos: [new ClassInfo('App\\VeryLongComponentNameHere\\X', TypeKind::ConcreteClass, '/dummy.php', [])],
        );
        $data = new ReportData([$short, $long], MetricsSummary::from([$short, $long]), []);

        $output = (new TextReporter())->render($data);
        $lines = explode("\n", $output);

        $headerLine = null;
        $rowShort = null;
        $rowLong = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Component')) {
                $headerLine = $line;
            } elseif (str_starts_with($line, 'App\\A ')) {
                $rowShort = $line;
            } elseif (str_starts_with($line, 'App\\VeryLongComponentNameHere')) {
                $rowLong = $line;
            }
        }

        self::assertNotNull($headerLine);
        self::assertNotNull($rowShort);
        self::assertNotNull($rowLong);
        // ゾーンなしの行は Zone 列のテキストを持たないため、ヘッダより「  Zone」の分だけ短い
        self::assertSame(mb_strlen($headerLine), mb_strlen($rowShort) + mb_strlen('  Zone'));
        // データ行同士は、コンポーネント名の長さが違っても列幅が揃っているため同じ長さになる
        self::assertSame(mb_strlen($rowShort), mb_strlen($rowLong));
    }

    /**
     * @param list<ClassInfo> $classInfos
     */
    private function metrics(
        string $name,
        int $ca,
        int $ce,
        float $instability,
        float $abstractness,
        float $distance,
        Zone $zone,
        array $classInfos = [],
        bool $dependencyMetricsEvaluable = true,
    ): ComponentMetrics {
        return new ComponentMetrics(
            component: new Component($name, $classInfos),
            ca: $ca,
            ce: $ce,
            instability: $instability,
            abstractness: $abstractness,
            distance: $distance,
            zone: $zone,
            dependencyMetricsEvaluable: $dependencyMetricsEvaluable,
        );
    }
}

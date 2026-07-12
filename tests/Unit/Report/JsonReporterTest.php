<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Report;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\TypeKind;
use Bobsap\Component\Component;
use Bobsap\Component\DependencyGraph;
use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\MetricsSummary;
use Bobsap\Metrics\Zone;
use Bobsap\Report\JsonReporter;
use Bobsap\Report\ReportData;
use PHPUnit\Framework\TestCase;

/**
 * JsonReporter のJSONスキーマを固定するテスト。
 *
 * @phpstan-type ClassDependency array{from: string, to: string}
 * @phpstan-type Dependency array{from: string, to: string, classDependencies: list<ClassDependency>}
 * @phpstan-type CycleGroup array{
 *     components: list<string>,
 *     componentCount: int,
 *     namespaceRelation: 'hierarchical'|'peer',
 *     representativePath: list<string>,
 *     omittedComponents: list<string>,
 *     dependencies: list<Dependency>,
 * }
 * @phpstan-type JsonReport array{
 *     summary: array{componentCount: int, namespaceDepth: int|null, metricsEvaluable: bool, meanDistance: float|null, varianceDistance: float|null},
 *     components: list<array{
 *         name: string,
 *         classCount: int,
 *         metricsEvaluable: bool,
 *         ca: int|null,
 *         ce: int|null,
 *         instability: float|null,
 *         abstractness: float,
 *         distance: float|null,
 *         zone: string|null,
 *         classes: list<array{fqcn: string, kind: string}>,
 *     }>,
 *     dependencies: list<Dependency>,
 *     cycles: list<list<string>>,
 *     cyclePaths: list<array{path: list<string>, dependencies: list<Dependency>}>,
 *     cycleGroups: list<CycleGroup>,
 *     warnings: list<string>,
 * }
 */
final class JsonReporterTest extends TestCase
{
    public function testEncodesSummary(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
            $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 0.1, distance: 0.0, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame(2, $decoded['summary']['componentCount']);
        self::assertTrue($decoded['summary']['metricsEvaluable']);
        self::assertEqualsWithDelta(0.025, $decoded['summary']['meanDistance'], 0.0001);
        self::assertArrayHasKey('varianceDistance', $decoded['summary']);
    }

    public function testEncodesComponentFieldsAndZoneAsNullWhenNoZone(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $decoded = $this->decode((new JsonReporter())->render($data));
        $component = $decoded['components'][0];

        self::assertSame('App\\Domain', $component['name']);
        self::assertSame(0, $component['classCount']);
        self::assertSame(8, $component['ca']);
        self::assertSame(2, $component['ce']);
        self::assertEqualsWithDelta(0.2, $component['instability'], 0.0001);
        self::assertEqualsWithDelta(0.75, $component['abstractness'], 0.0001);
        self::assertEqualsWithDelta(0.05, $component['distance'], 0.0001);
        self::assertNull($component['zone']);
        self::assertTrue($component['metricsEvaluable']);
    }

    public function testEncodesUnavailableDependencyMetricsAsNull(): void
    {
        $metrics = [
            $this->metrics('App', 0, 0, 0.0, 0.25, 0.75, Zone::None, dependencyMetricsEvaluable: false),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertFalse($decoded['summary']['metricsEvaluable']);
        self::assertNull($decoded['summary']['meanDistance']);
        self::assertNull($decoded['summary']['varianceDistance']);
        self::assertFalse($decoded['components'][0]['metricsEvaluable']);
        self::assertNull($decoded['components'][0]['ca']);
        self::assertNull($decoded['components'][0]['ce']);
        self::assertNull($decoded['components'][0]['instability']);
        self::assertSame(0.25, $decoded['components'][0]['abstractness']);
        self::assertNull($decoded['components'][0]['distance']);
    }

    public function testEncodesZoneAsPainOrUseless(): void
    {
        $pain = $this->metrics('App\\Legacy', ca: 6, ce: 1, instability: 0.14, abstractness: 0.0, distance: 0.86, zone: Zone::Pain);
        $useless = $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 1.0, distance: 0.9, zone: Zone::Useless);
        $data = new ReportData([$pain, $useless], MetricsSummary::from([$pain, $useless]), []);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame('pain', $decoded['components'][0]['zone']);
        self::assertSame('useless', $decoded['components'][1]['zone']);
    }

    public function testAlwaysIncludesAllClassesRegardlessOfZone(): void
    {
        $classInfo = new ClassInfo('App\\Domain\\User', TypeKind::Interface_, '/dummy.php', []);
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None, classInfos: [$classInfo]),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $decoded = $this->decode((new JsonReporter())->render($data));
        $classes = $decoded['components'][0]['classes'];

        self::assertCount(1, $classes);
        self::assertSame('App\\Domain\\User', $classes[0]['fqcn']);
        self::assertSame('interface', $classes[0]['kind']);
        self::assertSame(1, $decoded['components'][0]['classCount']);
    }

    public function testEncodesCycles(): void
    {
        $graph = new DependencyGraph(
            ['App\\Domain', 'App\\Infra'],
            [['App\\Domain', 'App\\Infra'], ['App\\Infra', 'App\\Domain']],
            [
                [
                    'from' => 'App\\Domain',
                    'to' => 'App\\Infra',
                    'classDependencies' => [['from' => 'App\\Domain\\Order', 'to' => 'App\\Infra\\Repository']],
                ],
                [
                    'from' => 'App\\Infra',
                    'to' => 'App\\Domain',
                    'classDependencies' => [['from' => 'App\\Infra\\Repository', 'to' => 'App\\Domain\\Order']],
                ],
            ],
        );
        $data = new ReportData([], MetricsSummary::from([]), [], [['App\\Domain', 'App\\Infra']], $graph);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame([['App\\Domain', 'App\\Infra']], $decoded['cycles']);
        self::assertSame(['App\\Domain', 'App\\Infra', 'App\\Domain'], $decoded['cyclePaths'][0]['path']);
        self::assertSame($graph->edgeDetails, $decoded['cyclePaths'][0]['dependencies']);
        self::assertSame(['App\\Domain', 'App\\Infra'], $decoded['cycleGroups'][0]['components']);
        self::assertSame(2, $decoded['cycleGroups'][0]['componentCount']);
        self::assertSame('peer', $decoded['cycleGroups'][0]['namespaceRelation']);
        self::assertSame([], $decoded['cycleGroups'][0]['omittedComponents']);
    }

    public function testEncodesHierarchicalCycleAndComponentsOmittedFromRepresentativePath(): void
    {
        $graph = new DependencyGraph(
            ['App', 'App\\Child', 'App\\Other'],
            [
                ['App', 'App\\Child'],
                ['App\\Child', 'App'],
                ['App\\Child', 'App\\Other'],
                ['App\\Other', 'App'],
            ],
        );
        $data = new ReportData([], MetricsSummary::from([]), [], [['App', 'App\\Child', 'App\\Other']], $graph);

        $decoded = $this->decode((new JsonReporter())->render($data));
        $group = $decoded['cycleGroups'][0];

        self::assertSame('hierarchical', $group['namespaceRelation']);
        self::assertSame(['App', 'App\\Child', 'App'], $group['representativePath']);
        self::assertSame(['App\\Other'], $group['omittedComponents']);
    }

    public function testEncodesDependencyEdgesWithClassEvidence(): void
    {
        $graph = new DependencyGraph(
            ['App\\Domain', 'App\\Infra'],
            [['App\\Domain', 'App\\Infra']],
            [[
                'from' => 'App\\Domain',
                'to' => 'App\\Infra',
                'classDependencies' => [[
                    'from' => 'App\\Domain\\User',
                    'to' => 'App\\Infra\\UserRepository',
                ]],
            ]],
        );
        $data = new ReportData([], MetricsSummary::from([]), [], [], $graph);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame($graph->edgeDetails, $decoded['dependencies']);
    }

    public function testEncodesEmptyCyclesArrayWhenNoCyclesExist(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame([], $decoded['cycles']);
        self::assertSame([], $decoded['cyclePaths']);
        self::assertSame([], $decoded['cycleGroups']);
    }

    public function testEncodesWarnings(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), ['パースエラーのためスキップしました: /x.php']);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame(['パースエラーのためスキップしました: /x.php'], $decoded['warnings']);
    }

    public function testOutputIsUnescapedForSlashesAndUnicode(): void
    {
        $classInfo = new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', []);
        $metrics = [
            $this->metrics('App\\Legacy', ca: 6, ce: 1, instability: 0.14, abstractness: 0.0, distance: 0.86, zone: Zone::Pain, classInfos: [$classInfo]),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new JsonReporter())->render($data);

        // JSON_UNESCAPED_SLASHES: FQCN の \ が \\/ にエスケープされない
        self::assertStringNotContainsString('\\/', $output);
        // JSON_PRETTY_PRINT: インデントされている
        self::assertStringContainsString("\n", $output);
    }

    /** @return JsonReport */
    private function decode(string $json): array
    {
        /** @var JsonReport $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
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

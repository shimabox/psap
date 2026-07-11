<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Report;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\TypeKind;
use Bobsap\Component\Component;
use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\MetricsSummary;
use Bobsap\Metrics\Zone;
use Bobsap\Report\PlantUmlReporter;
use Bobsap\Report\ReportData;
use PHPUnit\Framework\TestCase;

// PlantUmlReporter: @startuml/@enduml・ノードのメトリクスラベル・ゾーン色・
// エッジ集約（同じコンポーネントペアの複数クラス依存は1本）・対象外依存の無視のテスト
final class PlantUmlReporterTest extends TestCase
{
    public function testWrapsOutputWithStartAndEndUmlTags(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringStartsWith('@startuml', $output);
        self::assertStringEndsWith('@enduml', $output);
    }

    public function testRendersNodeWithNameAndMetricsLabel(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        // FQCN の `\` は PlantUML のラベル内で `\\` にエスケープされ、
        // 名前とメトリクスの区切りには `\n`（改行のエスケープ）を使う
        self::assertStringContainsString('rectangle "App\\\\Domain\\nI=0.20 A=0.75 D=0.05" as C1', $output);
    }

    public function testUsesSequentialNodeIds(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
            $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 0.1, distance: 0.0, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('as C1', $output);
        self::assertStringContainsString('as C2', $output);
    }

    public function testColorsPainZoneComponentRed(): void
    {
        $metrics = [
            $this->metrics('App\\Legacy', ca: 6, ce: 1, instability: 0.14, abstractness: 0.0, distance: 0.86, zone: Zone::Pain),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('as C1 #FFCCCC', $output);
    }

    public function testColorsUselessZoneComponentYellow(): void
    {
        $metrics = [
            $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 1.0, distance: 0.9, zone: Zone::Useless),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('as C1 #FFF2CC', $output);
    }

    public function testNoColorSuffixForNormalZone(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertMatchesRegularExpression('/as C1\s*$/m', $output);
    }

    public function testRendersLegendForZoneColors(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('legend right', $output);
        self::assertStringContainsString('endlegend', $output);
        // CJK フォントのない環境（CI 等）でも豆腐にならないよう、凡例は英語で出力する
        self::assertStringContainsString('Pain Zone = #FFCCCC', $output);
        self::assertStringContainsString('Useless Zone = #FFF2CC', $output);
        self::assertStringContainsString('Normal = no color', $output);
    }

    public function testAggregatesMultipleClassDependenciesIntoSingleEdge(): void
    {
        // App\Domain の2クラスがどちらも App\Infra 内のクラスに依存している
        $domainClasses = [
            new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', ['App\\Infra\\UserRepository']),
            new ClassInfo('App\\Domain\\Order', TypeKind::ConcreteClass, '/dummy.php', ['App\\Infra\\UserRepository']),
        ];
        $infraClasses = [
            new ClassInfo('App\\Infra\\UserRepository', TypeKind::ConcreteClass, '/dummy.php', []),
        ];
        $metrics = [
            $this->metrics('App\\Domain', ca: 0, ce: 2, instability: 1.0, abstractness: 0.0, distance: 0.0, zone: Zone::None, classInfos: $domainClasses),
            $this->metrics('App\\Infra', ca: 2, ce: 0, instability: 0.0, abstractness: 0.0, distance: 1.0, zone: Zone::None, classInfos: $infraClasses),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertSame(1, substr_count($output, 'C1 --> C2'));
    }

    public function testIgnoresDependenciesOutsideAnalyzedComponents(): void
    {
        $domainClasses = [
            new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', ['Vendor\\SomeLib\\Thing']),
        ];
        $metrics = [
            $this->metrics('App\\Domain', ca: 0, ce: 0, instability: 0.0, abstractness: 0.0, distance: 1.0, zone: Zone::None, classInfos: $domainClasses),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringNotContainsString('-->', $output);
    }

    public function testIgnoresIntraComponentDependencies(): void
    {
        $domainClasses = [
            new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', ['App\\Domain\\Address']),
            new ClassInfo('App\\Domain\\Address', TypeKind::ConcreteClass, '/dummy.php', []),
        ];
        $metrics = [
            $this->metrics('App\\Domain', ca: 0, ce: 0, instability: 0.0, abstractness: 0.0, distance: 1.0, zone: Zone::None, classInfos: $domainClasses),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringNotContainsString('-->', $output);
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
    ): ComponentMetrics {
        return new ComponentMetrics(
            component: new Component($name, $classInfos),
            ca: $ca,
            ce: $ce,
            instability: $instability,
            abstractness: $abstractness,
            distance: $distance,
            zone: $zone,
        );
    }
}

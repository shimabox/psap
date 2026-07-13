<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Psap\Analyzer\ClassInfo;
use Psap\Analyzer\TypeKind;
use Psap\Component\Component;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\MetricsSummary;
use Psap\Metrics\Zone;
use Psap\Report\PlantUmlReporter;
use Psap\Report\ReportData;

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
        self::assertStringContainsString('Red edge = shortest cycle path (ADP violation)', $output);
    }

    // エッジ導出（集約・対象外依存の無視・コンポーネント内依存の無視）のテストは
    // Psap\Component\DependencyGraph に共有クラスとして抽出済みのため、そちらに移設した
    // （tests/Unit/Component/DependencyGraphTest.php）。ここではレンダリング結果のみ検証する。
    public function testRendersEdgeBetweenComponents(): void
    {
        $domainClasses = [
            new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', ['App\\Infra\\UserRepository']),
        ];
        $infraClasses = [
            new ClassInfo('App\\Infra\\UserRepository', TypeKind::ConcreteClass, '/dummy.php', []),
        ];
        $metrics = [
            $this->metrics('App\\Domain', ca: 0, ce: 1, instability: 1.0, abstractness: 0.0, distance: 0.0, zone: Zone::None, classInfos: $domainClasses),
            $this->metrics('App\\Infra', ca: 1, ce: 0, instability: 0.0, abstractness: 0.0, distance: 1.0, zone: Zone::None, classInfos: $infraClasses),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('C1 --> C2', $output);
    }

    public function testRendersCycleEdgesInRed(): void
    {
        // App\A <-> App\B の相互依存（循環）
        $aClasses = [
            new ClassInfo('App\\A\\X', TypeKind::ConcreteClass, '/dummy.php', ['App\\B\\X']),
        ];
        $bClasses = [
            new ClassInfo('App\\B\\X', TypeKind::ConcreteClass, '/dummy.php', ['App\\A\\X']),
        ];
        $metrics = [
            $this->metrics('App\\A', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None, classInfos: $aClasses),
            $this->metrics('App\\B', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None, classInfos: $bClasses),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), [], [['App\\A', 'App\\B']]);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('C1 -[#red,thickness=2]-> C2', $output);
        self::assertStringContainsString('C2 -[#red,thickness=2]-> C1', $output);
        self::assertStringNotContainsString('C1 --> C2', $output);
        self::assertStringNotContainsString('C2 --> C1', $output);
    }

    public function testColorsOnlyEdgesInRepresentativeCyclePath(): void
    {
        $classes = [
            new ClassInfo('App\\A\\X', TypeKind::ConcreteClass, '/dummy.php', ['App\\B\\X', 'App\\C\\X']),
            new ClassInfo('App\\B\\X', TypeKind::ConcreteClass, '/dummy.php', ['App\\A\\X']),
            new ClassInfo('App\\C\\X', TypeKind::ConcreteClass, '/dummy.php', ['App\\A\\X']),
        ];
        $metrics = [
            $this->metrics('App\\A', ca: 2, ce: 2, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None, classInfos: [$classes[0]]),
            $this->metrics('App\\B', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None, classInfos: [$classes[1]]),
            $this->metrics('App\\C', ca: 1, ce: 1, instability: 0.5, abstractness: 0.0, distance: 0.5, zone: Zone::None, classInfos: [$classes[2]]),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), [], [['App\\A', 'App\\B', 'App\\C']]);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('C1 -[#red,thickness=2]-> C2', $output);
        self::assertStringContainsString('C2 -[#red,thickness=2]-> C1', $output);
        self::assertStringContainsString('C1 --> C3', $output);
        self::assertStringContainsString('C3 --> C1', $output);
    }

    public function testDoesNotColorEdgesOutsideCycle(): void
    {
        $domainClasses = [
            new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', ['App\\Infra\\UserRepository']),
        ];
        $infraClasses = [
            new ClassInfo('App\\Infra\\UserRepository', TypeKind::ConcreteClass, '/dummy.php', []),
        ];
        $metrics = [
            $this->metrics('App\\Domain', ca: 0, ce: 1, instability: 1.0, abstractness: 0.0, distance: 0.0, zone: Zone::None, classInfos: $domainClasses),
            $this->metrics('App\\Infra', ca: 1, ce: 0, instability: 0.0, abstractness: 0.0, distance: 1.0, zone: Zone::None, classInfos: $infraClasses),
        ];
        // 循環は別コンポーネントの組み合わせなので、Domain -> Infra のエッジは赤くならない
        $data = new ReportData($metrics, MetricsSummary::from($metrics), [], [['App\\Other1', 'App\\Other2']]);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('C1 --> C2', $output);
        self::assertStringNotContainsString('#red', $output);
    }

    public function testRendersUnavailableDependencyMetricsAsNotApplicable(): void
    {
        $metrics = [
            $this->metrics('App', 0, 0, 0.0, 0.25, 0.75, Zone::None, dependencyMetricsEvaluable: false),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PlantUmlReporter())->render($data);

        self::assertStringContainsString('App\\nI=N/A A=0.25 D=N/A', $output);
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

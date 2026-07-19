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
use Psap\Report\MermaidFlowchartReporter;
use Psap\Report\ReportData;

// MermaidFlowchartReporter: flowchart TD ヘッダ・ノード数/エッジ数の一致・
// ノードのメトリクスラベル（エスケープ含む）・ゾーン色分け（classDef 適用）・
// 循環エッジのみの強調（linkStyle）・コードフェンス非包含のテスト
final class MermaidFlowchartReporterTest extends TestCase
{
    public function testRendersFlowchartHeader(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringStartsWith('flowchart TD', $output);
    }

    public function testNodeCountMatchesComponentMetrics(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
            $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 0.1, distance: 0.0, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringContainsString('C1["App\\Domain<br/>I=0.20 A=0.75 D=0.05"]', $output);
        self::assertStringContainsString('C2["App\\Infra<br/>I=0.90 A=0.10 D=0.00"]', $output);
        // 余分なノードが出力されていないことも確認する（ノード行の出現数が componentMetrics 数と一致）
        self::assertSame(count($metrics), preg_match_all('/^\s*C\d+\["/m', $output));
    }

    public function testEdgeCountMatchesDependencyGraph(): void
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

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertSame(1, substr_count($output, '-->'));
        self::assertStringContainsString('C1 --> C2', $output);
    }

    public function testHighlightsOnlyEdgesInRepresentativeCyclePath(): void
    {
        // App\A -> App\B, App\C（両方 App\A に依存を戻すが、代表循環経路は A<->B のみ）
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

        $output = (new MermaidFlowchartReporter())->render($data);

        // エッジ出力順: C1-->C2 (0), C1-->C3 (1), C2-->C1 (2), C3-->C1 (3)
        // 代表循環経路 App\A -> App\B -> App\A に含まれるのは index 0, 2 のみ
        self::assertStringContainsString('C1 --> C2', $output);
        self::assertStringContainsString('C1 --> C3', $output);
        self::assertStringContainsString('C2 --> C1', $output);
        self::assertStringContainsString('C3 --> C1', $output);
        self::assertStringContainsString('linkStyle 0,2 stroke:#FF0000,stroke-width:2px', $output);
    }

    public function testDoesNotHighlightEdgesOutsideCycle(): void
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
        // 循環は別コンポーネントの組み合わせなので、Domain -> Infra のエッジは強調されない
        $data = new ReportData($metrics, MetricsSummary::from($metrics), [], [['App\\Other1', 'App\\Other2']]);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringContainsString('C1 --> C2', $output);
        self::assertStringNotContainsString('linkStyle', $output);
    }

    public function testEscapesSpecialCharactersInComponentName(): void
    {
        // `\`（名前空間区切り）はそのまま通す想定。`<` `>` `&` `"` はエンティティ記法でエスケープする
        $metrics = [
            $this->metrics('App\\<Tag>&"Quote"', ca: 0, ce: 0, instability: 0.0, abstractness: 0.0, distance: 0.0, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringContainsString(
            'C1["App\\#lt;Tag#gt;#38;#quot;Quote#quot;<br/>I=0.00 A=0.00 D=0.00"]',
            $output,
        );
        // エスケープ前の生文字が残っていないことも確認する
        self::assertStringNotContainsString('<Tag>', $output);
        self::assertStringNotContainsString('&"', $output);
    }

    public function testRendersUnavailableDependencyMetricsAsNotApplicable(): void
    {
        $metrics = [
            $this->metrics('App', 0, 0, 0.0, 0.25, 0.75, Zone::None, dependencyMetricsEvaluable: false),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringContainsString('App<br/>I=N/A A=0.25 D=N/A', $output);
    }

    public function testAppliesPainClassDefToPainZoneComponent(): void
    {
        $metrics = [
            $this->metrics('App\\Legacy', ca: 6, ce: 1, instability: 0.14, abstractness: 0.0, distance: 0.86, zone: Zone::Pain),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringContainsString('"]:::pain', $output);
        self::assertStringContainsString('classDef pain fill:#FFCCCC', $output);
    }

    public function testAppliesUselessClassDefToUselessZoneComponent(): void
    {
        $metrics = [
            $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 1.0, distance: 0.9, zone: Zone::Useless),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringContainsString('"]:::useless', $output);
        self::assertStringContainsString('classDef useless fill:#FFF2CC', $output);
    }

    public function testAppliesNoClassSuffixForNormalZoneComponent(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertMatchesRegularExpression('/"\]\s*$/m', $output);
    }

    public function testOutputDoesNotIncludeMarkdownCodeFence(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringNotContainsString('```', $output);
    }

    public function testRendersLegendAsComment(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new MermaidFlowchartReporter())->render($data);

        self::assertStringContainsString('%% Pain Zone = #FFCCCC', $output);
        self::assertStringContainsString('%% Useless Zone = #FFF2CC', $output);
        self::assertStringContainsString('%% Normal = no color', $output);
        self::assertStringContainsString('%% Red edge = shortest cycle path (ADP violation)', $output);
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

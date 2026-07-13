<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Report;

use JsonException;
use PHPUnit\Framework\TestCase;
use Psap\Analyzer\ClassInfo;
use Psap\Analyzer\TypeKind;
use Psap\Component\Component;
use Psap\Component\DependencyGraph;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\MetricsSummary;
use Psap\Metrics\Zone;
use Psap\Report\HtmlReporter;
use Psap\Report\ReportData;

/**
 * @phpstan-type HtmlComponent array{
 *     name: string,
 *     classCount: int,
 *     metricsEvaluable: bool,
 *     ca: int|null,
 *     ce: int|null,
 *     instability: float|null,
 *     abstractness: float,
 *     distance: float|null,
 *     zone: string|null,
 *     classes: list<array{fqcn: string, kind: string}>
 * }
 * @phpstan-type HtmlCycle array{
 *     components: list<string>,
 *     componentCount: int,
 *     namespaceRelation: 'hierarchical'|'peer',
 *     representativePath: list<string>,
 *     omittedComponents: list<string>,
 *     dependencies: list<array{
 *         from: string,
 *         to: string,
 *         classDependencies: list<array{
 *             from: string,
 *             to: string,
 *             evidence: list<array{kind: string, file: string, line: int}>
 *         }>
 *     }>
 * }
 * @phpstan-type HtmlPayload array{
 *     summary: array{componentCount: int, meanDistance: float|null, cycleGroupCount: int},
 *     warnings: list<string>,
 *     components: list<HtmlComponent>,
 *     cycles: list<HtmlCycle>
 * }
 */
final class HtmlReporterTest extends TestCase
{
    public function testRendersSelfContainedInteractiveDocument(): void
    {
        $metrics = [$this->metrics('App\\Domain', 0.2, 0.75, 0.05)];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new HtmlReporter())->render($data);

        self::assertStringStartsWith('<!doctype html>', $output);
        self::assertStringContainsString('<title>psap — Interactive I/A report</title>', $output);
        self::assertStringContainsString('<h1 data-i18n="headline">Instability / Abstractness Analysis</h1>', $output);
        self::assertStringNotContainsString('Instability meets abstraction.', $output);
        self::assertStringContainsString('id="ia-chart"', $output);
        self::assertStringContainsString('aria-label="SAP instability and abstractness graph"', $output);
        self::assertStringContainsString('aria-describedby="chart-description"', $output);
        self::assertStringNotContainsString('<title id="chart-title"', $output);
        self::assertStringContainsString('id="tooltip"', $output);
        self::assertStringContainsString('id="inspector"', $output);
        self::assertStringContainsString('id="cycle-panel"', $output);
        self::assertStringContainsString('id="warning-panel"', $output);
        self::assertStringContainsString('id="summary-cycles"', $output);
        $tablePosition = strpos($output, '<section class="table-panel"');
        $cyclePosition = strpos($output, '<section id="cycle-panel"');
        self::assertNotFalse($tablePosition);
        self::assertNotFalse($cyclePosition);
        self::assertGreaterThan($tablePosition, $cyclePosition);
        self::assertStringNotContainsString('details.open = index === 0', $output);
        self::assertStringContainsString('<html lang="en">', $output);
        self::assertStringContainsString('<select id="language">', $output);
        self::assertStringContainsString('<option value="ja">日本語</option>', $output);
        self::assertStringContainsString("let locale = 'en'", $output);
        self::assertStringContainsString("language.addEventListener('change'", $output);
        self::assertStringNotContainsString('psap / アーキテクチャ検査ボード', $output);
        self::assertStringNotContainsString('不安定度と抽象度の交点。', $output);
        self::assertStringContainsString("containedClasses: '含まれるクラス'", $output);
        self::assertStringContainsString("cycleHeading: '循環依存が検出されました'", $output);
        self::assertStringContainsString("analysisWarnings: '解析時の警告'", $output);
        self::assertStringContainsString("noMatches: '絞り込みに一致するコンポーネントがありません。", $output);
        self::assertStringContainsString("metricIName: 'Instability (I)'", $output);
        self::assertStringContainsString("metricIHelp: 'Ce / (Ca + Ce).", $output);
        self::assertStringContainsString("metricCaName: '求心性結合度 (Ca)'", $output);
        self::assertStringContainsString("metricDHelp: '|A + I - 1|。", $output);
        self::assertStringContainsString("tooltip.setAttribute('role', 'tooltip')", $output);
        self::assertStringContainsString("wrapper.setAttribute('aria-describedby', tooltipId)", $output);
        self::assertStringContainsString('wrapper.tabIndex = 0', $output);
        self::assertStringContainsString('.metric-grid > div:hover .metric-definition', $output);
        self::assertStringNotContainsString('className = \'metric-help\'', $output);
        self::assertStringNotContainsString('cursor: help', $output);
        self::assertStringContainsString('this HTML report draws the radius-based boundaries used by psap', $output);
        self::assertStringContainsString('Point metrics and coordinates come from the same analysis.', $output);
        self::assertStringContainsString('tabindex: \'0\'', $output);
        self::assertStringContainsString("element.addEventListener('focus', show)", $output);
        self::assertStringContainsString("element.addEventListener('click'", $output);
        self::assertStringNotContainsString('src="https://', $output);
        self::assertStringNotContainsString('href="https://', $output);
        self::assertStringNotContainsString('fetch(', $output);
    }

    /**
     * @throws JsonException
     */
    public function testEmbedsAndRendersAnalysisWarnings(): void
    {
        $metrics = [$this->metrics('App\\Domain', 0.2, 0.75, 0.05)];
        $warning = 'UTF-8として解釈できないためスキップしました: /project/Latin1.php:18';
        $data = new ReportData($metrics, MetricsSummary::from($metrics), [$warning]);

        $output = (new HtmlReporter())->render($data);
        $payload = $this->payload($output);

        self::assertSame([$warning], $payload['warnings']);
        self::assertStringContainsString('id="warning-count"', $output);
        self::assertStringContainsString('id="warning-list"', $output);
        self::assertStringContainsString('warningPanel.hidden = report.warnings.length === 0', $output);
        self::assertStringContainsString("appendTextElement(warningList, 'li', warning)", $output);
    }

    /**
     * @throws JsonException
     */
    public function testEmbedsAndRendersCycleDetailsWithClassEvidence(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', 0.5, 0.0, 0.5),
            $this->metrics('App\\Infra', 0.5, 0.0, 0.5),
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
                            'file' => 'src/Domain/Order.php',
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
        $data = new ReportData(
            $metrics,
            MetricsSummary::from($metrics),
            [],
            [['App\\Domain', 'App\\Infra']],
            $graph,
        );

        $output = (new HtmlReporter())->render($data);
        $payload = $this->payload($output);

        self::assertSame(1, $payload['summary']['cycleGroupCount']);
        self::assertSame(['App\\Domain', 'App\\Infra'], $payload['cycles'][0]['components']);
        self::assertSame(
            ['App\\Domain', 'App\\Infra', 'App\\Domain'],
            $payload['cycles'][0]['representativePath'],
        );
        self::assertSame(
            [[
                'kind' => 'parameter_type',
                'file' => 'src/Domain/Order.php',
                'line' => 12,
            ]],
            $payload['cycles'][0]['dependencies'][0]['classDependencies'][0]['evidence'],
        );
        self::assertStringContainsString('function renderCycles()', $output);
        self::assertStringContainsString('function focusCycle(index)', $output);
        self::assertStringContainsString("componentInCycle: 'Part of 1 cycle group'", $output);
    }

    /**
     * @throws JsonException
     */
    public function testEmbedsComponentMetricsAndContainedClasses(): void
    {
        $classInfo = new ClassInfo('App\\Domain\\Order', TypeKind::ConcreteClass, '/private/project/Order.php', []);
        $metrics = [$this->metrics('App\\Domain', 0.2, 0.75, 0.05, ca: 8, ce: 2, classInfos: [$classInfo])];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $payload = $this->payload((new HtmlReporter())->render($data));
        $component = $payload['components'][0];

        self::assertSame('App\\Domain', $component['name']);
        self::assertSame(8, $component['ca']);
        self::assertSame(2, $component['ce']);
        self::assertSame(0.2, $component['instability']);
        self::assertSame(0.75, $component['abstractness']);
        self::assertSame(0.05, $component['distance']);
        self::assertSame([['fqcn' => 'App\\Domain\\Order', 'kind' => 'concrete']], $component['classes']);
        self::assertArrayNotHasKey('filePath', $component['classes'][0]);
    }

    /**
     * @throws JsonException
     */
    public function testKeepsOverlappingAndBoundaryPointsWithoutClamping(): void
    {
        $metrics = [
            $this->metrics('App\\One', 0.0, 1.0, 0.0),
            $this->metrics('App\\Two', 0.0, 1.0, 0.0),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $payload = $this->payload((new HtmlReporter())->render($data));

        self::assertCount(2, $payload['components']);
        self::assertEquals(0.0, $payload['components'][0]['instability']);
        self::assertEquals(1.0, $payload['components'][0]['abstractness']);
        self::assertEquals(0.0, $payload['components'][1]['instability']);
        self::assertStringContainsString('groupAtSameCoordinate', (new HtmlReporter())->render($data));
    }

    /**
     * @throws JsonException
     */
    public function testKeepsUnevaluableComponentInPayloadButDoesNotPlotIt(): void
    {
        $metrics = [$this->metrics('App', 0.0, 0.25, 0.75, evaluable: false)];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new HtmlReporter())->render($data);
        $component = $this->payload($output)['components'][0];

        self::assertFalse($component['metricsEvaluable']);
        self::assertNull($component['instability']);
        self::assertNull($component['distance']);
        self::assertStringContainsString('component.metricsEvaluable', $output);
    }

    /**
     * @throws JsonException
     */
    public function testEscapesScriptBreakingPayloadAndRecoversOriginalValue(): void
    {
        $attack = '</script><script>alert("psap")</script>&\'';
        $classInfo = new ClassInfo($attack, TypeKind::ConcreteClass, '/dummy.php', []);
        $metrics = [$this->metrics($attack, 0.2, 0.75, 0.05, classInfos: [$classInfo])];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new HtmlReporter())->render($data);
        $payload = $this->payload($output);

        self::assertSame(2, substr_count($output, '<script'));
        self::assertStringNotContainsString($attack, $output);
        self::assertSame($attack, $payload['components'][0]['name']);
        self::assertSame($attack, $payload['components'][0]['classes'][0]['fqcn']);
    }

    /**
     * @return HtmlPayload
     * @throws JsonException
     */
    private function payload(string $output): array
    {
        $matched = preg_match(
            '/<script id="psap-data" type="application\/json">(.*?)<\/script>/s',
            $output,
            $matches,
        );
        if ($matched !== 1) {
            self::fail('Embedded psap JSON payload was not found.');
        }

        /** @var HtmlPayload $payload */
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @param list<ClassInfo> $classInfos
     */
    private function metrics(
        string $name,
        float $instability,
        float $abstractness,
        float $distance,
        int $ca = 0,
        int $ce = 0,
        array $classInfos = [],
        bool $evaluable = true,
    ): ComponentMetrics {
        return new ComponentMetrics(
            component: new Component($name, $classInfos),
            ca: $ca,
            ce: $ce,
            instability: $instability,
            abstractness: $abstractness,
            distance: $distance,
            zone: Zone::determine($instability, $abstractness),
            dependencyMetricsEvaluable: $evaluable,
        );
    }
}

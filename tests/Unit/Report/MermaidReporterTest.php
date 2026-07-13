<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Psap\Component\Component;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\MetricsSummary;
use Psap\Metrics\Zone;
use Psap\Report\MermaidReporter;
use Psap\Report\ReportData;

// MermaidReporter: quadrantChart のヘッダ構造・点の座標（クランプ含む）・ラベルの D 値・
// 象限ラベル（ゾーンの意味づけ）のテスト
final class MermaidReporterTest extends TestCase
{
    public function testRendersQuadrantChartHeaderStructure(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new MermaidReporter())->render($data);

        self::assertStringContainsString('quadrantChart', $output);
        self::assertStringContainsString('title SAP - I/A Graph (D = distance from main sequence)', $output);
        // 括弧を含むラベルは Mermaid のパーサーエラーを避けるため引用符で囲む
        self::assertStringContainsString('x-axis "Instability (I)"', $output);
        self::assertStringContainsString('y-axis "Abstractness (A)"', $output);
    }

    public function testQuadrantLabelsMatchZoneMeaning(): void
    {
        // 右上（I高・A高）が無駄ゾーン、左下（I低・A低）が苦痛ゾーン
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new MermaidReporter())->render($data);

        self::assertStringContainsString('quadrant-1 Useless Zone', $output);
        self::assertStringContainsString('quadrant-2 Main Sequence', $output);
        self::assertStringContainsString('quadrant-3 Pain Zone', $output);
        self::assertStringContainsString('quadrant-4 Main Sequence', $output);
    }

    public function testRendersPointWithComponentNameAndDistanceLabel(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', instability: 0.2, abstractness: 0.75, distance: 0.05),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidReporter())->render($data);

        self::assertStringContainsString('"App\\Domain (D=0.05)": [0.2, 0.75]', $output);
    }

    public function testClampsZeroCoordinateToAvoidRenderingEdgeCase(): void
    {
        $metrics = [
            $this->metrics('App\\Infra', instability: 0.0, abstractness: 0.1, distance: 0.9),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidReporter())->render($data);

        // instability=0.0 は表示専用に 0.01 へクランプされる
        self::assertStringContainsString('[0.01, 0.1]', $output);
    }

    public function testClampsOneCoordinateToAvoidRenderingEdgeCase(): void
    {
        $metrics = [
            $this->metrics('App\\Infra', instability: 0.9, abstractness: 1.0, distance: 0.9),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidReporter())->render($data);

        // abstractness=1.0 は表示専用に 0.99 へクランプされる
        self::assertStringContainsString('[0.9, 0.99]', $output);
    }

    public function testDistanceLabelUsesActualValueEvenWhenCoordinateIsClamped(): void
    {
        $metrics = [
            $this->metrics('App\\Infra', instability: 0.0, abstractness: 0.0, distance: 1.0),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidReporter())->render($data);

        // ラベルの D 値はクランプせず実値のまま
        self::assertStringContainsString('(D=1.00)', $output);
        self::assertStringContainsString('[0.01, 0.01]', $output);
    }

    public function testOutputDoesNotIncludeMarkdownCodeFence(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', instability: 0.2, abstractness: 0.75, distance: 0.05),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidReporter())->render($data);

        self::assertStringNotContainsString('```', $output);
    }

    public function testRendersMultipleComponentsAsMultiplePoints(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', instability: 0.2, abstractness: 0.75, distance: 0.05),
            $this->metrics('App\\Infra', instability: 0.9, abstractness: 0.1, distance: 0.0),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidReporter())->render($data);

        self::assertStringContainsString('"App\\Domain (D=0.05)": [0.2, 0.75]', $output);
        self::assertStringContainsString('"App\\Infra (D=0.00)": [0.9, 0.1]', $output);
    }

    public function testHighlightsCycleComponentsWithLabelAndPointStyle(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', instability: 0.2, abstractness: 0.75, distance: 0.05),
            $this->metrics('App\\Infra', instability: 0.9, abstractness: 0.1, distance: 0.0),
            $this->metrics('App\\Shared', instability: 0.5, abstractness: 0.5, distance: 0.0),
        ];
        $data = new ReportData(
            $metrics,
            MetricsSummary::from($metrics),
            [],
            [['App\\Domain', 'App\\Infra']],
        );

        $output = (new MermaidReporter())->render($data);

        self::assertStringContainsString('"App\\Domain [cycle] (D=0.05)":::cycle: [0.2, 0.75]', $output);
        self::assertStringContainsString('"App\\Infra [cycle] (D=0.00)":::cycle: [0.9, 0.1]', $output);
        self::assertStringContainsString('"App\\Shared (D=0.00)": [0.5, 0.5]', $output);
        self::assertStringContainsString(
            'classDef cycle color: #b42318, radius: 9, stroke-color: #7a271a, stroke-width: 3px',
            $output,
        );
    }

    public function testOmitsCycleStyleWhenThereAreNoCyclePoints(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', instability: 0.2, abstractness: 0.75, distance: 0.05),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidReporter())->render($data);

        self::assertStringNotContainsString('[cycle]', $output);
        self::assertStringNotContainsString('classDef cycle', $output);
    }

    public function testOmitsComponentsWithUnavailableDependencyMetrics(): void
    {
        $metrics = [
            $this->metrics('App', instability: 0.0, abstractness: 0.25, distance: 0.75, evaluable: false),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MermaidReporter())->render($data);

        self::assertStringNotContainsString('"App (D=', $output);
        self::assertStringContainsString('No components with evaluable dependency metrics', $output);
    }

    private function metrics(
        string $name,
        float $instability,
        float $abstractness,
        float $distance,
        bool $evaluable = true,
    ): ComponentMetrics {
        return new ComponentMetrics(
            component: new Component($name, []),
            ca: 0,
            ce: 0,
            instability: $instability,
            abstractness: $abstractness,
            distance: $distance,
            zone: Zone::determine($instability, $abstractness),
            dependencyMetricsEvaluable: $evaluable,
        );
    }
}

<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Psap\Analyzer\AnalysisCoverage;
use Psap\Analyzer\ClassInfo;
use Psap\Analyzer\TypeKind;
use Psap\Baseline\CycleBaselineComparison;
use Psap\Component\Component;
use Psap\Component\DependencyGraph;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\MetricsSummary;
use Psap\Metrics\Zone;
use Psap\Report\MarkdownReporter;
use Psap\Report\ReportData;

final class MarkdownReporterTest extends TestCase
{
    public function testRendersAnalysisContextPrioritiesCycleEvidenceAndMetrics(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', 1, 1, 0.5, 0.0, 0.5, Zone::None, 'App\\Domain\\Order'),
            $this->metrics('App\\Infra', 1, 1, 0.5, 0.0, 0.5, Zone::Pain, 'App\\Infra\\Repository'),
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
                            'line' => 18,
                        ]],
                    ]],
                ],
                [
                    'from' => 'App\\Infra',
                    'to' => 'App\\Domain',
                    'classDependencies' => [[
                        'from' => 'App\\Infra\\Repository',
                        'to' => 'App\\Domain\\Order',
                        'evidence' => [[
                            'kind' => 'return_type',
                            'file' => 'Infra/Repository.php',
                            'line' => 24,
                        ]],
                    ]],
                ],
            ],
        );
        $data = new ReportData(
            componentMetrics: $metrics,
            summary: MetricsSummary::from($metrics),
            warnings: ['One file could not be parsed.'],
            cycles: [['App\\Domain', 'App\\Infra']],
            dependencyGraph: $graph,
            namespaceDepth: 2,
            cycleBaselineComparison: new CycleBaselineComparison(
                [['App\\Domain', 'App\\Infra']],
                [['App\\Legacy', 'App\\Old']],
            ),
            sourcePaths: ['src'],
            docblockEnabled: false,
            excludePatterns: ['*/Generated/*'],
        );

        $output = (new MarkdownReporter())->render($data);

        self::assertStringContainsString('# psap Architecture Analysis', $output);
        self::assertStringContainsString('| Components | 2 |', $output);
        self::assertStringContainsString('- Source paths `src`', $output);
        self::assertStringContainsString('- Docblock dependencies disabled', $output);
        self::assertStringContainsString('- Exclude patterns `*/Generated/*`', $output);
        self::assertStringContainsString('New cycle not present in the baseline', $output);
        self::assertStringContainsString('Review `App\\Infra` in the pain zone', $output);
        self::assertStringContainsString('## Cycle Baseline Changes', $output);
        self::assertStringContainsString('Representative shortest path `App\\Domain` -> `App\\Infra` -> `App\\Domain`', $output);
        self::assertStringContainsString('`parameter_type` at `Domain/Order.php:18`', $output);
        self::assertStringContainsString('## Dependency Hotspots', $output);
        self::assertStringContainsString('| `App\\Domain` | 1 | 1 | 1 | 0.50 | 0.00 | 0.50 |  |', $output);
        self::assertStringContainsString('- One file could not be parsed.', $output);
        self::assertStringContainsString('## Interpretation Notes', $output);
    }

    public function testStatesWhenNoPrioritiesExistAndMetricsAreNotEvaluable(): void
    {
        $metrics = [$this->metrics('App', 0, 0, 0.0, 0.5, 0.5, Zone::None, 'App\\Thing', false)];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new MarkdownReporter())->render($data);

        self::assertStringContainsString('No circular dependencies or SAP zone violations were detected.', $output);
        self::assertStringContainsString('| `App` | 1 | N/A | N/A | N/A | 0.50 | N/A |  |', $output);
        self::assertStringContainsString('Mean D and variance D are not evaluable.', $output);
        self::assertStringNotContainsString('## Circular Dependencies', $output);
        self::assertStringNotContainsString('## Dependency Hotspots', $output);
    }

    public function testAddsFileCoverageToAnalysisSummary(): void
    {
        $data = new ReportData(
            [],
            MetricsSummary::from([]),
            [],
            analysisCoverage: new AnalysisCoverage(10_715, 8_205, 8_204, 2_510, 1),
        );

        $output = (new MarkdownReporter())->render($data);

        self::assertStringContainsString('| Analysis coverage | 99.99% |', $output);
        self::assertStringContainsString('| Discovered PHP files | 10,715 |', $output);
        self::assertStringContainsString('| Selected PHP files | 8,205 |', $output);
        self::assertStringContainsString('| Analyzed PHP files | 8,204 |', $output);
        self::assertStringContainsString('| Excluded PHP files | 2,510 |', $output);
        self::assertStringContainsString('| Skipped PHP files | 1 |', $output);
    }

    public function testRendersFileCoverageAsNotApplicableWhenNoFilesAreSelected(): void
    {
        $data = new ReportData(
            [],
            MetricsSummary::from([]),
            [],
            analysisCoverage: new AnalysisCoverage(5, 0, 0, 5, 0),
        );

        $output = (new MarkdownReporter())->render($data);

        self::assertStringContainsString('| Analysis coverage | N/A |', $output);
    }

    public function testOmitsFileCoverageWhenUnavailable(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new MarkdownReporter())->render($data);

        self::assertStringNotContainsString('| Analysis coverage |', $output);
        self::assertStringNotContainsString('| Discovered PHP files |', $output);
    }

    private function metrics(
        string $name,
        int $ca,
        int $ce,
        float $instability,
        float $abstractness,
        float $distance,
        Zone $zone,
        string $fqcn,
        bool $dependencyMetricsEvaluable = true,
    ): ComponentMetrics {
        return new ComponentMetrics(
            new Component($name, [new ClassInfo($fqcn, TypeKind::ConcreteClass, '/dummy.php', [])]),
            $ca,
            $ce,
            $instability,
            $abstractness,
            $distance,
            $zone,
            $dependencyMetricsEvaluable,
        );
    }
}

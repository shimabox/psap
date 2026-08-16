<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Psap\Analyzer\AnalysisCoverage;
use Psap\Analyzer\ClassInfo;
use Psap\Analyzer\DependencyKind;
use Psap\Analyzer\TypeKind;
use Psap\Baseline\CycleBaselineComparison;
use Psap\Component\Component;
use Psap\Component\DependencyGraph;
use Psap\Component\OutOfScopeDependencyComponent;
use Psap\Component\OutOfScopeDependencyEvidence;
use Psap\Component\OutOfScopeDependencyGroup;
use Psap\Component\OutOfScopeDependencyReport;
use Psap\Diagnostic\Diagnostic;
use Psap\Diagnostic\DiagnosticAction;
use Psap\Diagnostic\DiagnosticCode;
use Psap\Diagnostic\DiagnosticSeverity;
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

    public function testRendersStructuredDiagnosticsInEnglish(): void
    {
        $data = new ReportData(
            [],
            MetricsSummary::from([]),
            [],
            diagnostics: [new Diagnostic(
                code: DiagnosticCode::SourceParseFailed,
                severity: DiagnosticSeverity::Warning,
                file: 'src/Broken.php',
                line: 7,
                context: ['detail' => 'Unexpected token.'],
                actions: [DiagnosticAction::FixSource, DiagnosticAction::ExcludeFile],
            )],
        );

        $output = (new MarkdownReporter())->render($data);

        self::assertStringContainsString('## Diagnostics', $output);
        self::assertStringContainsString('**Warning** `source.parse_failed`', $output);
        self::assertStringContainsString('src/Broken.php:7', $output);
        self::assertStringContainsString('Fix the PHP source code.', $output);
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

    public function testRendersOutOfScopeDependenciesWithGroupTablesAndEvidence(): void
    {
        $data = new ReportData(
            [],
            MetricsSummary::from([]),
            [],
            outOfScopeDependencies: $this->outOfScopeReport(),
        );

        $output = (new MarkdownReporter())->render($data);

        self::assertStringContainsString('## Dependencies outside analysis scope', $output);
        self::assertStringContainsString('Distinct classes outside the analysis scope 3 in 2 namespaces.', $output);
        self::assertStringContainsString('| Namespace | Classes | Referencing classes |', $output);
        self::assertStringContainsString('| `Vendor\\Support\\Facades` | 2 | 2 |', $output);
        self::assertStringContainsString('| `(global)` | 1 | 1 |', $output);
        self::assertStringContainsString('### `App\\Http`', $output);
        self::assertStringContainsString(
            '- `App\\Http\\Controller` to `Vendor\\Support\\Facades\\DB` using `static_call` at `Http/Controller.php:12`',
            $output,
        );
    }

    public function testLimitsOutOfScopeEvidenceToThreePerGroup(): void
    {
        $evidence = [];
        for ($line = 1; $line <= 5; $line++) {
            $evidence[] = new OutOfScopeDependencyEvidence(
                'App\\Http\\Controller',
                'Vendor\\Support\\Facades\\DB',
                DependencyKind::StaticCall,
                'Http/Controller.php',
                $line,
            );
        }
        $group = new OutOfScopeDependencyGroup(
            'Vendor\\Support\\Facades',
            ['Vendor\\Support\\Facades\\DB'],
            ['App\\Http\\Controller'],
            $evidence,
        );
        $data = new ReportData(
            [],
            MetricsSummary::from([]),
            [],
            outOfScopeDependencies: new OutOfScopeDependencyReport(
                [$group],
                [new OutOfScopeDependencyComponent('App\\Http', [$group])],
            ),
        );

        $output = (new MarkdownReporter())->render($data);

        self::assertStringContainsString('at `Http/Controller.php:3`', $output);
        self::assertStringNotContainsString('at `Http/Controller.php:4`', $output);
        self::assertStringContainsString('- 2 additional source locations omitted', $output);
    }

    public function testRendersOutOfScopeSectionWithZeroWhenNothingIsOutOfScope(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new MarkdownReporter())->render($data);

        self::assertStringContainsString("## Dependencies outside analysis scope\n\n0 (None)", $output);
    }

    private function outOfScopeReport(): OutOfScopeDependencyReport
    {
        return new OutOfScopeDependencyReport(
            groups: [
                new OutOfScopeDependencyGroup(
                    'Vendor\\Support\\Facades',
                    ['Vendor\\Support\\Facades\\Cache', 'Vendor\\Support\\Facades\\DB'],
                    ['App\\Domain\\Order', 'App\\Http\\Controller'],
                    [],
                ),
                new OutOfScopeDependencyGroup('(global)', ['GlobalFacade'], ['App\\Http\\Controller'], []),
            ],
            components: [
                new OutOfScopeDependencyComponent('App\\Http', [
                    new OutOfScopeDependencyGroup(
                        'Vendor\\Support\\Facades',
                        ['Vendor\\Support\\Facades\\DB'],
                        ['App\\Http\\Controller'],
                        [new OutOfScopeDependencyEvidence(
                            'App\\Http\\Controller',
                            'Vendor\\Support\\Facades\\DB',
                            DependencyKind::StaticCall,
                            'Http/Controller.php',
                            12,
                        )],
                    ),
                    new OutOfScopeDependencyGroup('(global)', ['GlobalFacade'], ['App\\Http\\Controller'], []),
                ]),
            ],
        );
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

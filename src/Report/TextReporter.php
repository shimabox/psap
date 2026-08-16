<?php

declare(strict_types=1);

namespace Psap\Report;

use Psap\Analyzer\ClassInfo;
use Psap\Component\OutOfScopeDependencyGroup;
use Psap\Diagnostic\DiagnosticFormatter;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\Zone;

/**
 * 人間向けのテキスト表レポート。
 *
 * コンポーネント一覧を表形式で出し、苦痛ゾーン・無駄ゾーン該当コンポーネントには警告と
 * 所属クラス一覧を表示する。verbose=true のときは全コンポーネントのクラス一覧を表示する。
 */
final class TextReporter implements ReporterInterface
{
    private const int CYCLE_EVIDENCE_LIMIT = 3;
    private const int DEPENDENCY_SOURCE_LIMIT = 3;

    /** 解析対象外依存のサマリで表示する名前空間グループの上限（verbose では全件） */
    private const int OUT_OF_SCOPE_GROUP_LIMIT = 5;

    /** verbose のときに名前空間グループごとに表示する証拠の上限 */
    private const int OUT_OF_SCOPE_EVIDENCE_LIMIT = 3;

    /** ゾーン警告なしの通常行に合わせて数値列の幅を揃えるための最小幅（"0.00" 形式は常に4桁） */
    private const int DECIMAL_COLUMN_WIDTH = 4;

    /** 表の Zone 列の区切り線の長さ（見た目上の目安。値そのものは padding しない） */
    private const int ZONE_SEPARATOR_WIDTH = 10;

    public function __construct(
        private readonly bool $verbose = false,
    ) {
    }

    public function render(ReportData $data): string
    {
        $lines = [];
        $lines[] = 'psap - Stable Abstractions Principle metrics';
        if ($data->namespaceDepth !== null) {
            $lines[] = sprintf('Namespace depth: %d', $data->namespaceDepth);
        }
        if ($data->analysisCoverage !== null) {
            $ratio = $data->analysisCoverage->ratio();
            $lines[] = sprintf(
                'Analysis coverage: %s (%s/%s selected files)',
                $ratio === null ? 'N/A' : sprintf('%.2f%%', $ratio * 100),
                number_format($data->analysisCoverage->analyzed),
                number_format($data->analysisCoverage->selected),
            );
            $lines[] = sprintf(
                'Files: discovered=%s, analyzed=%s, excluded=%s, skipped=%s',
                number_format($data->analysisCoverage->discovered),
                number_format($data->analysisCoverage->analyzed),
                number_format($data->analysisCoverage->excluded),
                number_format($data->analysisCoverage->skipped),
            );
        }
        $lines[] = '';

        $widths = $this->calculateColumnWidths($data->componentMetrics);
        $lines[] = $this->headerLine($widths);
        $lines[] = $this->separatorLine($widths);

        foreach ($data->componentMetrics as $metrics) {
            $lines[] = $this->rowLine($metrics, $widths);
        }

        $lines[] = '';
        $lines[] = $data->summary->meanDistance === null || $data->summary->varianceDistance === null
            ? 'Statistics: mean(D)=N/A, variance(D)=N/A'
            : sprintf(
                'Statistics: mean(D)=%.2f, variance(D)=%.2f',
                $data->summary->meanDistance,
                $data->summary->varianceDistance,
            );

        if ($data->cycles !== []) {
            $lines[] = '';
            $lines[] = 'Cycles (ADP violation):';
            foreach ($data->cycleGroups() as $index => $cycle) {
                $lines[] = sprintf(
                    '  - Cycle %d (%d components, %s namespaces)',
                    $index + 1,
                    $cycle['componentCount'],
                    $cycle['namespaceRelation'],
                );
                $lines[] = '    Components: ' . implode(', ', $cycle['components']);
                $lines[] = '    Representative shortest path: ' . implode(' -> ', $cycle['representativePath']);
                if ($cycle['omittedComponents'] !== []) {
                    $lines[] = sprintf(
                        '    Omitted from path (%d): %s',
                        count($cycle['omittedComponents']),
                        implode(', ', $cycle['omittedComponents']),
                    );
                }
                $lines[] = '    Dependency evidence:';
                foreach ($cycle['dependencies'] as $dependency) {
                    $lines[] = sprintf('      %s -> %s', $dependency['from'], $dependency['to']);
                    $visibleEvidence = array_slice($dependency['classDependencies'], 0, self::CYCLE_EVIDENCE_LIMIT);
                    foreach ($visibleEvidence as $classDependency) {
                        $lines[] = sprintf(
                            '        - %s -> %s',
                            $classDependency['from'],
                            $classDependency['to'],
                        );
                        $visibleSources = array_slice(
                            $classDependency['evidence'],
                            0,
                            self::DEPENDENCY_SOURCE_LIMIT,
                        );
                        foreach ($visibleSources as $source) {
                            $lines[] = sprintf(
                                '          %s at %s:%d',
                                $source['kind'],
                                $source['file'],
                                $source['line'],
                            );
                        }
                        $remainingSources = count($classDependency['evidence']) - count($visibleSources);
                        if ($remainingSources > 0) {
                            $lines[] = sprintf('          ... and %d more sources', $remainingSources);
                        }
                    }

                    $remaining = count($dependency['classDependencies']) - count($visibleEvidence);
                    if ($remaining > 0) {
                        $lines[] = sprintf('        - ... and %d more', $remaining);
                    }
                }
            }
        }

        if ($data->cycleBaselineComparison !== null) {
            $lines[] = '';
            $lines[] = 'Cycle baseline comparison:';
            $lines[] = sprintf('  New cycles: %d', count($data->cycleBaselineComparison->newCycles));
            foreach ($data->cycleBaselineComparison->newCycles as $cycle) {
                $lines[] = '    + ' . implode(', ', $cycle);
            }
            $lines[] = sprintf('  Resolved cycles: %d', count($data->cycleBaselineComparison->resolvedCycles));
            foreach ($data->cycleBaselineComparison->resolvedCycles as $cycle) {
                $lines[] = '    - ' . implode(', ', $cycle);
            }
        }

        $lines = [...$lines, ...$this->outOfScopeDependencies($data)];

        foreach ($data->componentMetrics as $metrics) {
            if (!$this->verbose && $metrics->zone === Zone::None) {
                continue;
            }

            $lines[] = '';
            $lines[] = sprintf('Classes in %s:', $metrics->component->name);
            foreach ($metrics->component->classInfos as $classInfo) {
                $lines[] = $this->classLine($classInfo);
            }
        }

        if ($data->diagnostics !== []) {
            $lines[] = '';
            $lines[] = 'Diagnostics:';
            $formatter = new DiagnosticFormatter('en');
            foreach ($data->diagnostics as $diagnostic) {
                $lines[] = sprintf('  - [%s] %s', $diagnostic->severity->value, $formatter->format($diagnostic));
            }
        }

        if ($data->warnings !== []) {
            $lines[] = '';
            $lines[] = 'Warnings:';
            foreach ($data->warnings as $warning) {
                $lines[] = '  - ' . $warning;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 解析対象外依存。通常は全体サマリと上位グループのみ、verbose ではコンポーネント別詳細と証拠も出す。
     * 0件でもサマリ行は必ず出す。
     *
     * @return list<string>
     */
    private function outOfScopeDependencies(ReportData $data): array
    {
        $report = $data->outOfScopeDependencies;

        $lines = [
            '',
            sprintf(
                'Dependencies outside analysis scope: %s in %s',
                $this->plural($report->dependencyCount(), 'class', 'classes'),
                $this->plural($report->groupCount(), 'namespace', 'namespaces'),
            ),
        ];

        if ($report->isEmpty()) {
            return $lines;
        }

        $visibleGroups = $this->verbose
            ? $report->groups
            : array_slice($report->groups, 0, self::OUT_OF_SCOPE_GROUP_LIMIT);
        foreach ($visibleGroups as $group) {
            $lines[] = '  ' . $this->outOfScopeGroupLine($group);
        }
        $remainingGroups = count($report->groups) - count($visibleGroups);
        if ($remainingGroups > 0) {
            $lines[] = sprintf('  ... and %d more namespaces', $remainingGroups);
        }

        if (!$this->verbose) {
            return $lines;
        }

        foreach ($report->components as $component) {
            $lines[] = '';
            $lines[] = sprintf('  %s (%s):', $component->name, $this->plural($component->dependencyCount(), 'class', 'classes'));
            foreach ($component->groups as $group) {
                $lines[] = '    ' . $this->outOfScopeGroupLine($group);
                $visibleEvidence = array_slice($group->evidence, 0, self::OUT_OF_SCOPE_EVIDENCE_LIMIT);
                foreach ($visibleEvidence as $evidence) {
                    $lines[] = sprintf(
                        '        %s -> %s (%s) at %s:%d',
                        $evidence->sourceFqcn,
                        $evidence->targetFqcn,
                        $evidence->kind->value,
                        $evidence->file,
                        $evidence->line,
                    );
                }
                $remainingEvidence = count($group->evidence) - count($visibleEvidence);
                if ($remainingEvidence > 0) {
                    $lines[] = sprintf('        ... and %d more sources', $remainingEvidence);
                }
            }
        }

        return $lines;
    }

    private function outOfScopeGroupLine(OutOfScopeDependencyGroup $group): string
    {
        return sprintf(
            '- %s: %s referenced by %s',
            $group->namespace,
            $this->plural($group->dependencyCount(), 'class', 'classes'),
            $this->plural($group->sourceCount(), 'class', 'classes'),
        );
    }

    private function plural(int $count, string $singular, string $plural): string
    {
        return sprintf('%d %s', $count, $count === 1 ? $singular : $plural);
    }

    private function classLine(ClassInfo $classInfo): string
    {
        return sprintf('  - %s (%s)', $classInfo->fqcn, $classInfo->kind->label());
    }

    /**
     * @param list<ComponentMetrics> $componentMetrics
     * @return array{name: int, classes: int, ca: int, ce: int, decimal: int}
     */
    private function calculateColumnWidths(array $componentMetrics): array
    {
        $nameWidth = mb_strlen('Component');
        $classesWidth = mb_strlen('Classes');
        $caWidth = mb_strlen('Ca');
        $ceWidth = mb_strlen('Ce');

        foreach ($componentMetrics as $metrics) {
            $nameWidth = max($nameWidth, mb_strlen($metrics->component->name));
            $classesWidth = max($classesWidth, mb_strlen((string) count($metrics->component->classInfos)));
            $caWidth = max($caWidth, mb_strlen($metrics->dependencyMetricsEvaluable ? (string) $metrics->ca : 'N/A'));
            $ceWidth = max($ceWidth, mb_strlen($metrics->dependencyMetricsEvaluable ? (string) $metrics->ce : 'N/A'));
        }

        return [
            'name' => $nameWidth,
            'classes' => $classesWidth,
            'ca' => $caWidth,
            'ce' => $ceWidth,
            'decimal' => self::DECIMAL_COLUMN_WIDTH,
        ];
    }

    /**
     * @param array{name: int, classes: int, ca: int, ce: int, decimal: int} $widths
     */
    private function headerLine(array $widths): string
    {
        return sprintf(
            '%-' . $widths['name'] . 's  %' . $widths['classes'] . 's  %' . $widths['ca'] . 's  %' . $widths['ce'] . 's  %' . $widths['decimal'] . 's  %' . $widths['decimal'] . 's  %' . $widths['decimal'] . 's  Zone',
            'Component',
            'Classes',
            'Ca',
            'Ce',
            'I',
            'A',
            'D',
        );
    }

    /**
     * @param array{name: int, classes: int, ca: int, ce: int, decimal: int} $widths
     */
    private function separatorLine(array $widths): string
    {
        return implode('  ', [
            str_repeat('-', $widths['name']),
            str_repeat('-', $widths['classes']),
            str_repeat('-', $widths['ca']),
            str_repeat('-', $widths['ce']),
            str_repeat('-', $widths['decimal']),
            str_repeat('-', $widths['decimal']),
            str_repeat('-', $widths['decimal']),
            str_repeat('-', self::ZONE_SEPARATOR_WIDTH),
        ]);
    }

    /**
     * @param array{name: int, classes: int, ca: int, ce: int, decimal: int} $widths
     */
    private function rowLine(ComponentMetrics $metrics, array $widths): string
    {
        $ca = $metrics->dependencyMetricsEvaluable ? (string) $metrics->ca : 'N/A';
        $ce = $metrics->dependencyMetricsEvaluable ? (string) $metrics->ce : 'N/A';
        $instability = $metrics->dependencyMetricsEvaluable ? sprintf('%.2f', $metrics->instability) : 'N/A';
        $distance = $metrics->dependencyMetricsEvaluable ? sprintf('%.2f', $metrics->distance) : 'N/A';

        $row = sprintf(
            '%-' . $widths['name'] . 's  %' . $widths['classes'] . 'd  %' . $widths['ca'] . 's  %' . $widths['ce'] . 's  %' . $widths['decimal'] . 's  %' . $widths['decimal'] . '.2f  %' . $widths['decimal'] . 's',
            $metrics->component->name,
            count($metrics->component->classInfos),
            $ca,
            $ce,
            $instability,
            $metrics->abstractness,
            $distance,
        );

        if ($metrics->zone !== Zone::None) {
            $row .= '  ⚠ ' . $metrics->zone->label();
        }

        return $row;
    }
}

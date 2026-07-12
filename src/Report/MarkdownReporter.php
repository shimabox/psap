<?php

declare(strict_types=1);

namespace Bobsap\Report;

use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\Zone;

/**
 * 生成AIと人間のレビューに必要な情報を優先度順にまとめるMarkdownレポート。
 */
final class MarkdownReporter implements ReporterInterface
{
    private const int HOTSPOT_LIMIT = 10;
    private const int HOTSPOT_EXAMPLE_LIMIT = 3;
    private const int CYCLE_CLASS_DEPENDENCY_LIMIT = 10;
    private const int CYCLE_EVIDENCE_LIMIT = 5;

    public function render(ReportData $data): string
    {
        return implode("\n", [
            ...$this->summary($data),
            ...$this->priorities($data),
            ...$this->baselineChanges($data),
            ...$this->cycles($data),
            ...$this->dependencyHotspots($data),
            ...$this->metrics($data),
            ...$this->warnings($data),
            ...$this->interpretationNotes(),
        ]);
    }

    /** @return list<string> */
    private function summary(ReportData $data): array
    {
        $typeCount = array_sum(array_map(
            static fn (ComponentMetrics $metrics): int => count($metrics->component->classInfos),
            $data->componentMetrics,
        ));

        $lines = [
            '# bobsap Architecture Analysis',
            '',
            '## Analysis Summary',
            '',
            '| Item | Value |',
            '|---|---:|',
            sprintf('| Components | %d |', count($data->componentMetrics)),
            sprintf('| Types | %d |', $typeCount),
            sprintf('| Component dependencies | %d |', count($data->dependencyGraph->edges)),
            sprintf('| Cycle groups | %d |', count($data->cycles)),
            sprintf('| Namespace depth | %s |', $data->namespaceDepth === null ? 'N/A' : (string) $data->namespaceDepth),
            sprintf('| Dependency metrics evaluable | %s |', $data->summary->meanDistance === null ? 'No' : 'Yes'),
            '',
            '### Analysis Context',
            '',
            '- Source paths ' . $this->codeList($data->sourcePaths),
            '- Docblock dependencies ' . ($data->docblockEnabled ? 'enabled' : 'disabled'),
            '- Exclude patterns ' . $this->codeList($data->excludePatterns),
            '',
        ];

        return $lines;
    }

    /** @return list<string> */
    private function priorities(ReportData $data): array
    {
        $lines = ['## Review Priorities', ''];
        $priorityCount = 0;

        if ($data->cycleBaselineComparison !== null && $data->cycleBaselineComparison->newCycles !== []) {
            foreach ($data->cycleBaselineComparison->newCycles as $cycle) {
                $lines[] = sprintf(
                    '%d. New cycle not present in the baseline involving %s.',
                    ++$priorityCount,
                    $this->codeList($cycle),
                );
            }
        } elseif ($data->cycles !== []) {
            $lines[] = sprintf(
                '%d. Review %d circular dependency group%s. Concrete paths and source locations are listed below.',
                ++$priorityCount,
                count($data->cycles),
                count($data->cycles) === 1 ? '' : 's',
            );
        }

        foreach ($this->zoneMetrics($data) as $metrics) {
            $lines[] = sprintf(
                '%d. Review %s in the %s with D=%s.',
                ++$priorityCount,
                $this->code($metrics->component->name),
                $metrics->zone === Zone::Pain ? 'pain zone' : 'uselessness zone',
                $this->decimal($metrics->distance),
            );
        }

        if ($priorityCount === 0) {
            $lines[] = 'No circular dependencies or SAP zone violations were detected.';
        }

        $lines[] = '';

        return $lines;
    }

    /** @return list<ComponentMetrics> */
    private function zoneMetrics(ReportData $data): array
    {
        $metrics = array_values(array_filter(
            $data->componentMetrics,
            static fn (ComponentMetrics $item): bool => $item->zone !== Zone::None,
        ));
        usort($metrics, static fn (ComponentMetrics $a, ComponentMetrics $b): int => $b->distance <=> $a->distance);

        return $metrics;
    }

    /** @return list<string> */
    private function baselineChanges(ReportData $data): array
    {
        if ($data->cycleBaselineComparison === null) {
            return [];
        }

        $lines = [
            '## Cycle Baseline Changes',
            '',
            sprintf('- New cycle groups %d', count($data->cycleBaselineComparison->newCycles)),
            sprintf('- Resolved cycle groups %d', count($data->cycleBaselineComparison->resolvedCycles)),
        ];
        foreach ($data->cycleBaselineComparison->newCycles as $cycle) {
            $lines[] = '- New ' . $this->codeList($cycle);
        }
        foreach ($data->cycleBaselineComparison->resolvedCycles as $cycle) {
            $lines[] = '- Resolved ' . $this->codeList($cycle);
        }
        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private function cycles(ReportData $data): array
    {
        if ($data->cycles === []) {
            return [];
        }

        $lines = ['## Circular Dependencies', ''];
        foreach ($data->cycleGroups() as $index => $cycle) {
            $lines[] = sprintf('### Cycle %d', $index + 1);
            $lines[] = '';
            $lines[] = '- Components ' . $this->codeList($cycle['components']);
            $lines[] = '- Namespace relation ' . $cycle['namespaceRelation'];
            $lines[] = '- Representative shortest path ' . $this->path($cycle['representativePath']);
            if ($cycle['omittedComponents'] !== []) {
                $lines[] = '- Components outside the representative path ' . $this->codeList($cycle['omittedComponents']);
            }
            $lines[] = '';

            foreach ($cycle['dependencies'] as $dependency) {
                $lines[] = sprintf(
                    '#### %s to %s',
                    $this->code($dependency['from']),
                    $this->code($dependency['to']),
                );
                $lines[] = '';
                $visibleDependencies = array_slice(
                    $dependency['classDependencies'],
                    0,
                    self::CYCLE_CLASS_DEPENDENCY_LIMIT,
                );
                foreach ($visibleDependencies as $classDependency) {
                    $lines[] = sprintf(
                        '- %s to %s',
                        $this->code($classDependency['from']),
                        $this->code($classDependency['to']),
                    );
                    $visibleEvidence = array_slice($classDependency['evidence'], 0, self::CYCLE_EVIDENCE_LIMIT);
                    foreach ($visibleEvidence as $evidence) {
                        $lines[] = sprintf(
                            '  - `%s` at %s',
                            $evidence['kind'],
                            $this->location($evidence['file'], $evidence['line']),
                        );
                    }
                    $remainingEvidence = count($classDependency['evidence']) - count($visibleEvidence);
                    if ($remainingEvidence > 0) {
                        $lines[] = sprintf('  - %d additional source location%s omitted', $remainingEvidence, $remainingEvidence === 1 ? '' : 's');
                    }
                }
                $remainingDependencies = count($dependency['classDependencies']) - count($visibleDependencies);
                if ($remainingDependencies > 0) {
                    $lines[] = sprintf('- %d additional class dependenc%s omitted', $remainingDependencies, $remainingDependencies === 1 ? 'y' : 'ies');
                }
                $lines[] = '';
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function dependencyHotspots(ReportData $data): array
    {
        if ($data->dependencyGraph->edgeDetails === []) {
            return [];
        }

        $dependencies = $data->dependencyGraph->edgeDetails;
        usort($dependencies, static function (array $a, array $b): int {
            $byCount = count($b['classDependencies']) <=> count($a['classDependencies']);

            return $byCount !== 0 ? $byCount : [$a['from'], $a['to']] <=> [$b['from'], $b['to']];
        });
        $visibleDependencies = array_slice($dependencies, 0, self::HOTSPOT_LIMIT);

        $lines = ['## Dependency Hotspots', ''];
        foreach ($visibleDependencies as $dependency) {
            $lines[] = sprintf(
                '### %s to %s',
                $this->code($dependency['from']),
                $this->code($dependency['to']),
            );
            $lines[] = '';
            $lines[] = sprintf('Class dependencies %d', count($dependency['classDependencies']));
            $lines[] = '';
            foreach (array_slice($dependency['classDependencies'], 0, self::HOTSPOT_EXAMPLE_LIMIT) as $classDependency) {
                $locations = array_map(
                    fn (array $evidence): string => sprintf(
                        '`%s` at %s',
                        $evidence['kind'],
                        $this->location($evidence['file'], $evidence['line']),
                    ),
                    array_slice($classDependency['evidence'], 0, 2),
                );
                $suffix = $locations === [] ? '' : ' using ' . implode(', ', $locations);
                $lines[] = sprintf(
                    '- %s to %s%s',
                    $this->code($classDependency['from']),
                    $this->code($classDependency['to']),
                    $suffix,
                );
            }
            $lines[] = '';
        }

        $remaining = count($dependencies) - count($visibleDependencies);
        if ($remaining > 0) {
            $lines[] = sprintf('%d additional component dependencies omitted.', $remaining);
            $lines[] = '';
        }

        return $lines;
    }

    /** @return list<string> */
    private function metrics(ReportData $data): array
    {
        $lines = [
            '## Component Metrics',
            '',
            '| Component | Types | Ca | Ce | I | A | D | Zone |',
            '|---|---:|---:|---:|---:|---:|---:|---|',
        ];
        foreach ($data->componentMetrics as $metrics) {
            $evaluable = $metrics->dependencyMetricsEvaluable;
            $lines[] = sprintf(
                '| %s | %d | %s | %s | %s | %s | %s | %s |',
                $this->code($metrics->component->name),
                count($metrics->component->classInfos),
                $evaluable ? (string) $metrics->ca : 'N/A',
                $evaluable ? (string) $metrics->ce : 'N/A',
                $evaluable ? $this->decimal($metrics->instability) : 'N/A',
                $this->decimal($metrics->abstractness),
                $evaluable ? $this->decimal($metrics->distance) : 'N/A',
                match ($metrics->zone) {
                    Zone::None => '',
                    Zone::Pain => 'pain',
                    Zone::Useless => 'uselessness',
                },
            );
        }
        $lines[] = '';
        $lines[] = $data->summary->meanDistance === null || $data->summary->varianceDistance === null
            ? 'Mean D and variance D are not evaluable.'
            : sprintf(
                'Mean D %s. Variance D %s.',
                $this->decimal($data->summary->meanDistance),
                $this->decimal($data->summary->varianceDistance),
            );
        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private function warnings(ReportData $data): array
    {
        if ($data->warnings === []) {
            return [];
        }

        return [
            '## Warnings',
            '',
            ...array_map(static fn (string $warning): string => '- ' . $warning, $data->warnings),
            '',
        ];
    }

    /** @return list<string> */
    private function interpretationNotes(): array
    {
        return [
            '## Interpretation Notes',
            '',
            '- Ca measures incoming dependencies and Ce measures outgoing dependencies.',
            '- I is instability, A is abstractness, and D is distance from the main sequence.',
            '- A cycle group is a strongly connected component. The representative path is one shortest concrete loop and may omit other members.',
            '- Source evidence identifies why a class dependency exists. Multiple syntax kinds or locations can support the same dependency.',
        ];
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function code(string $value): string
    {
        return str_contains($value, '`') ? '`` ' . $value . ' ``' : '`' . $value . '`';
    }

    /** @param list<string> $values */
    private function codeList(array $values): string
    {
        return $values === [] ? 'none' : implode(', ', array_map($this->code(...), $values));
    }

    /** @param list<string> $components */
    private function path(array $components): string
    {
        return $components === [] ? 'unavailable' : implode(' -> ', array_map($this->code(...), $components));
    }

    private function location(string $file, int $line): string
    {
        return $this->code($file . ':' . $line);
    }
}

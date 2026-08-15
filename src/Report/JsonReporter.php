<?php

declare(strict_types=1);

namespace Psap\Report;

use JsonException;
use Psap\Analyzer\ClassInfo;
use Psap\Component\OutOfScopeDependencyComponent;
use Psap\Component\OutOfScopeDependencyEvidence;
use Psap\Component\OutOfScopeDependencyGroup;
use Psap\Diagnostic\Diagnostic;
use Psap\Diagnostic\DiagnosticAction;
use Psap\Diagnostic\DiagnosticFormatter;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\Zone;

/**
 * 機械可読な JSON レポート。
 *
 * text と異なり、常に全コンポーネント・全クラスを出力する（フィルタリングは呼び出し側の責務）。
 */
final class JsonReporter implements ReporterInterface
{
    /** D 値・I 値・A 値の丸め桁数（浮動小数点誤差でノイズが出ないようにする） */
    private const int ROUND_PRECISION = 4;

    public function render(ReportData $data): string
    {
        $payload = [
            'summary' => [
                'componentCount' => count($data->componentMetrics),
                'namespaceDepth' => $data->namespaceDepth,
                'metricsEvaluable' => $data->summary->meanDistance !== null,
                'meanDistance' => $this->rounded($data->summary->meanDistance),
                'varianceDistance' => $this->rounded($data->summary->varianceDistance),
            ],
            'components' => array_map($this->componentPayload(...), $data->componentMetrics),
            'dependencies' => $data->dependencyGraph->edgeDetails,
            'outOfScopeDependencies' => $this->outOfScopeDependenciesPayload($data),
            'cycles' => $data->cycles,
            'cyclePaths' => $data->cyclePathDetails(),
            'cycleGroups' => $data->cycleGroups(),
            'cycleBaselineComparison' => $data->cycleBaselineComparison === null ? null : [
                'hasChanges' => $data->cycleBaselineComparison->hasChanges(),
                'newCycles' => $data->cycleBaselineComparison->newCycles,
                'resolvedCycles' => $data->cycleBaselineComparison->resolvedCycles,
            ],
            'fileCoverage' => $data->analysisCoverage === null ? null : [
                'discovered' => $data->analysisCoverage->discovered,
                'selected' => $data->analysisCoverage->selected,
                'analyzed' => $data->analysisCoverage->analyzed,
                'excluded' => $data->analysisCoverage->excluded,
                'skipped' => $data->analysisCoverage->skipped,
                'analysisCoverage' => $data->analysisCoverage->ratio(),
            ],
            'diagnostics' => array_map($this->diagnosticPayload(...), $data->diagnostics),
            'warnings' => $this->compatibilityWarnings($data),
        ];

        try {
            return json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            // ここに到達するのは payload が JSON にエンコードできない場合のみで、
            // 上記の構築内容では通常起こり得ない
            throw new \RuntimeException('レポートの JSON エンコードに失敗しました: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array{
     *     name: string,
     *     classCount: int,
     *     metricsEvaluable: bool,
     *     ca: int|null,
     *     ce: int|null,
     *     instability: float|null,
     *     abstractness: float,
     *     distance: float|null,
     *     zone: string|null,
     *     classes: list<array{fqcn: string, kind: string}>,
     * }
     */
    private function componentPayload(ComponentMetrics $metrics): array
    {
        return [
            'name' => $metrics->component->name,
            'classCount' => count($metrics->component->classInfos),
            'metricsEvaluable' => $metrics->dependencyMetricsEvaluable,
            'ca' => $metrics->dependencyMetricsEvaluable ? $metrics->ca : null,
            'ce' => $metrics->dependencyMetricsEvaluable ? $metrics->ce : null,
            'instability' => $metrics->dependencyMetricsEvaluable ? round($metrics->instability, self::ROUND_PRECISION) : null,
            'abstractness' => round($metrics->abstractness, self::ROUND_PRECISION),
            'distance' => $metrics->dependencyMetricsEvaluable ? round($metrics->distance, self::ROUND_PRECISION) : null,
            'zone' => $this->zoneValue($metrics->zone),
            'classes' => array_map($this->classPayload(...), $metrics->component->classInfos),
        ];
    }

    /**
     * 解析対象外依存。0件でもキーと空配列を必ず出す（スキーマを安定させるため）。
     *
     * `dependencyCount` はプロジェクト全体で distinct な FQCN 数であり、
     * `components[].dependencyCount` の単純合計とは一致しない（同じ FQCN を
     * 複数コンポーネントから参照していても全体では1件と数えるため）。
     * 証拠は `components[].groups[].evidence` に全量が入る（`groups` は全体サマリなので持たない）。
     *
     * @return array{
     *     dependencyCount: int,
     *     groupCount: int,
     *     groups: list<array{namespace: string, dependencyCount: int, sourceCount: int, targets: list<string>}>,
     *     components: list<array{
     *         name: string,
     *         dependencyCount: int,
     *         groupCount: int,
     *         groups: list<array{
     *             namespace: string,
     *             dependencyCount: int,
     *             sourceCount: int,
     *             targets: list<string>,
     *             sources: list<string>,
     *             evidence: list<array{sourceFqcn: string, targetFqcn: string, kind: string, file: string, line: int}>,
     *         }>,
     *     }>,
     * }
     */
    private function outOfScopeDependenciesPayload(ReportData $data): array
    {
        $report = $data->outOfScopeDependencies;

        return [
            'dependencyCount' => $report->dependencyCount(),
            'groupCount' => $report->groupCount(),
            'groups' => array_map(
                static fn (OutOfScopeDependencyGroup $group): array => [
                    'namespace' => $group->namespace,
                    'dependencyCount' => $group->dependencyCount(),
                    'sourceCount' => $group->sourceCount(),
                    'targets' => $group->targetFqcns,
                ],
                $report->groups,
            ),
            'components' => array_map(
                fn (OutOfScopeDependencyComponent $component): array => [
                    'name' => $component->name,
                    'dependencyCount' => $component->dependencyCount(),
                    'groupCount' => $component->groupCount(),
                    'groups' => array_map($this->outOfScopeGroupPayload(...), $component->groups),
                ],
                $report->components,
            ),
        ];
    }

    /**
     * @return array{
     *     namespace: string,
     *     dependencyCount: int,
     *     sourceCount: int,
     *     targets: list<string>,
     *     sources: list<string>,
     *     evidence: list<array{sourceFqcn: string, targetFqcn: string, kind: string, file: string, line: int}>,
     * }
     */
    private function outOfScopeGroupPayload(OutOfScopeDependencyGroup $group): array
    {
        return [
            'namespace' => $group->namespace,
            'dependencyCount' => $group->dependencyCount(),
            'sourceCount' => $group->sourceCount(),
            'targets' => $group->targetFqcns,
            'sources' => $group->sourceFqcns,
            'evidence' => array_map(
                static fn (OutOfScopeDependencyEvidence $evidence): array => [
                    'sourceFqcn' => $evidence->sourceFqcn,
                    'targetFqcn' => $evidence->targetFqcn,
                    'kind' => $evidence->kind->value,
                    'file' => $evidence->file,
                    'line' => $evidence->line,
                ],
                $group->evidence,
            ),
        ];
    }

    private function rounded(?float $value): ?float
    {
        return $value === null ? null : round($value, self::ROUND_PRECISION);
    }

    /**
     * @return array{
     *     code: string,
     *     severity: string,
     *     file: string|null,
     *     line: int|null,
     *     message: string,
     *     context: object,
     *     actions: list<array{code: string, option?: string}>,
     * }
     */
    private function diagnosticPayload(Diagnostic $diagnostic): array
    {
        $formatter = new DiagnosticFormatter('en');

        return [
            'code' => $diagnostic->code->value,
            'severity' => $diagnostic->severity->value,
            'file' => $diagnostic->file,
            'line' => $diagnostic->line,
            'message' => $formatter->message($diagnostic),
            'context' => (object) $diagnostic->context,
            'actions' => array_map(
                static fn (DiagnosticAction $action): array => $action === DiagnosticAction::ExcludeFile
                    ? ['code' => $action->value, 'option' => '--exclude']
                    : ['code' => $action->value],
                $diagnostic->actions,
            ),
        ];
    }

    /** @return list<string> */
    private function compatibilityWarnings(ReportData $data): array
    {
        $formatter = new DiagnosticFormatter('ja');
        $diagnosticWarnings = array_map($formatter->format(...), $data->diagnostics);

        return array_values(array_unique([...$data->warnings, ...$diagnosticWarnings]));
    }

    private function zoneValue(Zone $zone): ?string
    {
        return match ($zone) {
            Zone::None => null,
            Zone::Pain => 'pain',
            Zone::Useless => 'useless',
        };
    }

    /**
     * @return array{fqcn: string, kind: string}
     */
    private function classPayload(ClassInfo $classInfo): array
    {
        return [
            'fqcn' => $classInfo->fqcn,
            'kind' => $classInfo->kind->label(),
        ];
    }
}

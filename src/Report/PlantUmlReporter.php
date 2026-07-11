<?php

declare(strict_types=1);

namespace Bobsap\Report;

use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\Zone;

/**
 * PlantUML でコンポーネント依存グラフを出力するレポーター。
 *
 * ノード = コンポーネント（ラベルに I/A/D を併記し、ゾーンごとに色分け）。
 * エッジ = コンポーネント間の依存（クラス単位の依存を「コンポーネント名ペア」に
 * 集約し重複排除したもの。対応表にない FQCN・自コンポーネント内依存は無視する）。
 */
final class PlantUmlReporter implements ReporterInterface
{
    /** 苦痛ゾーン（赤系） */
    private const string PAIN_COLOR = '#FFCCCC';

    /** 無駄ゾーン（黄系） */
    private const string USELESS_COLOR = '#FFF2CC';

    public function render(ReportData $data): string
    {
        $nodeIdByComponentName = $this->buildNodeIds($data->componentMetrics);

        $lines = [];
        $lines[] = '@startuml';
        $lines[] = "' bobsap - SAP metrics";
        $lines[] = 'skinparam rectangle {';
        $lines[] = '  BackgroundColor White';
        $lines[] = '  BorderColor Black';
        $lines[] = '}';

        foreach ($data->componentMetrics as $metrics) {
            $lines[] = $this->nodeLine($metrics, $nodeIdByComponentName[$metrics->component->name]);
        }

        foreach ($this->buildEdges($data->componentMetrics, $nodeIdByComponentName) as [$from, $to]) {
            $lines[] = sprintf('%s --> %s', $from, $to);
        }

        $lines[] = '';
        // 凡例は英語で出す（CJK フォント非搭載の環境では日本語が豆腐になるため。
        // Mermaid 側の "Pain Zone" / "Useless Zone" 表記とも揃える）
        $lines[] = 'legend right';
        $lines[] = '  Pain Zone = ' . self::PAIN_COLOR;
        $lines[] = '  Useless Zone = ' . self::USELESS_COLOR;
        $lines[] = '  Normal = no color';
        $lines[] = 'endlegend';
        $lines[] = '@enduml';

        return implode("\n", $lines);
    }

    /**
     * コンポーネント名 → ノード ID（C1, C2, ...）の対応表を作る。
     * PlantUML の識別子には `\` を含む名前空間名をそのまま使えないため連番の ID にする。
     *
     * @param list<ComponentMetrics> $componentMetrics
     * @return array<string, string>
     */
    private function buildNodeIds(array $componentMetrics): array
    {
        $map = [];
        $index = 1;
        foreach ($componentMetrics as $metrics) {
            $map[$metrics->component->name] = 'C' . $index;
            $index++;
        }

        return $map;
    }

    private function nodeLine(ComponentMetrics $metrics, string $nodeId): string
    {
        $label = sprintf(
            '%s\nI=%.2f A=%.2f D=%.2f',
            $this->escapeLabel($metrics->component->name),
            $metrics->instability,
            $metrics->abstractness,
            $metrics->distance,
        );

        $color = match ($metrics->zone) {
            Zone::Pain => ' ' . self::PAIN_COLOR,
            Zone::Useless => ' ' . self::USELESS_COLOR,
            Zone::None => '',
        };

        return sprintf('rectangle "%s" as %s%s', $label, $nodeId, $color);
    }

    /**
     * コンポーネント名に含まれる `\` を PlantUML の引用文字列内で安全に表示できるようにする。
     * PlantUML の引用文字列は `\n` を改行として解釈するため、名前中の `\` をそのまま置くと
     * 意図しない改行等を引き起こしうる。`\\`（二重化）で本来のバックスラッシュ1文字を表す。
     */
    private function escapeLabel(string $name): string
    {
        return str_replace('\\', '\\\\', $name);
    }

    /**
     * クラス単位の依存を「コンポーネント名ペア」に集約し重複排除してエッジ一覧を作る。
     * 対応表にない FQCN（解析対象外への依存）・自コンポーネント内依存は無視する。
     *
     * @param list<ComponentMetrics> $componentMetrics
     * @param array<string, string> $nodeIdByComponentName
     * @return list<array{0: string, 1: string}>
     */
    private function buildEdges(array $componentMetrics, array $nodeIdByComponentName): array
    {
        $componentNameByFqcn = $this->buildComponentNameMap($componentMetrics);

        $seenPairs = [];
        $edges = [];
        foreach ($componentMetrics as $metrics) {
            $fromName = $metrics->component->name;
            foreach ($metrics->component->classInfos as $classInfo) {
                foreach ($classInfo->dependencies as $dependency) {
                    $toName = $componentNameByFqcn[$dependency] ?? null;
                    if ($toName === null || $toName === $fromName) {
                        continue;
                    }

                    $pairKey = $fromName . '|' . $toName;
                    if (isset($seenPairs[$pairKey])) {
                        continue;
                    }
                    $seenPairs[$pairKey] = true;

                    $edges[] = [$nodeIdByComponentName[$fromName], $nodeIdByComponentName[$toName]];
                }
            }
        }

        return $edges;
    }

    /**
     * クラスの FQCN → 所属コンポーネント名 の対応表を作る（MetricsCalculator と同じ考え方）。
     *
     * @param list<ComponentMetrics> $componentMetrics
     * @return array<string, string>
     */
    private function buildComponentNameMap(array $componentMetrics): array
    {
        $map = [];
        foreach ($componentMetrics as $metrics) {
            foreach ($metrics->component->classInfos as $classInfo) {
                $map[$classInfo->fqcn] = $metrics->component->name;
            }
        }

        return $map;
    }
}

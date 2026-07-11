<?php

declare(strict_types=1);

namespace Bobsap\Report;

use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\Zone;

/**
 * PlantUML でコンポーネント依存グラフを出力するレポーター。
 *
 * ノード = コンポーネント（ラベルに I/A/D を併記し、ゾーンごとに色分け）。
 * エッジ = コンポーネント間の依存（DependencyGraph が集約・重複排除したもの）。
 * 循環依存（ADP違反）ごとの最短経路に含まれるエッジは赤色の太線で強調する。
 */
final class PlantUmlReporter implements ReporterInterface
{
    /** 苦痛ゾーン（赤系） */
    private const string PAIN_COLOR = '#FFCCCC';

    /** 無駄ゾーン（黄系） */
    private const string USELESS_COLOR = '#FFF2CC';

    /** 循環依存（ADP違反）に含まれるエッジのスタイル */
    private const string CYCLE_EDGE_STYLE = '-[#red,thickness=2]->';

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

        $graph = $data->dependencyGraph;
        foreach ($graph->edges as [$from, $to]) {
            $lines[] = $this->edgeLine(
                $nodeIdByComponentName[$from],
                $nodeIdByComponentName[$to],
                $this->isCycleEdge($from, $to, $data->cyclePaths),
            );
        }

        $lines[] = '';
        // 凡例は英語で出す（CJK フォント非搭載の環境では日本語が豆腐になるため。
        // Mermaid 側の "Pain Zone" / "Useless Zone" 表記とも揃える）
        $lines[] = 'legend right';
        $lines[] = '  Pain Zone = ' . self::PAIN_COLOR;
        $lines[] = '  Useless Zone = ' . self::USELESS_COLOR;
        $lines[] = '  Normal = no color';
        $lines[] = '  Red edge = shortest cycle path (ADP violation)';
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

    private function edgeLine(string $fromId, string $toId, bool $isCycleEdge): string
    {
        $style = $isCycleEdge ? self::CYCLE_EDGE_STYLE : '-->';

        return sprintf('%s %s %s', $fromId, $style, $toId);
    }

    /**
     * エッジ (from, to) が表示対象の代表循環経路に含まれるかどうか。
     *
     * @param list<list<string>> $cyclePaths
     */
    private function isCycleEdge(string $from, string $to, array $cyclePaths): bool
    {
        foreach ($cyclePaths as $path) {
            for ($index = 0; isset($path[$index + 1]); $index++) {
                if ($path[$index] === $from && $path[$index + 1] === $to) {
                    return true;
                }
            }
        }

        return false;
    }
}

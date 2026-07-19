<?php

declare(strict_types=1);

namespace Psap\Report;

use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\Zone;

/**
 * Mermaid flowchart でコンポーネント依存グラフを出力するレポーター。
 *
 * PlantUmlReporter と同等の情報（ノード、I/A/D ラベル、ゾーン色分け、循環エッジ強調）を
 * `flowchart TD` で出力する。将来の自己完結HTMLポータル出力（ブラウザ内 mermaid.js 描画）の
 * 第一歩として新設したもので、現時点では内部利用のみ（CLI の `--format` には未公開）。
 *
 * 出力は Markdown のコードフェンスを含まない（MermaidReporter と同方針）。
 */
final class MermaidFlowchartReporter implements ReporterInterface
{
    /** 苦痛ゾーン（赤系）。PlantUmlReporter と同色 */
    private const string PAIN_COLOR = '#FFCCCC';

    /** 無駄ゾーン（黄系）。PlantUmlReporter と同色 */
    private const string USELESS_COLOR = '#FFF2CC';

    /** 循環依存（ADP違反）に含まれるエッジのスタイル（赤・太線） */
    private const string CYCLE_EDGE_STYLE = 'stroke:#FF0000,stroke-width:2px';

    public function render(ReportData $data): string
    {
        $nodeIdByComponentName = $this->buildNodeIds($data->componentMetrics);

        $lines = [];
        $lines[] = 'flowchart TD';

        foreach ($data->componentMetrics as $metrics) {
            $lines[] = $this->nodeLine($metrics, $nodeIdByComponentName[$metrics->component->name]);
        }

        // flowchart の linkStyle はエッジの出力順の序数で指定するため、
        // エッジ行を出力しながら循環依存に該当する index を集めておく。
        $cycleEdgeIndexes = [];
        $graph = $data->dependencyGraph;
        foreach ($graph->edges as $edgeIndex => [$from, $to]) {
            $lines[] = $this->edgeLine($nodeIdByComponentName[$from], $nodeIdByComponentName[$to]);
            if ($this->isCycleEdge($from, $to, $data->cyclePaths)) {
                $cycleEdgeIndexes[] = $edgeIndex;
            }
        }

        $lines[] = '    classDef pain fill:' . self::PAIN_COLOR;
        $lines[] = '    classDef useless fill:' . self::USELESS_COLOR;

        if ($cycleEdgeIndexes !== []) {
            $lines[] = sprintf('    linkStyle %s ' . self::CYCLE_EDGE_STYLE, implode(',', $cycleEdgeIndexes));
        }

        $lines[] = '';
        // flowchart にネイティブの legend 機能がないため、コメント行で最小限に記す
        // （図内に凡例ノードは作らない）。PlantUmlReporter の legend と同じ内容を英語で揃える。
        $lines[] = '%% Pain Zone = ' . self::PAIN_COLOR;
        $lines[] = '%% Useless Zone = ' . self::USELESS_COLOR;
        $lines[] = '%% Normal = no color';
        $lines[] = '%% Red edge = shortest cycle path (ADP violation)';

        return implode("\n", $lines);
    }

    /**
     * コンポーネント名 → ノード ID（C1, C2, ...）の対応表を作る。
     * Mermaid のノード ID には `\` を含む名前空間名をそのまま使えないため連番の ID にする
     * （PlantUmlReporter の buildNodeIds() と同じロジック）。
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
        $instability = $metrics->dependencyMetricsEvaluable ? sprintf('%.2f', $metrics->instability) : 'N/A';
        $distance = $metrics->dependencyMetricsEvaluable ? sprintf('%.2f', $metrics->distance) : 'N/A';
        $label = sprintf(
            '%s<br/>I=%s A=%.2f D=%s',
            $this->escapeLabel($metrics->component->name),
            $instability,
            $metrics->abstractness,
            $distance,
        );

        $classSuffix = match ($metrics->zone) {
            Zone::Pain => ':::pain',
            Zone::Useless => ':::useless',
            Zone::None => '',
        };

        return sprintf('    %s["%s"]%s', $nodeId, $label, $classSuffix);
    }

    /**
     * コンポーネント名を Mermaid flowchart の引用ラベル内で安全に表示できるようにする。
     *
     * Mermaid flowchart は引用ラベル内でも `<` 等を HTML として解釈するため、
     * `&` `<` `>` `"` を Mermaid のエンティティ記法（先頭 `&` を省いた HTML 数値/名前
     * 参照）でエスケープする。`\`（名前空間区切り）は特殊文字として解釈されないため
     * そのまま通す。
     */
    private function escapeLabel(string $name): string
    {
        return str_replace(
            ['&', '<', '>', '"'],
            ['#38;', '#lt;', '#gt;', '#quot;'],
            $name,
        );
    }

    private function edgeLine(string $fromId, string $toId): string
    {
        return sprintf('    %s --> %s', $fromId, $toId);
    }

    /**
     * エッジ (from, to) が表示対象の代表循環経路に含まれるかどうか。
     * PlantUmlReporter の isCycleEdge() と同一のロジック。
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

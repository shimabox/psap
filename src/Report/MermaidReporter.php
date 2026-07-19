<?php

declare(strict_types=1);

namespace Psap\Report;

use Psap\Metrics\ComponentMetrics;

/**
 * Mermaid の quadrantChart で I/A 散布図を出力するレポーター。
 *
 * 書籍 Clean Architecture 図14-13「除外すべき範囲」相当。
 * 象限は右上（I高・A高）が無駄ゾーン、左下（I低・A低）が苦痛ゾーンになるよう
 * quadrant-1〜4 のラベルを割り当てる（Mermaid の quadrantChart は quadrant-1=右上,
 * quadrant-2=左上, quadrant-3=左下, quadrant-4=右下の固定順）。
 *
 * 出力は Markdown のコードフェンスを含まない（利用者が好きな場所に埋め込めるように）。
 */
final class MermaidReporter implements ReporterInterface
{
    /**
     * 座標が 0 または 1 ちょうどだと点がグラフの端にはみ出て描画が崩れるレンダラーがあるため、
     * 表示用の座標のみこの範囲にクランプする（ラベルの D 値は実値のまま出す）。
     */
    private const float CLAMP_MIN = 0.01;
    private const float CLAMP_MAX = 0.99;

    public function render(ReportData $data): string
    {
        $lines = [];
        $lines[] = 'quadrantChart';
        $lines[] = '    title SAP - I/A Graph (D = distance from main sequence)';
        // x-axis/y-axis のラベルは括弧を含む場合、引用符なしだと Mermaid の
        // quadrantChart パーサーが字句解析エラーを起こすため、必ずダブルクオートで囲む
        $lines[] = '    x-axis "Instability (I)"';
        $lines[] = '    y-axis "Abstractness (A)"';
        $lines[] = '    quadrant-1 Useless Zone';
        $lines[] = '    quadrant-2 Main Sequence';
        $lines[] = '    quadrant-3 Pain Zone';
        $lines[] = '    quadrant-4 Main Sequence';

        $evaluableMetrics = array_values(array_filter(
            $data->componentMetrics,
            static fn (ComponentMetrics $metrics): bool => $metrics->dependencyMetricsEvaluable,
        ));
        $cycleComponents = [];
        foreach ($data->cycles as $cycle) {
            foreach ($cycle as $component) {
                $cycleComponents[$component] = true;
            }
        }
        $hasCyclePoints = false;
        foreach ($evaluableMetrics as $metrics) {
            $inCycle = isset($cycleComponents[$metrics->component->name]);
            $lines[] = $this->pointLine($metrics, $inCycle);
            $hasCyclePoints = $hasCyclePoints || $inCycle;
        }
        if ($evaluableMetrics === []) {
            $lines[] = '    %% No components with evaluable dependency metrics';
        }
        if ($hasCyclePoints) {
            $lines[] = '    classDef cycle color: #b42318, radius: 9, stroke-color: #7a271a, stroke-width: 3px';
        }

        return implode("\n", $lines);
    }

    private function pointLine(ComponentMetrics $metrics, bool $inCycle): string
    {
        // コンポーネント名（FQCN の名前空間部分）に含まれる `\` はそのまま使う。
        // Mermaid の quadrantChart のラベル文字列はバックスラッシュを特殊文字として
        // 解釈しないため、エスケープなしでそのまま書ける。
        $label = sprintf(
            '%s%s (D=%.2f)',
            $metrics->component->name,
            $inCycle ? ' [cycle]' : '',
            $metrics->distance,
        );

        return sprintf(
            '    "%s"%s: [%s, %s]',
            $label,
            $inCycle ? ':::cycle' : '',
            $this->formatCoordinate($metrics->instability),
            $this->formatCoordinate($metrics->abstractness),
        );
    }

    private function formatCoordinate(float $value): string
    {
        $clamped = max(self::CLAMP_MIN, min(self::CLAMP_MAX, $value));

        // 末尾の 0 を除去して見た目をシンプルにする（0.20 → 0.2、0.90 → 0.9 等）
        return rtrim(rtrim(sprintf('%.2f', $clamped), '0'), '.');
    }
}

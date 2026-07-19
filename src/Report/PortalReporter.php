<?php

declare(strict_types=1);

namespace Psap\Report;

use JsonException;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\Zone;
use RuntimeException;

/**
 * 1回の解析結果を単一の自己完結HTMLポータルにまとめて出力するレポーター。
 *
 * 既存の Reporter を合成する:
 *   - HtmlReporter          … インタラクティブ I/A グラフ（iframe srcdoc で無改修埋め込み）
 *   - MermaidReporter       … quadrantChart ソース（同梱 mermaid.js でブラウザ内描画）
 *   - MermaidFlowchartReporter … 依存フローチャート ソース（同梱 mermaid.js で描画）
 *   - PlantUmlReporter      … PlantUML ソース（表示・コピー・ダウンロードのみ）
 *   - ReportData から直接    … Overview / Cycles タブを PHP 側で HTML 生成
 *
 * 図の描画は同梱した mermaid.min.js でブラウザ内実行するため、外部サービスへの通信は
 * 一切発生しない。出力 HTML には外部オリジンへの参照を含めず、CSP でも遮断する。
 */
final class PortalReporter implements ReporterInterface
{
    /**
     * flowchart のクライアント描画を行う上限エッジ数（Mermaid 既定の maxEdges と同値）。
     * これを超える場合は描画をスキップしてソース表示へフォールバックする。
     */
    private const int FLOWCHART_MAX_EDGES = 500;

    /** Overview の「D 値ワースト」に表示する最大件数 */
    private const int WORST_DISTANCE_LIMIT = 10;

    private const string MERMAID_ASSET_PATH = __DIR__ . '/../../resources/js/mermaid.min.js';

    /** 同梱している mermaid のバージョン（resources/js/README.md と一致させる） */
    private const string MERMAID_VERSION = '11.16.0';

    /**
     * @throws JsonException
     */
    public function render(ReportData $data): string
    {
        $html = (new HtmlReporter())->render($data);
        $quadrant = (new MermaidReporter())->render($data);
        $flowchart = (new MermaidFlowchartReporter())->render($data);
        $plantuml = (new PlantUmlReporter())->render($data);
        $markdown = (new MarkdownReporter())->render($data);
        $mermaidJs = $this->loadMermaidAsset();

        $edgeCount = count($data->dependencyGraph->edges);
        $flowchartRenderable = $edgeCount <= self::FLOWCHART_MAX_EDGES;

        $replacements = [
            '__PSAP_DATA__' => $this->encode($this->payload($data, $edgeCount, $flowchartRenderable)),
            '__PSAP_OVERVIEW_HTML__' => $this->renderOverview($data),
            '__PSAP_CYCLES_HTML__' => $this->renderCycles($data),
            '__PSAP_IFRAME_HTML__' => htmlspecialchars($html, ENT_QUOTES, 'UTF-8'),
            '__PSAP_MERMAID_QUADRANT__' => $this->encode($quadrant),
            '__PSAP_MERMAID_FLOWCHART__' => $this->encode($flowchart),
            '__PSAP_PLANTUML__' => $this->encode($plantuml),
            '__PSAP_MARKDOWN__' => $this->encode($markdown),
            '__PSAP_MERMAID_VERSION__' => self::MERMAID_VERSION,
            '__PSAP_MERMAID_JS__' => $mermaidJs,
        ];

        // strtr は挿入した文字列を再走査しないため、値に別のプレースホルダ文字列が
        // 含まれていても誤置換されない（mermaid.min.js 本体を安全に埋め込める）。
        return strtr($this->template(), $replacements);
    }

    /**
     * @throws JsonException
     */
    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES,
        );
    }

    private function loadMermaidAsset(): string
    {
        $asset = @file_get_contents(self::MERMAID_ASSET_PATH);
        if ($asset === false) {
            throw new RuntimeException(sprintf(
                'ポータルの描画に必要な mermaid.min.js を読み込めませんでした: %s。'
                . '配布物に resources/ が同梱されているか確認してください。',
                self::MERMAID_ASSET_PATH,
            ));
        }

        return $asset;
    }

    /**
     * @return array{
     *     summary: array{componentCount: int, plottedCount: int, meanDistance: float|null, cycleGroupCount: int},
     *     flowchart: array{edgeCount: int, maxEdges: int, renderable: bool}
     * }
     */
    private function payload(ReportData $data, int $edgeCount, bool $flowchartRenderable): array
    {
        $plotted = count(array_filter(
            $data->componentMetrics,
            static fn (ComponentMetrics $metrics): bool => $metrics->dependencyMetricsEvaluable,
        ));

        return [
            'summary' => [
                'componentCount' => count($data->componentMetrics),
                'plottedCount' => $plotted,
                'meanDistance' => $data->summary->meanDistance === null
                    ? null
                    : round($data->summary->meanDistance, 4),
                'cycleGroupCount' => count($data->cycles),
            ],
            'flowchart' => [
                'edgeCount' => $edgeCount,
                'maxEdges' => self::FLOWCHART_MAX_EDGES,
                'renderable' => $flowchartRenderable,
            ],
        ];
    }

    private function renderOverview(ReportData $data): string
    {
        $plotted = array_values(array_filter(
            $data->componentMetrics,
            static fn (ComponentMetrics $metrics): bool => $metrics->dependencyMetricsEvaluable,
        ));
        $meanDistance = $data->summary->meanDistance === null
            ? 'N/A'
            : sprintf('%.2f', $data->summary->meanDistance);

        $rows = '';
        $rows .= $this->statCard('components', (string) count($data->componentMetrics));
        $rows .= $this->statCard('plotted', (string) count($plotted));
        $rows .= $this->statCard('meanDistance', $meanDistance);
        $rows .= $this->statCard('cycleGroups', (string) count($data->cycles), count($data->cycles) > 0);

        $html = '<dl class="stat-grid">' . $rows . '</dl>';

        $coverage = $data->analysisCoverage;
        if ($coverage !== null) {
            $ratio = $coverage->ratio();
            $ratioLabel = $ratio === null ? 'N/A' : sprintf('%.2f%%', $ratio * 100);
            $ledger = '';
            $ledger .= $this->statCard('discoveredFiles', (string) $coverage->discovered);
            $ledger .= $this->statCard('selectedFiles', (string) $coverage->selected);
            $ledger .= $this->statCard('analyzedFiles', (string) $coverage->analyzed);
            $ledger .= $this->statCard('excludedFiles', (string) $coverage->excluded);
            $ledger .= $this->statCard('skippedFiles', (string) $coverage->skipped, $coverage->skipped > 0);
            $html .= '<h3 class="panel-title"><span data-i18n="analysisCoverage">Analysis coverage</span>'
                . ' <span class="ratio">' . $this->escape($ratioLabel) . '</span></h3>';
            $html .= '<dl class="stat-grid ledger">' . $ledger . '</dl>';
        }

        $diagnosticCount = count($data->diagnostics);
        if ($diagnosticCount > 0) {
            $html .= '<p class="notice"><span data-i18n="diagnosticsNotice">Analysis notices</span>: '
                . $diagnosticCount . '. <span data-i18n="diagnosticsHint">'
                . 'See the Interactive I/A tab for full details.</span></p>';
        }

        $html .= '<h3 class="panel-title" data-i18n="worstDistanceHeading">Highest distance from the main sequence</h3>';
        $html .= $this->renderWorstTable($plotted);

        return $html;
    }

    /**
     * @param list<ComponentMetrics> $plotted
     */
    private function renderWorstTable(array $plotted): string
    {
        if ($plotted === []) {
            return '<p class="empty" data-i18n="noComponents">No components with evaluable metrics.</p>';
        }

        usort(
            $plotted,
            static fn (ComponentMetrics $a, ComponentMetrics $b): int => $b->distance <=> $a->distance,
        );
        $worst = array_slice($plotted, 0, self::WORST_DISTANCE_LIMIT);

        $body = '';
        foreach ($worst as $metrics) {
            $body .= '<tr>'
                . '<td>' . $this->escape($metrics->component->name) . '</td>'
                . '<td>' . sprintf('%.2f', $metrics->instability) . '</td>'
                . '<td>' . sprintf('%.2f', $metrics->abstractness) . '</td>'
                . '<td>' . sprintf('%.2f', $metrics->distance) . '</td>'
                . '<td>' . $this->zoneBadge($metrics->zone) . '</td>'
                . '</tr>';
        }

        return '<div class="table-wrap"><table><thead><tr>'
            . '<th data-i18n="component">Component</th><th>I</th><th>A</th><th>D</th>'
            . '<th data-i18n="zone">Zone</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    private function renderCycles(ReportData $data): string
    {
        $groups = $data->cycleGroups();
        if ($groups === []) {
            return '<p class="empty ok" data-i18n="noCyclesFound">'
                . 'No circular dependencies (ADP violations) were detected.</p>';
        }

        $html = '';
        foreach ($groups as $index => $group) {
            $relationKey = $group['namespaceRelation'] === 'hierarchical'
                ? 'hierarchicalNamespaces'
                : 'peerNamespaces';
            $summary = '#' . ($index + 1) . ' · ' . $group['componentCount']
                . ' <span data-i18n="componentsWord">components</span>'
                . ' · <span data-i18n="' . $relationKey . '">namespaces</span>';

            $body = '<p class="cycle-label" data-i18n="representativePath">Representative shortest path</p>';
            $body .= $this->chips($group['representativePath'], true);

            $body .= '<p class="cycle-label" data-i18n="involvedComponents">Components in this cycle</p>';
            $body .= $this->chips($group['components'], false);

            if ($group['omittedComponents'] !== []) {
                $body .= '<p class="cycle-label" data-i18n="omittedFromPath">Not shown in the representative path</p>';
                $body .= $this->chips($group['omittedComponents'], false);
            }

            $body .= '<p class="cycle-label" data-i18n="dependencyEvidence">Dependency evidence</p>';
            $body .= $this->renderDependencies($group['dependencies']);

            $open = $index === 0 ? ' open' : '';
            $html .= '<details class="cycle-group"' . $open . '><summary>' . $summary . '</summary>'
                . '<div class="cycle-body">' . $body . '</div></details>';
        }

        return $html;
    }

    /**
     * @param list<array{
     *     from: string,
     *     to: string,
     *     classDependencies: list<array{
     *         from: string,
     *         to: string,
     *         evidence: list<array{kind: string, file: string, line: int}>
     *     }>,
     * }> $dependencies
     */
    private function renderDependencies(array $dependencies): string
    {
        $html = '';
        foreach ($dependencies as $dependency) {
            $html .= '<div class="edge"><h4>' . $this->escape($dependency['from'])
                . ' <span class="arrow">&rarr;</span> ' . $this->escape($dependency['to']) . '</h4>';

            if ($dependency['classDependencies'] === []) {
                $html .= '<p class="edge-empty" data-i18n="noClassDependencies">'
                    . 'No class dependencies were recorded for this edge.</p></div>';
                continue;
            }

            foreach ($dependency['classDependencies'] as $classDependency) {
                $html .= '<div class="class-dep"><code>' . $this->escape($classDependency['from'])
                    . ' &rarr; ' . $this->escape($classDependency['to']) . '</code>';
                if ($classDependency['evidence'] === []) {
                    $html .= '<p class="edge-empty" data-i18n="noSourceEvidence">'
                        . 'No source-location evidence was recorded.</p>';
                } else {
                    $html .= '<ul class="evidence">';
                    foreach ($classDependency['evidence'] as $evidence) {
                        $html .= '<li><code>' . $this->escape($evidence['kind']) . '</code> · '
                            . $this->escape($evidence['file']) . ':' . $evidence['line'] . '</li>';
                    }
                    $html .= '</ul>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * @param list<string> $names
     */
    private function chips(array $names, bool $withArrows): string
    {
        $html = '<div class="chips">';
        $last = count($names) - 1;
        foreach ($names as $position => $name) {
            $html .= '<code>' . $this->escape($name) . '</code>';
            if ($withArrows && $position < $last) {
                $html .= '<span class="arrow">&rarr;</span>';
            }
        }

        return $html . '</div>';
    }

    private function statCard(string $labelKey, string $value, bool $alert = false): string
    {
        $ddClass = $alert ? ' class="alert"' : '';

        return '<div><dt data-i18n="' . $labelKey . '">' . $labelKey . '</dt>'
            . '<dd' . $ddClass . '>' . $this->escape($value) . '</dd></div>';
    }

    private function zoneBadge(Zone $zone): string
    {
        [$key, $class] = match ($zone) {
            Zone::Pain => ['painZone', 'pain'],
            Zone::Useless => ['uselessZone', 'useless'],
            Zone::None => ['mainSequence', 'main'],
        };

        return '<span class="zone zone-' . $class . '" data-i18n="' . $key . '">' . $key . '</span>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function template(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; font-src data:; frame-src 'self' data: about:; base-uri 'none'; form-action 'none'">
  <title>psap — SAP Analysis Report</title>
  <link rel="icon" href="data:,">
  <style>
    :root {
      color-scheme: light;
      --canvas: #f4f8fb;
      --paper: #ffffff;
      --ink: #18252f;
      --muted: #5b6a75;
      --grid: #c9d6df;
      --main: #2457c5;
      --pain: #b54b35;
      --useless: #7651b2;
      --shadow: 0 18px 50px rgb(24 37 47 / 10%);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background:
        linear-gradient(rgb(201 214 223 / 28%) 1px, transparent 1px),
        linear-gradient(90deg, rgb(201 214 223 / 28%) 1px, transparent 1px),
        var(--canvas);
      background-size: 24px 24px;
      color: var(--ink);
      font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      line-height: 1.5;
    }
    button, select { font: inherit; }
    button:focus-visible, select:focus-visible, [tabindex]:focus-visible {
      outline: 3px solid rgb(36 87 197 / 35%);
      outline-offset: 2px;
    }
    .shell { width: min(1400px, calc(100% - 32px)); margin: 0 auto; padding: 36px 0 64px; }
    .masthead {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 24px;
      align-items: end;
      margin-bottom: 22px;
    }
    .eyebrow {
      margin: 0 0 6px;
      color: var(--main);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .76rem;
      font-weight: 700;
      letter-spacing: .13em;
      text-transform: uppercase;
    }
    h1 {
      max-width: 820px;
      margin: 0;
      font-family: ui-serif, Georgia, Cambria, "Times New Roman", serif;
      font-size: clamp(2rem, 4.4vw, 3.8rem);
      font-weight: 500;
      letter-spacing: -.05em;
      line-height: 1;
    }
    .dek { max-width: 760px; margin: 12px 0 0; color: var(--muted); font-size: .96rem; }
    .language-field { display: grid; gap: 6px; justify-items: end; min-width: 150px; }
    .language-field label {
      color: var(--muted);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
    }
    select {
      min-height: 42px;
      width: 100%;
      border: 1px solid #aebec9;
      background: var(--paper);
      color: var(--ink);
      padding: 9px 11px;
    }
    .tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 2px;
      margin-bottom: 18px;
      border-bottom: 2px solid var(--grid);
    }
    .tab {
      border: 1px solid var(--grid);
      border-bottom: 0;
      background: rgb(255 255 255 / 70%);
      color: var(--muted);
      padding: 11px 18px;
      cursor: pointer;
      font-weight: 700;
      font-size: .84rem;
    }
    .tab[aria-selected="true"] { background: var(--paper); color: var(--main); box-shadow: 0 -3px 0 var(--main) inset; }
    .panel {
      border: 1px solid var(--grid);
      background: var(--paper);
      box-shadow: var(--shadow);
      padding: 24px;
    }
    .panel[hidden] { display: none; }
    .panel-title {
      margin: 26px 0 12px;
      font-size: .82rem;
      letter-spacing: .05em;
      text-transform: uppercase;
      color: var(--ink);
    }
    .panel-title .ratio { color: var(--main); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      margin: 0;
      border: 1px solid var(--grid);
      background: rgb(255 255 255 / 82%);
    }
    .stat-grid.ledger { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .stat-grid div { padding: 14px 16px; }
    .stat-grid div + div { border-left: 1px solid var(--grid); }
    .stat-grid dt {
      color: var(--muted);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .64rem;
      letter-spacing: .07em;
      text-transform: uppercase;
    }
    .stat-grid dd { margin: 3px 0 0; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 1.2rem; font-weight: 700; }
    .stat-grid dd.alert { color: var(--pain); }
    .notice { margin: 18px 0 0; border-left: 4px solid var(--main); background: rgb(36 87 197 / 7%); padding: 10px 14px; font-size: .84rem; }
    .table-wrap { overflow: auto; border: 1px solid var(--grid); }
    table { width: 100%; border-collapse: collapse; font-size: .84rem; }
    th, td { padding: 9px 12px; border-top: 1px solid var(--grid); text-align: right; white-space: nowrap; }
    th { color: var(--muted); font-size: .66rem; letter-spacing: .05em; text-transform: uppercase; }
    th:first-child, td:first-child { text-align: left; }
    thead th { border-top: 0; }
    td:first-child { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
    .zone { display: inline-block; padding: 2px 8px; font-size: .68rem; font-weight: 700; }
    .zone-pain { background: rgb(181 75 53 / 14%); color: var(--pain); }
    .zone-useless { background: rgb(118 81 178 / 14%); color: var(--useless); }
    .zone-main { background: rgb(36 87 197 / 12%); color: var(--main); }
    .empty { color: var(--muted); }
    .empty.ok { border-left: 4px solid #2f8f5b; background: rgb(47 143 91 / 8%); padding: 12px 14px; }
    .iframe-hint { margin: 0 0 14px; color: var(--muted); font-size: .84rem; }
    iframe.report {
      width: 100%;
      height: 82vh;
      min-height: 640px;
      border: 1px solid var(--grid);
      background: var(--paper);
    }
    .diagram-block { margin-bottom: 34px; }
    .diagram-block h3 { margin: 0 0 6px; font-size: .96rem; }
    .diagram-note { margin: 0 0 12px; color: var(--muted); font-size: .8rem; }
    .diagram {
      overflow: auto;
      border: 1px solid var(--grid);
      background: var(--paper);
      padding: 16px;
      min-height: 120px;
    }
    .diagram svg { max-width: 100%; height: auto; }
    /* A successfully-rendered diagram becomes zoomable: the container clips and
       anchors the overlaid controls, and the inner <svg> carries the transform. */
    .diagram.zoomable {
      position: relative;
      overflow: hidden;
      padding: 0;
      max-height: 80vh;
      cursor: grab;
    }
    .diagram.zoomable.dragging { cursor: grabbing; user-select: none; }
    .diagram.zoomable svg { max-width: none; height: auto; display: block; }
    .zoom-controls { position: absolute; top: 10px; right: 10px; z-index: 2; display: flex; gap: 6px; }
    .zoom-btn {
      min-width: 30px;
      border: 1px solid var(--ink);
      background: rgb(255 255 255 / 92%);
      color: var(--ink);
      padding: 5px 9px;
      cursor: pointer;
      font-size: .82rem;
      line-height: 1.1;
      box-shadow: 0 2px 6px rgb(24 37 47 / 12%);
    }
    .zoom-btn:hover { background: var(--ink); color: #fff; }
    .fallback { margin: 0; border-left: 4px solid var(--pain); background: rgb(181 75 53 / 8%); padding: 12px 14px; font-size: .84rem; }
    .cycle-group { border: 1px solid var(--grid); margin-bottom: 10px; }
    .cycle-group > summary { padding: 14px 18px; cursor: pointer; font-weight: 700; }
    .cycle-group[open] > summary { background: rgb(181 75 53 / 6%); }
    .cycle-body { padding: 4px 18px 20px; }
    .cycle-label {
      margin: 18px 0 6px;
      color: var(--muted);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .66rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
    }
    .chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .chips code, .class-dep code, .evidence code {
      border: 1px solid var(--grid);
      background: #f7fafc;
      padding: 3px 7px;
      font-size: .78rem;
      overflow-wrap: anywhere;
    }
    .arrow { color: var(--pain); font-weight: 700; }
    .edge { margin-top: 12px; border: 1px solid var(--grid); }
    .edge h4 {
      margin: 0;
      background: #f7fafc;
      padding: 9px 12px;
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .78rem;
      font-weight: 700;
      overflow-wrap: anywhere;
    }
    .class-dep { padding: 10px 12px; border-top: 1px solid var(--grid); }
    .class-dep > code { display: inline-block; }
    .evidence { max-height: 200px; margin: 7px 0 0; padding-left: 18px; overflow: auto; color: var(--muted); font-size: .76rem; }
    .edge-empty { margin: 6px 0 0; color: var(--muted); font-size: .78rem; }
    .source-block { margin-bottom: 28px; }
    .source-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
    .source-head h3 { margin: 0; font-size: .92rem; }
    .source-actions { display: flex; gap: 8px; }
    .source-actions button {
      border: 1px solid var(--ink);
      background: var(--paper);
      color: var(--ink);
      padding: 6px 12px;
      cursor: pointer;
      font-size: .78rem;
    }
    .source-actions button:hover { background: var(--ink); color: #fff; }
    pre.source {
      max-height: 360px;
      margin: 0;
      overflow: auto;
      border: 1px solid var(--grid);
      background: #0f1a22;
      color: #dce7ee;
      padding: 14px 16px;
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .78rem;
      line-height: 1.5;
    }
    footer { margin-top: 26px; color: var(--muted); font-size: .74rem; }
    @media (max-width: 820px) {
      .masthead { grid-template-columns: 1fr; }
      .stat-grid, .stat-grid.ledger { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .stat-grid div:nth-child(n+3) { border-top: 1px solid var(--grid); }
      .stat-grid div:nth-child(odd) { border-left: 0; }
    }
    @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
  </style>
</head>
<body>
  <main class="shell">
    <header class="masthead">
      <div>
        <p class="eyebrow" data-i18n="eyebrow">psap / sap analysis report</p>
        <h1 data-i18n="headline">SAP Analysis Report</h1>
        <p class="dek" data-i18n="description">One self-contained page: summary, the interactive I/A graph, in-browser Mermaid diagrams, cycle details, and diagram sources. No network access is required or made.</p>
      </div>
      <div class="language-field">
        <label for="language" data-i18n="language">Language</label>
        <select id="language">
          <option value="en" selected>English</option>
          <option value="ja">日本語</option>
        </select>
      </div>
    </header>

    <div class="tabs" role="tablist" aria-label="Portal sections" data-i18n-aria-label="portalSections">
      <button class="tab" role="tab" id="tab-overview" aria-controls="panel-overview" aria-selected="true" data-i18n="tabOverview">Overview</button>
      <button class="tab" role="tab" id="tab-interactive" aria-controls="panel-interactive" aria-selected="false" data-i18n="tabInteractive">Interactive I/A</button>
      <button class="tab" role="tab" id="tab-diagrams" aria-controls="panel-diagrams" aria-selected="false" data-i18n="tabDiagrams">Diagrams</button>
      <button class="tab" role="tab" id="tab-cycles" aria-controls="panel-cycles" aria-selected="false" data-i18n="tabCycles">Cycles</button>
      <button class="tab" role="tab" id="tab-sources" aria-controls="panel-sources" aria-selected="false" data-i18n="tabSources">Sources</button>
    </div>

    <section class="panel" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview">__PSAP_OVERVIEW_HTML__</section>

    <section class="panel" id="panel-interactive" role="tabpanel" aria-labelledby="tab-interactive" hidden>
      <p class="iframe-hint" data-i18n="interactiveHint">The interactive Instability / Abstractness report is embedded below. It has its own language selector.</p>
      <iframe class="report" title="psap interactive I/A report" data-i18n-title="interactiveTitle" srcdoc="__PSAP_IFRAME_HTML__"></iframe>
    </section>

    <section class="panel" id="panel-diagrams" role="tabpanel" aria-labelledby="tab-diagrams" hidden>
      <div class="diagram-block">
        <h3 data-i18n="quadrantHeading">I/A quadrant chart</h3>
        <p class="diagram-note" data-i18n="quadrantNote">Rendered in your browser by the bundled Mermaid. Zones are shown as quadrant labels (an approximation of the radius-based zones).</p>
        <div class="diagram" id="diagram-quadrant" aria-live="polite"></div>
      </div>
      <div class="diagram-block">
        <h3 data-i18n="flowchartHeading">Dependency flowchart</h3>
        <p class="diagram-note" data-i18n="flowchartNote">Components and their dependencies. Red edges are shortest cycle paths (ADP violations).</p>
        <div class="diagram" id="diagram-flowchart" aria-live="polite"></div>
      </div>
    </section>

    <section class="panel" id="panel-cycles" role="tabpanel" aria-labelledby="tab-cycles" hidden>
      <h2 class="panel-title" data-i18n="cyclesHeading">Circular dependencies</h2>
      <p class="diagram-note" data-i18n="cyclesIntro">Each group shows one representative shortest path and the class-level evidence that creates its component dependencies.</p>
      __PSAP_CYCLES_HTML__
    </section>

    <section class="panel" id="panel-sources" role="tabpanel" aria-labelledby="tab-sources" hidden>
      <p class="diagram-note" data-i18n="sourcesIntro">Copy or download the raw diagram sources for use in external viewers or with AI assistants.</p>
      <div class="source-block">
        <div class="source-head">
          <h3 data-i18n="quadrantSource">Mermaid quadrantChart (.mmd)</h3>
          <div class="source-actions">
            <button type="button" data-copy="quadrant" data-i18n="copy">Copy</button>
            <button type="button" data-download="quadrant" data-file="psap-quadrant.mmd" data-i18n="download">Download</button>
          </div>
        </div>
        <pre class="source" id="source-quadrant"></pre>
      </div>
      <div class="source-block">
        <div class="source-head">
          <h3 data-i18n="flowchartSource">Mermaid flowchart (.mmd)</h3>
          <div class="source-actions">
            <button type="button" data-copy="flowchart" data-i18n="copy">Copy</button>
            <button type="button" data-download="flowchart" data-file="psap-flowchart.mmd" data-i18n="download">Download</button>
          </div>
        </div>
        <pre class="source" id="source-flowchart"></pre>
      </div>
      <div class="source-block">
        <div class="source-head">
          <h3 data-i18n="plantumlSource">PlantUML (.puml)</h3>
          <div class="source-actions">
            <button type="button" data-copy="plantuml" data-i18n="copy">Copy</button>
            <button type="button" data-download="plantuml" data-file="psap.puml" data-i18n="download">Download</button>
          </div>
        </div>
        <pre class="source" id="source-plantuml"></pre>
      </div>
      <div class="source-block">
        <div class="source-head">
          <h3 data-i18n="markdownSource">Markdown report (.md)</h3>
          <div class="source-actions">
            <button type="button" data-copy="markdown" data-i18n="copy">Copy</button>
            <button type="button" data-download="markdown" data-file="psap-report.md" data-i18n="download">Download</button>
          </div>
        </div>
        <pre class="source" id="source-markdown"></pre>
      </div>
    </section>

    <footer>
      <p data-i18n="footerNote">Generated by psap. This page is fully self-contained: diagrams render in your browser with a bundled copy of Mermaid, and no data leaves your machine.</p>
    </footer>
  </main>

  <script id="psap-portal-data" type="application/json">__PSAP_DATA__</script>
  <!--
    Bundled Mermaid v__PSAP_MERMAID_VERSION__ is distributed under the MIT License.
    Copyright (c) 2014-2022 Knut Sveidqvist and Mermaid contributors.
    Full license text: resources/js/mermaid.LICENSE
  -->
  <script>__PSAP_MERMAID_JS__</script>
  <script>
    (() => {
      'use strict';

      const messages = {
        en: {
          documentTitle: 'psap — SAP Analysis Report',
          eyebrow: 'psap / sap analysis report',
          headline: 'SAP Analysis Report',
          description: 'One self-contained page: summary, the interactive I/A graph, in-browser Mermaid diagrams, cycle details, and diagram sources. No network access is required or made.',
          language: 'Language',
          portalSections: 'Portal sections',
          tabOverview: 'Overview',
          tabInteractive: 'Interactive I/A',
          tabDiagrams: 'Diagrams',
          tabCycles: 'Cycles',
          tabSources: 'Sources',
          components: 'Components',
          plotted: 'Plotted',
          meanDistance: 'Mean D',
          cycleGroups: 'Cycle groups',
          analysisCoverage: 'Analysis coverage',
          discoveredFiles: 'Discovered',
          selectedFiles: 'Selected',
          analyzedFiles: 'Analyzed',
          excludedFiles: 'Excluded',
          skippedFiles: 'Skipped',
          diagnosticsNotice: 'Analysis notices',
          diagnosticsHint: 'See the Interactive I/A tab for full details.',
          worstDistanceHeading: 'Highest distance from the main sequence',
          component: 'Component',
          zone: 'Zone',
          painZone: 'Pain zone',
          uselessZone: 'Useless zone',
          mainSequence: 'Main sequence',
          noComponents: 'No components with evaluable metrics.',
          interactiveHint: 'The interactive Instability / Abstractness report is embedded below. It has its own language selector.',
          interactiveTitle: 'psap interactive I/A report',
          quadrantHeading: 'I/A quadrant chart',
          quadrantNote: 'Rendered in your browser by the bundled Mermaid. Zones are shown as quadrant labels (an approximation of the radius-based zones).',
          flowchartHeading: 'Dependency flowchart',
          flowchartNote: 'Components and their dependencies. Red edges are shortest cycle paths (ADP violations).',
          flowchartSkipped: 'This graph is large ({edges} edges, limit {max}), so in-browser rendering was skipped. Use the Sources tab with an external viewer, or narrow the scope with --depth or --exclude.',
          diagramError: 'The diagram could not be rendered in this browser. The source is available on the Sources tab.',
          zoomIn: 'Zoom in',
          zoomOut: 'Zoom out',
          zoomReset: 'Reset',
          zoomResetTitle: 'Reset zoom and pan',
          zoomHint: 'Ctrl/Cmd+scroll to zoom, drag to pan',
          cyclesHeading: 'Circular dependencies',
          cyclesIntro: 'Each group shows one representative shortest path and the class-level evidence that creates its component dependencies.',
          componentsWord: 'components',
          hierarchicalNamespaces: 'hierarchical namespaces',
          peerNamespaces: 'peer namespaces',
          representativePath: 'Representative shortest path',
          involvedComponents: 'Components in this cycle',
          omittedFromPath: 'Not shown in the representative path',
          dependencyEvidence: 'Dependency evidence',
          noClassDependencies: 'No class dependencies were recorded for this edge.',
          noSourceEvidence: 'No source-location evidence was recorded.',
          noCyclesFound: 'No circular dependencies (ADP violations) were detected.',
          sourcesIntro: 'Copy or download the raw diagram sources and the Markdown report for use in external viewers or with AI assistants.',
          quadrantSource: 'Mermaid quadrantChart (.mmd)',
          flowchartSource: 'Mermaid flowchart (.mmd)',
          plantumlSource: 'PlantUML (.puml)',
          markdownSource: 'Markdown report (.md)',
          copy: 'Copy',
          copied: 'Copied',
          copyManual: 'Selected — press Ctrl/Cmd+C',
          download: 'Download',
          footerNote: 'Generated by psap. This page is fully self-contained: diagrams render in your browser with a bundled copy of Mermaid, and no data leaves your machine.',
        },
        ja: {
          documentTitle: 'psap — SAP Analysis Report',
          eyebrow: 'psap / sap analysis report',
          headline: 'SAP Analysis Report',
          description: '1つの自己完結ページに、サマリー・対話型I/Aグラフ・ブラウザ内Mermaid図・循環詳細・図ソースをまとめています。外部通信は必要とせず、一切行いません。',
          language: '表示言語',
          portalSections: 'ポータルのセクション',
          tabOverview: '概要',
          tabInteractive: '対話型 I/A',
          tabDiagrams: '図',
          tabCycles: '循環依存',
          tabSources: '図ソース',
          components: 'コンポーネント',
          plotted: 'プロット',
          meanDistance: '平均D',
          cycleGroups: '循環グループ',
          analysisCoverage: '解析カバレッジ',
          discoveredFiles: '発見',
          selectedFiles: '選択',
          analyzedFiles: '解析済み',
          excludedFiles: '除外',
          skippedFiles: 'スキップ',
          diagnosticsNotice: '解析上の注意',
          diagnosticsHint: '詳細は「対話型 I/A」タブを参照してください。',
          worstDistanceHeading: '主系列からの距離が大きいコンポーネント',
          component: 'コンポーネント',
          zone: 'ゾーン',
          painZone: '苦痛ゾーン',
          uselessZone: '無駄ゾーン',
          mainSequence: '主系列',
          noComponents: '評価可能な指標を持つコンポーネントがありません。',
          interactiveHint: '対話型の不安定度／抽象度レポートを下に埋め込んでいます。独自の言語セレクターを持ちます。',
          interactiveTitle: 'psap 対話型 I/A レポート',
          quadrantHeading: 'I/A 象限チャート',
          quadrantNote: '同梱の Mermaid がブラウザ内で描画します。ゾーンは象限ラベルで近似表示します（円弧境界の近似）。',
          flowchartHeading: '依存フローチャート',
          flowchartNote: 'コンポーネントとその依存関係。赤いエッジは最短の循環経路（ADP違反）です。',
          flowchartSkipped: 'グラフが大きいため（{edges}エッジ、上限{max}）、ブラウザ内描画をスキップしました。「図ソース」タブのソースを外部ビューアで利用するか、--depth や --exclude で対象を絞ってください。',
          diagramError: 'この図をブラウザで描画できませんでした。ソースは「図ソース」タブで確認できます。',
          zoomIn: '拡大',
          zoomOut: '縮小',
          zoomReset: 'リセット',
          zoomResetTitle: 'ズームと位置をリセット',
          zoomHint: 'Ctrl/Cmd+スクロールで拡縮、ドラッグで移動',
          cyclesHeading: '循環依存',
          cyclesIntro: '各グループには代表となる最短経路と、コンポーネント間依存を生むクラス単位の根拠を表示します。',
          componentsWord: 'コンポーネント',
          hierarchicalNamespaces: '親子関係の名前空間',
          peerNamespaces: '並列関係の名前空間',
          representativePath: '代表となる最短経路',
          involvedComponents: 'この循環に含まれるコンポーネント',
          omittedFromPath: '代表経路に含まれないコンポーネント',
          dependencyEvidence: '依存の根拠',
          noClassDependencies: 'この辺にはクラス単位の依存が記録されていません。',
          noSourceEvidence: 'ソース位置の根拠は記録されていません。',
          noCyclesFound: '循環依存（ADP違反）は検出されませんでした。',
          sourcesIntro: '図のソースと Markdown レポートをコピーまたはダウンロードして、外部ビューアやAIアシスタントで利用できます。',
          quadrantSource: 'Mermaid quadrantChart（.mmd）',
          flowchartSource: 'Mermaid flowchart（.mmd）',
          plantumlSource: 'PlantUML（.puml）',
          markdownSource: 'Markdown レポート（.md）',
          copy: 'コピー',
          copied: 'コピーしました',
          copyManual: '選択しました。Ctrl/Cmd+C でコピー',
          download: 'ダウンロード',
          footerNote: 'psap が生成しました。このページは完全に自己完結しており、図は同梱の Mermaid でブラウザ内描画され、データが外部へ出ることはありません。',
        },
      };

      const portal = JSON.parse(document.getElementById('psap-portal-data').textContent);
      const sources = {
        quadrant: __PSAP_MERMAID_QUADRANT__,
        flowchart: __PSAP_MERMAID_FLOWCHART__,
        plantuml: __PSAP_PLANTUML__,
        markdown: __PSAP_MARKDOWN__,
      };
      const language = document.getElementById('language');
      let locale = 'en';
      let diagramsRendered = false;

      function t(key, values = {}) {
        const template = messages[locale][key] ?? messages.en[key] ?? key;
        return template.replace(/\{(\w+)\}/g, (match, name) => Object.hasOwn(values, name) ? String(values[name]) : match);
      }

      function applyLanguage() {
        document.documentElement.lang = locale;
        document.title = t('documentTitle');
        document.querySelectorAll('[data-i18n]').forEach((element) => {
          element.textContent = t(element.dataset.i18n);
        });
        document.querySelectorAll('[data-i18n-aria-label]').forEach((element) => {
          element.setAttribute('aria-label', t(element.dataset.i18nAriaLabel));
        });
        document.querySelectorAll('[data-i18n-title]').forEach((element) => {
          element.setAttribute('title', t(element.dataset.i18nTitle));
        });
        // Re-translate any diagram fallback text (quadrant and flowchart) after a
        // language change, using the message key each element recorded for itself.
        document.querySelectorAll('.fallback[data-fallback-key]').forEach((element) => {
          element.textContent = t(element.dataset.fallbackKey, fallbackParams(element));
        });
      }

      function fallbackParams(element) {
        if (element.dataset.fallbackKey === 'flowchartSkipped') {
          return { edges: element.dataset.fallbackEdges, max: element.dataset.fallbackMax };
        }
        return {};
      }

      // Render a fallback notice that remembers its message key (and any placeholder
      // values) so applyLanguage() can re-translate it without losing which case it is.
      function setFallback(container, key, params) {
        const notice = document.createElement('p');
        notice.className = 'fallback';
        notice.dataset.fallbackKey = key;
        if (key === 'flowchartSkipped') {
          notice.dataset.fallbackEdges = String(params.edges);
          notice.dataset.fallbackMax = String(params.max);
        }
        notice.textContent = t(key, params);
        container.replaceChildren(notice);
      }

      // --- tabs -----------------------------------------------------------
      const tabs = Array.from(document.querySelectorAll('.tab'));
      function selectTab(tab) {
        tabs.forEach((current) => {
          const selected = current === tab;
          current.setAttribute('aria-selected', selected ? 'true' : 'false');
          document.getElementById(current.getAttribute('aria-controls')).hidden = !selected;
        });
        if (tab.id === 'tab-diagrams') renderDiagrams();
      }
      tabs.forEach((tab) => tab.addEventListener('click', () => selectTab(tab)));

      // --- sources --------------------------------------------------------
      document.getElementById('source-quadrant').textContent = sources.quadrant;
      document.getElementById('source-flowchart').textContent = sources.flowchart;
      document.getElementById('source-plantuml').textContent = sources.plantuml;
      document.getElementById('source-markdown').textContent = sources.markdown;

      document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
          const text = sources[button.dataset.copy];
          let copied = false;
          try {
            await navigator.clipboard.writeText(text);
            copied = true;
          } catch (error) {
            // Clipboard API unavailable (e.g. non-secure context): select the source
            // and try execCommand. Only report success when the copy actually happened.
            copied = selectAndCopy(button.dataset.copy);
          }
          button.textContent = copied ? t('copied') : t('copyManual');
          // Resolve the restored label at restore time so a language switch during
          // the transient window leaves the button in the current locale.
          setTimeout(() => { button.textContent = t('copy'); }, copied ? 1200 : 2500);
        });
      });

      function selectAndCopy(key) {
        const selection = window.getSelection();
        if (selection === null) return false;
        const range = document.createRange();
        range.selectNodeContents(document.getElementById('source-' + key));
        selection.removeAllRanges();
        selection.addRange(range);
        try {
          return document.execCommand('copy') === true;
        } catch (error) {
          return false;
        }
      }

      document.querySelectorAll('[data-download]').forEach((button) => {
        button.addEventListener('click', () => {
          const text = sources[button.dataset.download];
          const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
          const url = URL.createObjectURL(blob);
          const anchor = document.createElement('a');
          anchor.href = url;
          anchor.download = button.dataset.file;
          document.body.appendChild(anchor);
          anchor.click();
          anchor.remove();
          setTimeout(() => URL.revokeObjectURL(url), 0);
        });
      });

      // --- diagrams -------------------------------------------------------
      function renderDiagrams() {
        if (diagramsRendered) return;
        diagramsRendered = true;

        if (typeof mermaid === 'undefined') return;
        mermaid.initialize({
          startOnLoad: false,
          securityLevel: 'strict',
          maxTextSize: 5000000,
          maxEdges: 2000,
          // Render the quadrant at its natural ~500px instead of stretching it to
          // the container width (which makes the title, labels and points huge).
          // The flowchart keeps the default (fills width). Both stay zoomable.
          quadrantChart: { useMaxWidth: false },
        });

        drawDiagram('diagram-quadrant', 'psap-quadrant-svg', sources.quadrant);

        const flowchartContainer = document.getElementById('diagram-flowchart');
        if (!portal.flowchart.renderable) {
          setFallback(flowchartContainer, 'flowchartSkipped', { edges: portal.flowchart.edgeCount, max: portal.flowchart.maxEdges });
          return;
        }
        drawDiagram('diagram-flowchart', 'psap-flowchart-svg', sources.flowchart);
      }

      async function drawDiagram(containerId, renderId, source) {
        const container = document.getElementById(containerId);
        try {
          const { svg } = await mermaid.render(renderId, source);
          container.innerHTML = svg;
          initZoomPan(container);
        } catch (error) {
          setFallback(container, 'diagramError');
        }
      }

      // Zoom/pan tuning. ZOOM_STEP is the per-click / per-wheel-tick scale factor;
      // ZOOM_MIN / ZOOM_MAX bound how far a diagram can shrink or grow.
      const ZOOM_STEP = 1.25;
      const ZOOM_MIN = 0.2;
      const ZOOM_MAX = 10;
      const clampZoom = (scale) => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, scale));

      // initZoomPan makes a successfully-rendered diagram container zoomable and
      // pannable. The scale/translate transform is applied to the inner <svg>
      // (transform-origin 0 0), never to its text/source, so nothing here changes
      // what mermaid rendered. Controls are localized via data-i18n so a language
      // switch relabels them. Idempotent: a second call is a no-op.
      function initZoomPan(container) {
        const svg = container.querySelector('svg');
        if (svg === null || container.querySelector('.zoom-controls') !== null) return;

        container.classList.add('zoomable');
        container.title = t('zoomHint');
        container.dataset.i18nTitle = 'zoomHint';
        svg.style.transformOrigin = '0 0';
        svg.style.maxWidth = 'none';

        const state = { scale: 1, x: 0, y: 0 };
        const apply = () => {
          svg.style.transform = 'translate(' + state.x + 'px, ' + state.y + 'px) scale(' + state.scale + ')';
        };
        const reset = () => { state.scale = 1; state.x = 0; state.y = 0; apply(); };

        // Rescale around a viewport point (e.g. the cursor) so whatever sits under
        // it stays under it after the scale change.
        function zoomAt(factor, clientX, clientY) {
          const newScale = clampZoom(state.scale * factor);
          if (newScale === state.scale) return;
          const rect = container.getBoundingClientRect();
          const originX = clientX - rect.left;
          const originY = clientY - rect.top;
          state.x = originX - ((originX - state.x) / state.scale) * newScale;
          state.y = originY - ((originY - state.y) / state.scale) * newScale;
          state.scale = newScale;
          apply();
        }
        function zoomAtCenter(factor) {
          const rect = container.getBoundingClientRect();
          zoomAt(factor, rect.left + rect.width / 2, rect.top + rect.height / 2);
        }

        const controls = document.createElement('div');
        controls.className = 'zoom-controls';
        controls.append(
          zoomButton('+', 'zoomIn', () => zoomAtCenter(ZOOM_STEP)),
          zoomButton('−', 'zoomOut', () => zoomAtCenter(1 / ZOOM_STEP)),
          zoomButton(t('zoomReset'), 'zoomReset', reset, 'zoomResetTitle'),
        );
        container.append(controls);

        // Only zoom on Ctrl/Cmd+wheel; a plain wheel keeps scrolling the page.
        container.addEventListener('wheel', (event) => {
          if (!(event.ctrlKey || event.metaKey)) return;
          event.preventDefault();
          zoomAt(event.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP, event.clientX, event.clientY);
        }, { passive: false });

        let dragging = false;
        let lastX = 0;
        let lastY = 0;
        const stopDrag = () => {
          if (!dragging) return;
          dragging = false;
          container.classList.remove('dragging');
        };
        container.addEventListener('mousedown', (event) => {
          if (event.button !== 0 || event.target.closest('.zoom-controls') !== null) return;
          dragging = true;
          lastX = event.clientX;
          lastY = event.clientY;
          container.classList.add('dragging');
          event.preventDefault();
        });
        document.addEventListener('mousemove', (event) => {
          if (!dragging) return;
          state.x += event.clientX - lastX;
          state.y += event.clientY - lastY;
          lastX = event.clientX;
          lastY = event.clientY;
          apply();
        });
        document.addEventListener('mouseup', stopDrag);
        window.addEventListener('blur', stopDrag);

        apply();
      }

      function zoomButton(symbol, labelKey, onClick, titleKey) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'zoom-btn';
        button.textContent = symbol;
        // The Reset button also localizes its label; +/− keep their symbol and
        // only localize the tooltip.
        if (labelKey === 'zoomReset') button.dataset.i18n = labelKey;
        const tooltipKey = titleKey ?? labelKey;
        button.title = t(tooltipKey);
        button.dataset.i18nTitle = tooltipKey;
        button.addEventListener('click', onClick);
        return button;
      }

      language.addEventListener('change', () => {
        locale = language.value;
        applyLanguage();
      });

      applyLanguage();
    })();
  </script>
</body>
</html>
HTML;
    }
}

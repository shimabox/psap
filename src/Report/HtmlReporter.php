<?php

declare(strict_types=1);

namespace Psap\Report;

use JsonException;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\Zone;

/**
 * 自己完結したインタラクティブ I/A グラフを出力する。
 *
 * 外部アセットや通信を必要とせず、生成したHTMLをそのままブラウザで開ける。
 */
final class HtmlReporter implements ReporterInterface
{
    /**
     * @throws JsonException
     */
    public function render(ReportData $data): string
    {
        $payload = json_encode(
            $this->payload($data),
            JSON_THROW_ON_ERROR
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE,
        );

        return str_replace('__PSAP_DATA__', $payload, <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>psap — Interactive I/A report</title>
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

    button, input, select { font: inherit; }

    button:focus-visible, input:focus-visible, select:focus-visible, [tabindex]:focus-visible {
      outline: 3px solid rgb(36 87 197 / 35%);
      outline-offset: 2px;
    }

    .shell {
      width: min(1500px, calc(100% - 32px));
      margin: 0 auto;
      padding: 42px 0 56px;
    }

    .masthead {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 24px;
      align-items: end;
      margin-bottom: 24px;
    }

    .masthead-side {
      display: grid;
      gap: 10px;
      justify-items: end;
    }

    .language-field { min-width: 150px; }

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
      max-width: 760px;
      margin: 0;
      font-family: ui-serif, Georgia, Cambria, "Times New Roman", serif;
      font-size: clamp(2.25rem, 5vw, 4.8rem);
      font-weight: 500;
      letter-spacing: -.055em;
      line-height: .98;
    }

    .dek {
      max-width: 720px;
      margin: 14px 0 0;
      color: var(--muted);
      font-size: .98rem;
    }

    .summary {
      display: grid;
      grid-template-columns: repeat(4, minmax(88px, 1fr));
      margin: 0;
      padding: 0;
      border: 1px solid var(--grid);
      background: rgb(255 255 255 / 82%);
      box-shadow: var(--shadow);
    }

    .summary div { padding: 14px 16px; }
    .summary div + div { border-left: 1px solid var(--grid); }
    .summary dt {
      color: var(--muted);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .66rem;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .summary dd {
      margin: 2px 0 0;
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: 1.25rem;
      font-weight: 700;
    }
    .summary dd.has-cycles { color: var(--pain); }

    .toolbar {
      display: grid;
      grid-template-columns: minmax(220px, 1fr) minmax(150px, auto) minmax(190px, auto) auto;
      gap: 12px;
      align-items: end;
      margin-bottom: 14px;
      padding: 14px;
      border: 1px solid var(--grid);
      background: rgb(255 255 255 / 88%);
    }

    .field { display: grid; gap: 5px; }
    .field label {
      color: var(--muted);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
    }

    input[type="search"], select {
      min-height: 42px;
      width: 100%;
      border: 1px solid #aebec9;
      border-radius: 0;
      background: var(--paper);
      color: var(--ink);
      padding: 9px 11px;
    }

    .range-line {
      display: grid;
      grid-template-columns: minmax(110px, 1fr) 4.2ch;
      gap: 10px;
      align-items: center;
      min-height: 42px;
    }

    input[type="range"] { accent-color: var(--main); }
    output {
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .82rem;
    }

    .reset {
      min-height: 42px;
      border: 1px solid var(--ink);
      border-radius: 0;
      background: var(--ink);
      color: white;
      padding: 8px 16px;
      cursor: pointer;
    }

    .workspace {
      display: grid;
      grid-template-columns: minmax(0, 1.65fr) minmax(300px, .75fr);
      border: 1px solid var(--grid);
      background: var(--paper);
      box-shadow: var(--shadow);
    }

    .plot-panel { min-width: 0; padding: 18px; }
    .plot-meta {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      gap: 8px 16px;
      margin-bottom: 8px;
      color: var(--muted);
      font-size: .8rem;
    }

    .plot-meta strong { color: var(--ink); }
    .plot-wrap { position: relative; }

    #ia-chart {
      display: block;
      width: 100%;
      height: auto;
      min-height: 420px;
      overflow: visible;
      touch-action: manipulation;
    }

    .grid-line { stroke: var(--grid); stroke-width: 1; }
    .axis-line { stroke: var(--ink); stroke-width: 1.5; }
    .main-sequence { stroke: var(--main); stroke-width: 2.5; stroke-dasharray: 8 7; }
    .zone-pain { fill: rgb(181 75 53 / 8%); stroke: var(--pain); stroke-width: 1.3; }
    .zone-useless { fill: rgb(118 81 178 / 8%); stroke: var(--useless); stroke-width: 1.3; }
    .chart-copy {
      fill: var(--muted);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: 12px;
    }
    .zone-copy { font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .point { cursor: pointer; color: var(--main); }
    .point[data-zone="pain"] { color: var(--pain); }
    .point[data-zone="useless"] { color: var(--useless); }
    .point .mark { fill: currentColor; stroke: white; stroke-width: 2; }
    .point .hit { fill: transparent; }
    .point:hover .mark, .point:focus .mark, .point.is-selected .mark {
      stroke: var(--ink);
      stroke-width: 3;
    }
    .point.is-selected .halo { fill: none; stroke: var(--ink); stroke-width: 2; }
    .stack-count {
      fill: var(--ink);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: 10px;
      font-weight: 700;
      text-anchor: middle;
    }
    .projection { stroke: var(--ink); stroke-width: 1.5; stroke-dasharray: 3 4; pointer-events: none; }
    .projection-label {
      fill: var(--ink);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: 11px;
      font-weight: 700;
    }

    .tooltip {
      position: fixed;
      z-index: 10;
      width: min(300px, calc(100vw - 24px));
      border: 1px solid var(--ink);
      background: rgb(24 37 47 / 96%);
      color: white;
      padding: 10px 12px;
      box-shadow: 0 10px 30px rgb(24 37 47 / 25%);
      pointer-events: none;
    }
    .tooltip[hidden] { display: none; }
    .tooltip strong, .tooltip span { display: block; }
    .tooltip span {
      margin-top: 3px;
      color: #dce7ee;
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .72rem;
    }

    .inspector {
      min-width: 0;
      border-left: 1px solid var(--grid);
      background: #fbfdff;
      padding: 24px;
    }
    .inspector h2 {
      margin: 0 0 8px;
      font-family: ui-serif, Georgia, Cambria, "Times New Roman", serif;
      font-size: 1.45rem;
      font-weight: 600;
      overflow-wrap: anywhere;
    }
    .inspector-empty { color: var(--muted); }
    .metric-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      margin: 18px 0;
      border-top: 1px solid var(--grid);
      border-left: 1px solid var(--grid);
    }
    .metric-grid > div {
      position: relative;
      padding: 10px;
      border-right: 1px solid var(--grid);
      border-bottom: 1px solid var(--grid);
    }
    .metric-grid > div:hover, .metric-grid > div:focus { background: #f2f6ff; }
    .metric-grid dt { color: var(--muted); font-size: .66rem; text-transform: uppercase; }
    .metric-grid dd { margin: 2px 0 0; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-weight: 700; }
    .metric-definition {
      position: absolute;
      top: 35px;
      z-index: 20;
      visibility: hidden;
      width: min(260px, calc(100vw - 48px));
      border: 1px solid var(--ink);
      background: rgb(24 37 47 / 97%);
      color: white;
      padding: 10px 12px;
      box-shadow: 0 10px 30px rgb(24 37 47 / 25%);
      opacity: 0;
      text-transform: none;
      transition: opacity 120ms ease;
    }
    .metric-grid > div:nth-child(3n + 1) .metric-definition { left: 8px; }
    .metric-grid > div:nth-child(3n + 2) .metric-definition { left: 50%; transform: translateX(-50%); }
    .metric-grid > div:nth-child(3n) .metric-definition { right: 8px; }
    .metric-grid > div:hover .metric-definition,
    .metric-grid > div:focus .metric-definition {
      visibility: visible;
      opacity: 1;
    }
    .metric-definition strong {
      display: block;
      margin-bottom: 3px;
      font-size: .72rem;
      letter-spacing: .02em;
    }
    .metric-definition span {
      display: block;
      color: #dce7ee;
      font-size: .7rem;
      line-height: 1.45;
    }
    .class-heading { margin: 20px 0 8px; font-size: .8rem; letter-spacing: .06em; text-transform: uppercase; }
    .class-list, .coordinate-list { margin: 0; padding: 0; list-style: none; }
    .class-list { max-height: 390px; overflow: auto; border-top: 1px solid var(--grid); }
    .class-list li { padding: 9px 0; border-bottom: 1px solid var(--grid); }
    .class-list code { display: block; overflow-wrap: anywhere; font-size: .76rem; }
    .kind { color: var(--muted); font-size: .7rem; text-transform: uppercase; }
    .coordinate-list button {
      width: 100%;
      border: 0;
      border-bottom: 1px solid var(--grid);
      background: transparent;
      color: var(--main);
      padding: 8px 0;
      text-align: left;
      cursor: pointer;
      overflow-wrap: anywhere;
    }

    .cycle-notice {
      margin: 16px 0 0;
      border-left: 4px solid var(--pain);
      background: rgb(181 75 53 / 8%);
      padding: 12px 14px;
    }
    .cycle-notice strong { display: block; color: var(--pain); }
    .cycle-notice p { margin: 4px 0 10px; font-size: .8rem; }
    .cycle-link {
      border: 0;
      border-bottom: 1px solid currentColor;
      background: transparent;
      color: var(--pain);
      padding: 0;
      cursor: pointer;
      font-weight: 700;
    }

    .cycle-panel {
      margin-top: 18px;
      border: 1px solid rgb(181 75 53 / 45%);
      border-top: 5px solid var(--pain);
      background: var(--paper);
      box-shadow: var(--shadow);
    }
    .cycle-panel[hidden] { display: none; }
    .cycle-header { padding: 22px 24px 18px; }
    .cycle-header .eyebrow { color: var(--pain); }
    .cycle-header h2 {
      margin: 0;
      font-family: ui-serif, Georgia, Cambria, "Times New Roman", serif;
      font-size: clamp(1.55rem, 3vw, 2.2rem);
      font-weight: 600;
    }
    .cycle-header p:last-child { max-width: 780px; margin: 8px 0 0; color: var(--muted); }
    .cycle-group { border-top: 1px solid var(--grid); }
    .cycle-group > summary {
      padding: 16px 24px;
      cursor: pointer;
      font-weight: 700;
    }
    .cycle-group[open] > summary { background: rgb(181 75 53 / 6%); }
    .cycle-body { padding: 4px 24px 24px; }
    .cycle-label {
      margin: 18px 0 6px;
      color: var(--muted);
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
    }
    .cycle-path, .cycle-components { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .cycle-path code, .cycle-components code {
      border: 1px solid var(--grid);
      background: #f7fafc;
      padding: 4px 7px;
      overflow-wrap: anywhere;
    }
    .cycle-arrow { color: var(--pain); font-weight: 700; }
    .dependency-edge { margin-top: 12px; border: 1px solid var(--grid); }
    .dependency-edge h3 {
      margin: 0;
      background: #f7fafc;
      padding: 10px 12px;
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .8rem;
      overflow-wrap: anywhere;
    }
    .class-dependency { padding: 11px 12px; border-top: 1px solid var(--grid); }
    .class-dependency > code { display: block; overflow-wrap: anywhere; }
    .evidence-list {
      max-height: 180px;
      margin: 7px 0 0;
      padding-left: 20px;
      color: var(--muted);
      font-size: .74rem;
      overflow: auto;
    }
    .evidence-list code { color: var(--ink); overflow-wrap: anywhere; }

    .table-panel {
      margin-top: 18px;
      border: 1px solid var(--grid);
      background: var(--paper);
      box-shadow: var(--shadow);
      overflow: auto;
    }
    table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    caption { padding: 14px 16px; text-align: left; font-weight: 700; }
    th, td { padding: 10px 12px; border-top: 1px solid var(--grid); text-align: right; white-space: nowrap; }
    th { color: var(--muted); font-size: .68rem; letter-spacing: .05em; text-transform: uppercase; }
    th:first-child, td:first-child { text-align: left; }
    tbody tr { cursor: pointer; }
    tbody tr:hover, tbody tr:focus-within { background: #f2f6ff; }
    .component-button {
      border: 0;
      background: transparent;
      color: var(--main);
      padding: 0;
      text-align: left;
      cursor: pointer;
    }
    .empty-row { color: var(--muted); text-align: center !important; padding: 28px; }

    .legend {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      margin: 10px 0 0;
      padding: 0;
      list-style: none;
      color: var(--muted);
      font-size: .72rem;
    }
    .legend i { display: inline-block; width: 10px; height: 10px; margin-right: 5px; background: var(--main); }
    .legend .pain { background: var(--pain); }
    .legend .useless { background: var(--useless); transform: rotate(45deg); }
    .representation-note {
      max-width: 760px;
      margin: 10px 0 0;
      color: var(--muted);
      font-size: .72rem;
    }

    @media (max-width: 900px) {
      .masthead, .workspace { grid-template-columns: 1fr; }
      .masthead-side { justify-items: stretch; }
      .summary { width: 100%; }
      .toolbar { grid-template-columns: 1fr 1fr; }
      .inspector { border-top: 1px solid var(--grid); border-left: 0; }
    }

    @media (max-width: 580px) {
      .shell { width: min(100% - 20px, 1500px); padding-top: 24px; }
      .toolbar { grid-template-columns: 1fr; }
      .summary { grid-template-columns: repeat(2, minmax(88px, 1fr)); }
      .summary div { padding: 10px; }
      .summary div:nth-child(3) { border-top: 1px solid var(--grid); border-left: 0; }
      .summary div:nth-child(4) { border-top: 1px solid var(--grid); }
      .plot-panel { padding: 8px; }
      #ia-chart { min-height: 340px; }
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { scroll-behavior: auto !important; transition: none !important; }
    }

    @media (forced-colors: active) {
      .point .mark, .main-sequence, .projection { forced-color-adjust: auto; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <header class="masthead">
      <div>
        <p class="eyebrow" data-i18n="eyebrow">psap / architecture inspection board</p>
        <h1 data-i18n="headline">Instability meets abstraction.</h1>
        <p class="dek" data-i18n="description">Each point is a namespace component. Hover for its SAP metrics; select it to inspect the classes gathered beneath that point.</p>
      </div>
      <div class="masthead-side">
        <div class="field language-field">
          <label for="language" data-i18n="language">Language</label>
          <select id="language">
            <option value="en" selected>English</option>
            <option value="ja">日本語</option>
          </select>
        </div>
        <dl class="summary" aria-label="Analysis summary" data-i18n-aria-label="analysisSummary">
          <div><dt data-i18n="components">Components</dt><dd id="summary-components">—</dd></div>
          <div><dt data-i18n="plotted">Plotted</dt><dd id="summary-plotted">—</dd></div>
          <div><dt data-i18n="meanDistance">Mean D</dt><dd id="summary-distance">—</dd></div>
          <div><dt data-i18n="cycleGroups">Cycle groups</dt><dd id="summary-cycles">—</dd></div>
        </dl>
      </div>
    </header>

    <section class="toolbar" aria-label="Graph filters" data-i18n-aria-label="graphFilters">
      <div class="field">
        <label for="search" data-i18n="findComponent">Find a component or class</label>
        <input id="search" type="search" placeholder="e.g. Domain or UserRepository" data-i18n-placeholder="searchPlaceholder" autocomplete="off">
      </div>
      <div class="field">
        <label for="zone-filter" data-i18n="zone">Zone</label>
        <select id="zone-filter">
          <option value="all" data-i18n="allZones">All zones</option>
          <option value="none" data-i18n="mainSequence">Main sequence</option>
          <option value="pain" data-i18n="painZone">Pain zone</option>
          <option value="useless" data-i18n="uselessZone">Useless zone</option>
        </select>
      </div>
      <div class="field">
        <label for="distance-filter" data-i18n="minimumDistance">Minimum distance (D)</label>
        <div class="range-line">
          <input id="distance-filter" type="range" min="0" max="1" step="0.01" value="0">
          <output id="distance-output" for="distance-filter">0.00</output>
        </div>
      </div>
      <button id="reset" class="reset" type="button" data-i18n="resetFilters">Reset filters</button>
    </section>

    <section class="workspace">
      <div class="plot-panel">
        <div class="plot-meta">
          <strong id="result-count" aria-live="polite" data-i18n="loadingComponents">Loading components…</strong>
          <span data-i18n="keyboardHelp">Keyboard: Tab to a point, Enter to select, Esc to clear.</span>
        </div>
        <div class="plot-wrap">
          <svg id="ia-chart" viewBox="0 0 640 620" role="group" aria-label="SAP instability and abstractness graph" aria-describedby="chart-description" data-i18n-aria-label="chartTitle">
            <desc id="chart-description" data-i18n="chartDescription">Instability runs from zero to one on the horizontal axis. Abstractness runs from zero to one on the vertical axis. Select a point to inspect its component classes.</desc>
            <g aria-hidden="true">
              <path class="zone-pain" d="M70 570 L70 300 A270 270 0 0 1 340 570 Z"></path>
              <path class="zone-useless" d="M610 30 L340 30 A270 270 0 0 0 610 300 Z"></path>
              <line class="grid-line" x1="70" y1="435" x2="610" y2="435"></line>
              <line class="grid-line" x1="70" y1="300" x2="610" y2="300"></line>
              <line class="grid-line" x1="70" y1="165" x2="610" y2="165"></line>
              <line class="grid-line" x1="205" y1="30" x2="205" y2="570"></line>
              <line class="grid-line" x1="340" y1="30" x2="340" y2="570"></line>
              <line class="grid-line" x1="475" y1="30" x2="475" y2="570"></line>
              <line class="axis-line" x1="70" y1="30" x2="70" y2="570"></line>
              <line class="axis-line" x1="70" y1="570" x2="610" y2="570"></line>
              <line class="main-sequence" x1="70" y1="30" x2="610" y2="570"></line>
              <text class="chart-copy zone-copy" x="92" y="545" data-i18n="painZone">Pain zone</text>
              <text class="chart-copy zone-copy" x="588" y="56" text-anchor="end" data-i18n="uselessZone">Useless zone</text>
              <text class="chart-copy" x="350" y="286" transform="rotate(45 350 286)" data-i18n="mainSequenceFormula">Main sequence · A + I = 1</text>
              <text class="chart-copy" x="70" y="590" text-anchor="middle">0</text>
              <text class="chart-copy" x="205" y="590" text-anchor="middle">.25</text>
              <text class="chart-copy" x="340" y="590" text-anchor="middle">.50</text>
              <text class="chart-copy" x="475" y="590" text-anchor="middle">.75</text>
              <text class="chart-copy" x="610" y="590" text-anchor="middle">1</text>
              <text class="chart-copy" x="55" y="574" text-anchor="end">0</text>
              <text class="chart-copy" x="55" y="439" text-anchor="end">.25</text>
              <text class="chart-copy" x="55" y="304" text-anchor="end">.50</text>
              <text class="chart-copy" x="55" y="169" text-anchor="end">.75</text>
              <text class="chart-copy" x="55" y="34" text-anchor="end">1</text>
              <text class="chart-copy" x="340" y="613" text-anchor="middle" data-i18n="instabilityAxis">Instability (I)</text>
              <text class="chart-copy" x="15" y="300" text-anchor="middle" transform="rotate(-90 15 300)" data-i18n="abstractnessAxis">Abstractness (A)</text>
            </g>
            <g id="projection-layer" aria-hidden="true"></g>
            <g id="point-layer"></g>
          </svg>
          <div id="tooltip" class="tooltip" role="tooltip" hidden><strong></strong><span></span></div>
        </div>
        <ul class="legend" aria-label="Point legend" data-i18n-aria-label="pointLegend">
          <li><i></i><span data-i18n="mainSequence">Main sequence</span></li>
          <li><i class="pain"></i><span data-i18n="painZone">Pain zone</span></li>
          <li><i class="useless"></i><span data-i18n="uselessZone">Useless zone</span></li>
        </ul>
        <p class="representation-note" data-i18n="representationNote">Zone display: this HTML report draws the radius-based boundaries used by psap. Mermaid quadrant charts show the same zone concepts as quadrant labels, so their shapes are an approximation. Point metrics and coordinates come from the same analysis.</p>
      </div>

      <aside id="inspector" class="inspector" aria-live="polite" aria-label="Selected component" data-i18n-aria-label="selectedComponent">
        <p class="eyebrow" data-i18n="selectedComponent">Selected component</p>
        <h2 data-i18n="noComponentSelected">No component selected</h2>
        <p class="inspector-empty" data-i18n="inspectorEmpty">Choose a point or a row below. Its SAP metrics and contained classes will stay pinned here.</p>
      </aside>
    </section>

    <section class="table-panel" aria-label="Component data" data-i18n-aria-label="componentData">
      <table>
        <caption data-i18n="matchingComponents">Components matching the current filters</caption>
        <thead><tr><th scope="col" data-i18n="component">Component</th><th scope="col" data-i18n="types">Types</th><th scope="col">Ca</th><th scope="col">Ce</th><th scope="col">I</th><th scope="col">A</th><th scope="col">D</th><th scope="col" data-i18n="zone">Zone</th></tr></thead>
        <tbody id="component-rows"></tbody>
      </table>
    </section>

    <section id="cycle-panel" class="cycle-panel" aria-labelledby="cycle-heading" hidden>
      <header class="cycle-header">
        <p class="eyebrow" data-i18n="adpViolation">ADP violation</p>
        <h2 id="cycle-heading" data-i18n="cycleHeading">Circular dependencies detected</h2>
        <p data-i18n="cycleIntro">Each group shows one representative shortest path and the class-level evidence that creates its component dependencies.</p>
      </header>
      <div id="cycle-groups"></div>
    </section>
  </main>

  <script id="psap-data" type="application/json">__PSAP_DATA__</script>
  <script>
    (() => {
      'use strict';

      const messages = {
        en: {
          documentTitle: 'psap — Interactive I/A report',
          eyebrow: 'psap / architecture inspection board',
          headline: 'Instability meets abstraction.',
          description: 'Each point is a namespace component. Hover for its SAP metrics; select it to inspect the classes gathered beneath that point.',
          language: 'Language',
          analysisSummary: 'Analysis summary',
          components: 'Components',
          plotted: 'Plotted',
          meanDistance: 'Mean D',
          cycleGroups: 'Cycle groups',
          adpViolation: 'ADP violation',
          cycleHeading: 'Circular dependencies detected',
          cycleIntro: 'Each group shows one representative shortest path and the class-level evidence that creates its component dependencies.',
          cycleGroupSummary: 'Cycle {number} · {count} components · {relation}',
          hierarchicalNamespaces: 'hierarchical namespaces',
          peerNamespaces: 'peer namespaces',
          representativePath: 'Representative shortest path',
          involvedComponents: 'Components in this cycle',
          omittedFromPath: 'Not shown in the representative path',
          dependencyEvidence: 'Dependency evidence',
          noClassDependencies: 'No class dependencies were recorded for this edge.',
          noSourceEvidence: 'No source-location evidence was recorded.',
          evidenceAt: '{kind} at {file}:{line}',
          componentInCycle: 'Part of 1 cycle group',
          componentInCycles: 'Part of {count} cycle groups',
          cycleInspectorText: 'This component participates in a circular dependency.',
          showCycleDetails: 'Show cycle details',
          graphFilters: 'Graph filters',
          findComponent: 'Find a component or class',
          searchPlaceholder: 'e.g. Domain or UserRepository',
          zone: 'Zone',
          allZones: 'All zones',
          mainSequence: 'Main sequence',
          painZone: 'Pain zone',
          uselessZone: 'Useless zone',
          minimumDistance: 'Minimum distance (D)',
          resetFilters: 'Reset filters',
          loadingComponents: 'Loading components…',
          keyboardHelp: 'Keyboard: Tab to a point, Enter to select, Esc to clear.',
          chartTitle: 'SAP instability and abstractness graph',
          chartDescription: 'Instability runs from zero to one on the horizontal axis. Abstractness runs from zero to one on the vertical axis. Select a point to inspect its component classes.',
          mainSequenceFormula: 'Main sequence · A + I = 1',
          instabilityAxis: 'Instability (I)',
          abstractnessAxis: 'Abstractness (A)',
          pointLegend: 'Point legend',
          representationNote: 'Zone display: this HTML report draws the radius-based boundaries used by psap. Mermaid quadrant charts show the same zone concepts as quadrant labels, so their shapes are an approximation. Point metrics and coordinates come from the same analysis.',
          selectedComponent: 'Selected component',
          noComponentSelected: 'No component selected',
          inspectorEmpty: 'Choose a point or a row below. Its SAP metrics and contained classes will stay pinned here.',
          componentData: 'Component data',
          matchingComponents: 'Components matching the current filters',
          component: 'Component',
          types: 'Types',
          kindConcrete: 'concrete',
          kindInterface: 'interface',
          kindAbstract: 'abstract',
          kindEnum: 'enum',
          kindTrait: 'trait',
          metricIName: 'Instability (I)',
          metricIHelp: 'Ce / (Ca + Ce). Near 0 means stable; near 1 means unstable.',
          metricAName: 'Abstractness (A)',
          metricAHelp: 'Abstract types / all types. 0 means fully concrete; 1 means fully abstract.',
          metricDName: 'Distance from main sequence (D)',
          metricDHelp: '|A + I - 1|. Near 0 means abstractness and stability are well balanced.',
          metricCaName: 'Afferent coupling (Ca)',
          metricCaHelp: 'Number of external classes that depend on classes in this component. Higher means more classes rely on it.',
          metricCeName: 'Efferent coupling (Ce)',
          metricCeHelp: 'Number of classes in this component that depend on external classes. Higher means it relies on more outside classes.',
          metricTypesName: 'Types',
          metricTypesHelp: 'Number of class, interface, abstract class, enum, and trait declarations in this component.',
          pointLabel: '{name}. I {i}, A {a}, D {d}',
          stackedPointLabel: '{count} components at I {i}, A {a}',
          sameCoordinates: '{name} + {count} at same coordinates',
          tooltipMetrics: 'I {i} · A {a} · D {d} · Ca {ca} · Ce {ce} · {count} types',
          typesAndZone: '{count} types · {zone}',
          componentsSharePoint: '{count} components share this point',
          containedClasses: 'Contained classes',
          noClasses: 'No class declarations were recorded.',
          noMatches: 'No components match these filters. Reset filters to see the full report.',
          resultCount: '{shown} of {total} plotted components shown',
        },
        ja: {
          documentTitle: 'psap — 対話型I/Aレポート',
          eyebrow: 'psap / architecture inspection board',
          headline: 'Instability meets abstraction.',
          description: '各点は名前空間コンポーネントを表します。マウスを重ねるとSAP指標を、選択するとその点に含まれるクラスを確認できます。',
          language: '表示言語',
          analysisSummary: '解析概要',
          components: 'コンポーネント',
          plotted: 'プロット',
          meanDistance: '平均D',
          cycleGroups: '循環グループ',
          adpViolation: 'ADP違反',
          cycleHeading: '循環依存が検出されました',
          cycleIntro: '各グループには代表となる最短経路と、コンポーネント間依存を生むクラス単位の根拠を表示します。',
          cycleGroupSummary: '循環 {number} · {count}コンポーネント · {relation}',
          hierarchicalNamespaces: '親子関係の名前空間',
          peerNamespaces: '並列関係の名前空間',
          representativePath: '代表となる最短経路',
          involvedComponents: 'この循環に含まれるコンポーネント',
          omittedFromPath: '代表経路に含まれないコンポーネント',
          dependencyEvidence: '依存の根拠',
          noClassDependencies: 'この辺にはクラス単位の依存が記録されていません。',
          noSourceEvidence: 'ソース位置の根拠は記録されていません。',
          evidenceAt: '{kind}（{file}:{line}）',
          componentInCycle: '1件の循環グループに含まれます',
          componentInCycles: '{count}件の循環グループに含まれます',
          cycleInspectorText: 'このコンポーネントは循環依存に関与しています。',
          showCycleDetails: '循環の詳細を表示',
          graphFilters: 'グラフの絞り込み',
          findComponent: 'コンポーネントまたはクラスを検索',
          searchPlaceholder: '例: Domain、UserRepository',
          zone: 'ゾーン',
          allZones: 'すべてのゾーン',
          mainSequence: '主系列',
          painZone: '苦痛ゾーン',
          uselessZone: '無駄ゾーン',
          minimumDistance: '最小距離 (D)',
          resetFilters: '絞り込みを解除',
          loadingComponents: 'コンポーネントを読み込み中…',
          keyboardHelp: 'キーボード: Tabで点へ移動、Enterで選択、Escで解除。',
          chartTitle: 'SAP 不安定度・抽象度グラフ',
          chartDescription: '横軸は0から1までの不安定度、縦軸は0から1までの抽象度です。点を選ぶとコンポーネントに含まれるクラスを確認できます。',
          mainSequenceFormula: '主系列 · A + I = 1',
          instabilityAxis: '不安定度 (I)',
          abstractnessAxis: '抽象度 (A)',
          pointLegend: '点の凡例',
          representationNote: 'ゾーン表示: このHTMLレポートはpsapの実際の判定と同じ円弧境界を描きます。MermaidのquadrantChartは同じ概念を象限ラベルで近似します。点の指標と座標は同じ解析結果です。',
          selectedComponent: '選択中のコンポーネント',
          noComponentSelected: 'コンポーネントが選択されていません',
          inspectorEmpty: 'グラフの点または下の一覧を選ぶと、SAP指標と含まれるクラスをここに固定表示します。',
          componentData: 'コンポーネントデータ',
          matchingComponents: '現在の絞り込みに一致するコンポーネント',
          component: 'コンポーネント',
          types: '型数',
          kindConcrete: '具象クラス',
          kindInterface: 'インターフェース',
          kindAbstract: '抽象クラス',
          kindEnum: 'enum',
          kindTrait: 'トレイト',
          metricIName: '不安定度 (I)',
          metricIHelp: 'Ce / (Ca + Ce)。0に近いほど安定、1に近いほど不安定です。',
          metricAName: '抽象度 (A)',
          metricAHelp: '抽象型数 / 総型数。0はすべて具象、1はすべて抽象です。',
          metricDName: '主系列からの距離 (D)',
          metricDHelp: '|A + I - 1|。0に近いほど抽象度と安定度のバランスがよいことを示します。',
          metricCaName: '求心性結合度 (Ca)',
          metricCaHelp: 'このコンポーネント内のクラスに依存する外部クラスの数です。大きいほど多くのクラスから使われています。',
          metricCeName: '遠心性結合度 (Ce)',
          metricCeHelp: '外部クラスに依存する、このコンポーネント内のクラスの数です。大きいほど多くの外部クラスを使っています。',
          metricTypesName: '型数',
          metricTypesHelp: 'このコンポーネントに含まれるクラス、インターフェース、抽象クラス、enum、トレイトの宣言数です。',
          pointLabel: '{name}。I {i}、A {a}、D {d}',
          stackedPointLabel: '同じ座標に{count}コンポーネント。I {i}、A {a}',
          sameCoordinates: '{name} 他{count}件（同じ座標）',
          tooltipMetrics: 'I {i} · A {a} · D {d} · Ca {ca} · Ce {ce} · {count}型',
          typesAndZone: '{count}型 · {zone}',
          componentsSharePoint: '{count}コンポーネントが同じ点にあります',
          containedClasses: '含まれるクラス',
          noClasses: 'クラス宣言は記録されていません。',
          noMatches: '絞り込みに一致するコンポーネントがありません。絞り込みを解除すると全件を表示できます。',
          resultCount: 'プロット対象{total}件中{shown}件を表示',
        },
      };

      const SVG_NS = 'http://www.w3.org/2000/svg';
      const report = JSON.parse(document.getElementById('psap-data').textContent);
      const chart = document.getElementById('ia-chart');
      const pointLayer = document.getElementById('point-layer');
      const projectionLayer = document.getElementById('projection-layer');
      const rows = document.getElementById('component-rows');
      const inspector = document.getElementById('inspector');
      const tooltip = document.getElementById('tooltip');
      const search = document.getElementById('search');
      const zoneFilter = document.getElementById('zone-filter');
      const distanceFilter = document.getElementById('distance-filter');
      const distanceOutput = document.getElementById('distance-output');
      const resultCount = document.getElementById('result-count');
      const language = document.getElementById('language');
      const cyclePanel = document.getElementById('cycle-panel');
      const cycleGroups = document.getElementById('cycle-groups');
      const plot = { left: 70, top: 30, size: 540 };
      let locale = 'en';
      let selected = null;
      let selectedGroup = [];

      const evaluable = report.components.filter((component) => component.metricsEvaluable);
      document.getElementById('summary-components').textContent = String(report.summary.componentCount);
      document.getElementById('summary-plotted').textContent = String(evaluable.length);
      document.getElementById('summary-distance').textContent = report.summary.meanDistance === null ? 'N/A' : report.summary.meanDistance.toFixed(2);
      const summaryCycles = document.getElementById('summary-cycles');
      summaryCycles.textContent = String(report.summary.cycleGroupCount);
      summaryCycles.classList.toggle('has-cycles', report.summary.cycleGroupCount > 0);

      function t(key, values = {}) {
        return messages[locale][key].replace(/\{(\w+)\}/g, (match, name) => Object.hasOwn(values, name) ? String(values[name]) : match);
      }

      function applyLanguage() {
        document.documentElement.lang = locale;
        document.title = t('documentTitle');
        document.querySelectorAll('[data-i18n]').forEach((element) => {
          element.textContent = t(element.dataset.i18n);
        });
        document.querySelectorAll('[data-i18n-placeholder]').forEach((element) => {
          element.setAttribute('placeholder', t(element.dataset.i18nPlaceholder));
        });
        document.querySelectorAll('[data-i18n-aria-label]').forEach((element) => {
          element.setAttribute('aria-label', t(element.dataset.i18nAriaLabel));
        });
        update();
        renderCycles();
        if (selected) renderInspector(selected, selectedGroup);
      }

      function svgElement(name, attributes = {}) {
        const element = document.createElementNS(SVG_NS, name);
        Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
        return element;
      }

      function value(value) {
        return value === null ? 'N/A' : Number(value).toFixed(2);
      }

      function zoneName(zone) {
        if (zone === 'pain') return t('painZone');
        if (zone === 'useless') return t('uselessZone');
        return t('mainSequence');
      }

      function kindName(kind) {
        const key = `kind${kind.charAt(0).toUpperCase()}${kind.slice(1)}`;
        return messages[locale][key] || kind;
      }

      function matches(component) {
        const query = search.value.trim().toLocaleLowerCase();
        const zone = zoneFilter.value;
        const minimumDistance = Number(distanceFilter.value);
        const haystack = [component.name, ...component.classes.map((item) => `${item.fqcn} ${item.kind} ${kindName(item.kind)}`)].join(' ').toLocaleLowerCase();
        return component.metricsEvaluable
          && (query === '' || haystack.includes(query))
          && (zone === 'all' || (component.zone || 'none') === zone)
          && component.distance >= minimumDistance;
      }

      function coordinates(component) {
        return {
          x: plot.left + component.instability * plot.size,
          y: plot.top + (1 - component.abstractness) * plot.size,
        };
      }

      function groupAtSameCoordinate(components) {
        const groups = new Map();
        components.forEach((component) => {
          const key = `${component.instability.toFixed(6)}:${component.abstractness.toFixed(6)}`;
          if (!groups.has(key)) groups.set(key, []);
          groups.get(key).push(component);
        });
        return [...groups.values()];
      }

      function marker(group) {
        const component = group[0];
        const { x, y } = coordinates(component);
        const element = svgElement('g', {
          class: `point${selected && group.some((item) => item.name === selected.name) ? ' is-selected' : ''}`,
          'data-zone': component.zone || 'none',
          tabindex: '0',
          role: 'button',
          'aria-label': group.length === 1
            ? t('pointLabel', { name: component.name, i: value(component.instability), a: value(component.abstractness), d: value(component.distance) })
            : t('stackedPointLabel', { count: group.length, i: value(component.instability), a: value(component.abstractness) }),
        });

        element.append(svgElement('circle', { class: 'hit', cx: x, cy: y, r: 17 }));
        if (selected && group.some((item) => item.name === selected.name)) {
          element.append(svgElement('circle', { class: 'halo', cx: x, cy: y, r: 14 }));
        }
        if (component.zone === 'pain') {
          element.append(svgElement('rect', { class: 'mark', x: x - 6, y: y - 6, width: 12, height: 12 }));
        } else if (component.zone === 'useless') {
          element.append(svgElement('polygon', { class: 'mark', points: `${x},${y - 8} ${x + 8},${y} ${x},${y + 8} ${x - 8},${y}` }));
        } else {
          element.append(svgElement('circle', { class: 'mark', cx: x, cy: y, r: 7 }));
        }
        if (group.length > 1) {
          const count = svgElement('text', { class: 'stack-count', x, y: y - 12 });
          count.textContent = String(group.length);
          element.append(count);
        }

        const show = (event) => showTooltip(group, event);
        element.addEventListener('mouseenter', show);
        element.addEventListener('mousemove', show);
        element.addEventListener('mouseleave', hideTooltip);
        element.addEventListener('focus', show);
        element.addEventListener('blur', hideTooltip);
        element.addEventListener('click', () => selectComponent(group[0], group));
        element.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            selectComponent(group[0], group);
          }
          if (event.key === 'Escape') clearSelection();
        });
        return element;
      }

      function showTooltip(group, event) {
        const component = group[0];
        tooltip.querySelector('strong').textContent = group.length === 1 ? component.name : t('sameCoordinates', { name: component.name, count: group.length - 1 });
        tooltip.querySelector('span').textContent = t('tooltipMetrics', {
          i: value(component.instability),
          a: value(component.abstractness),
          d: value(component.distance),
          ca: component.ca,
          ce: component.ce,
          count: component.classCount,
        });
        tooltip.hidden = false;
        let x = event.clientX;
        let y = event.clientY;
        if (!x && event.currentTarget) {
          const box = event.currentTarget.getBoundingClientRect();
          x = box.left + box.width / 2;
          y = box.top;
        }
        const width = tooltip.offsetWidth;
        const height = tooltip.offsetHeight;
        tooltip.style.left = `${Math.max(12, Math.min(window.innerWidth - width - 12, x + 14))}px`;
        tooltip.style.top = `${Math.max(12, Math.min(window.innerHeight - height - 12, y + 14))}px`;
      }

      function hideTooltip() {
        tooltip.hidden = true;
      }

      function renderProjection(component) {
        projectionLayer.replaceChildren();
        if (!component) return;
        const start = coordinates(component);
        const projectedI = Math.max(0, Math.min(1, (component.instability - component.abstractness + 1) / 2));
        const end = {
          x: plot.left + projectedI * plot.size,
          y: plot.top + projectedI * plot.size,
        };
        projectionLayer.append(svgElement('line', { class: 'projection', x1: start.x, y1: start.y, x2: end.x, y2: end.y }));
        const label = svgElement('text', { class: 'projection-label', x: (start.x + end.x) / 2 + 6, y: (start.y + end.y) / 2 - 6 });
        label.textContent = `D ${value(component.distance)}`;
        projectionLayer.append(label);
      }

      function appendTextElement(parent, name, text, className) {
        const element = document.createElement(name);
        if (className) element.className = className;
        element.textContent = text;
        parent.append(element);
        return element;
      }

      function appendMetric(parent, definition) {
        const wrapper = document.createElement('div');
        const tooltipId = `metric-${definition.key}-help`;
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'group');
        wrapper.setAttribute('aria-label', `${t(`${definition.key}Name`)}: ${definition.displayValue}`);
        wrapper.setAttribute('aria-describedby', tooltipId);

        const term = document.createElement('dt');
        appendTextElement(term, 'span', definition.label);

        const tooltip = document.createElement('span');
        tooltip.id = tooltipId;
        tooltip.className = 'metric-definition';
        tooltip.setAttribute('role', 'tooltip');
        appendTextElement(tooltip, 'strong', t(`${definition.key}Name`));
        appendTextElement(tooltip, 'span', t(`${definition.key}Help`));

        term.append(tooltip);
        wrapper.append(term);
        appendTextElement(wrapper, 'dd', definition.displayValue);
        parent.append(wrapper);
      }

      function appendCodeList(parent, values) {
        values.forEach((item, index) => {
          if (index > 0) appendTextElement(parent, 'span', '→', 'cycle-arrow');
          appendTextElement(parent, 'code', item);
        });
      }

      function renderCycles() {
        cyclePanel.hidden = report.cycles.length === 0;
        cycleGroups.replaceChildren();
        report.cycles.forEach((cycle, index) => {
          const details = document.createElement('details');
          details.id = `cycle-group-${index}`;
          details.className = 'cycle-group';

          const relation = cycle.namespaceRelation === 'hierarchical'
            ? t('hierarchicalNamespaces')
            : t('peerNamespaces');
          appendTextElement(details, 'summary', t('cycleGroupSummary', {
            number: index + 1,
            count: cycle.componentCount,
            relation,
          }));

          const body = document.createElement('div');
          body.className = 'cycle-body';
          appendTextElement(body, 'p', t('representativePath'), 'cycle-label');
          const path = document.createElement('div');
          path.className = 'cycle-path';
          appendCodeList(path, cycle.representativePath);
          body.append(path);

          appendTextElement(body, 'p', t('involvedComponents'), 'cycle-label');
          const components = document.createElement('div');
          components.className = 'cycle-components';
          cycle.components.forEach((name) => appendTextElement(components, 'code', name));
          body.append(components);

          if (cycle.omittedComponents.length > 0) {
            appendTextElement(body, 'p', t('omittedFromPath'), 'cycle-label');
            const omitted = document.createElement('div');
            omitted.className = 'cycle-components';
            cycle.omittedComponents.forEach((name) => appendTextElement(omitted, 'code', name));
            body.append(omitted);
          }

          appendTextElement(body, 'p', t('dependencyEvidence'), 'cycle-label');
          cycle.dependencies.forEach((dependency) => {
            const edge = document.createElement('section');
            edge.className = 'dependency-edge';
            appendTextElement(edge, 'h3', `${dependency.from} → ${dependency.to}`);
            if (dependency.classDependencies.length === 0) {
              appendTextElement(edge, 'p', t('noClassDependencies'), 'class-dependency');
            } else {
              dependency.classDependencies.forEach((classDependency) => {
                const item = document.createElement('div');
                item.className = 'class-dependency';
                appendTextElement(item, 'code', `${classDependency.from} → ${classDependency.to}`);
                if (classDependency.evidence.length === 0) {
                  appendTextElement(item, 'p', t('noSourceEvidence'));
                } else {
                  const evidenceList = document.createElement('ul');
                  evidenceList.className = 'evidence-list';
                  classDependency.evidence.forEach((evidence) => {
                    const entry = document.createElement('li');
                    appendTextElement(entry, 'code', t('evidenceAt', evidence));
                    evidenceList.append(entry);
                  });
                  item.append(evidenceList);
                }
                edge.append(item);
              });
            }
            body.append(edge);
          });

          details.append(body);
          cycleGroups.append(details);
        });
      }

      function focusCycle(index) {
        const details = document.getElementById(`cycle-group-${index}`);
        if (!details) return;
        details.open = true;
        details.scrollIntoView({ behavior: 'smooth', block: 'start' });
        details.querySelector('summary').focus({ preventScroll: true });
      }

      function renderInspector(component, coordinateGroup = [component]) {
        inspector.replaceChildren();
        appendTextElement(inspector, 'p', t('selectedComponent'), 'eyebrow');
        appendTextElement(inspector, 'h2', component.name);
        appendTextElement(inspector, 'p', t('typesAndZone', { count: component.classCount, zone: zoneName(component.zone) }));

        const componentCycles = report.cycles
          .map((cycle, index) => ({ cycle, index }))
          .filter(({ cycle }) => cycle.components.includes(component.name));
        if (componentCycles.length > 0) {
          const notice = document.createElement('div');
          notice.className = 'cycle-notice';
          appendTextElement(
            notice,
            'strong',
            t(componentCycles.length === 1 ? 'componentInCycle' : 'componentInCycles', { count: componentCycles.length }),
          );
          appendTextElement(notice, 'p', t('cycleInspectorText'));
          const button = appendTextElement(notice, 'button', t('showCycleDetails'), 'cycle-link');
          button.type = 'button';
          button.addEventListener('click', () => focusCycle(componentCycles[0].index));
          inspector.append(notice);
        }

        const metrics = document.createElement('dl');
        metrics.className = 'metric-grid';
        [
          { key: 'metricI', label: 'I', displayValue: value(component.instability) },
          { key: 'metricA', label: 'A', displayValue: value(component.abstractness) },
          { key: 'metricD', label: 'D', displayValue: value(component.distance) },
          { key: 'metricCa', label: 'Ca', displayValue: String(component.ca) },
          { key: 'metricCe', label: 'Ce', displayValue: String(component.ce) },
          { key: 'metricTypes', label: t('types'), displayValue: String(component.classCount) },
        ].forEach((definition) => appendMetric(metrics, definition));
        inspector.append(metrics);

        if (coordinateGroup.length > 1) {
          appendTextElement(inspector, 'h3', t('componentsSharePoint', { count: coordinateGroup.length }), 'class-heading');
          const alternatives = document.createElement('ul');
          alternatives.className = 'coordinate-list';
          coordinateGroup.forEach((item) => {
            const entry = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = item.name;
            button.addEventListener('click', () => selectComponent(item, coordinateGroup));
            entry.append(button);
            alternatives.append(entry);
          });
          inspector.append(alternatives);
        }

        appendTextElement(inspector, 'h3', t('containedClasses'), 'class-heading');
        const list = document.createElement('ul');
        list.className = 'class-list';
        if (component.classes.length === 0) {
          appendTextElement(list, 'li', t('noClasses'));
        } else {
          component.classes.forEach((item) => {
            const entry = document.createElement('li');
            appendTextElement(entry, 'code', item.fqcn);
            appendTextElement(entry, 'span', kindName(item.kind), 'kind');
            list.append(entry);
          });
        }
        inspector.append(list);
      }

      function selectComponent(component, coordinateGroup = [component]) {
        selected = component;
        selectedGroup = coordinateGroup;
        renderInspector(component, coordinateGroup);
        renderProjection(component);
        renderPoints(filteredComponents());
      }

      function clearSelection() {
        selected = null;
        selectedGroup = [];
        projectionLayer.replaceChildren();
        inspector.replaceChildren();
        appendTextElement(inspector, 'p', t('selectedComponent'), 'eyebrow');
        appendTextElement(inspector, 'h2', t('noComponentSelected'));
        appendTextElement(inspector, 'p', t('inspectorEmpty'), 'inspector-empty');
        renderPoints(filteredComponents());
      }

      function filteredComponents() {
        return report.components.filter(matches);
      }

      function renderPoints(components) {
        pointLayer.replaceChildren();
        groupAtSameCoordinate(components).forEach((group) => pointLayer.append(marker(group)));
      }

      function renderRows(components) {
        rows.replaceChildren();
        if (components.length === 0) {
          const row = document.createElement('tr');
          const cell = document.createElement('td');
          cell.colSpan = 8;
          cell.className = 'empty-row';
          cell.textContent = t('noMatches');
          row.append(cell);
          rows.append(row);
          return;
        }
        components.forEach((component) => {
          const row = document.createElement('tr');
          const nameCell = document.createElement('td');
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'component-button';
          button.textContent = component.name;
          button.addEventListener('click', () => selectComponent(component));
          nameCell.append(button);
          row.append(nameCell);
          [component.classCount, component.ca, component.ce, value(component.instability), value(component.abstractness), value(component.distance), zoneName(component.zone)].forEach((metric) => {
            appendTextElement(row, 'td', String(metric));
          });
          rows.append(row);
        });
      }

      function update() {
        hideTooltip();
        distanceOutput.textContent = Number(distanceFilter.value).toFixed(2);
        const components = filteredComponents();
        resultCount.textContent = t('resultCount', { shown: components.length, total: evaluable.length });
        renderPoints(components);
        renderRows(components);
        if (selected && !components.some((component) => component.name === selected.name)) clearSelection();
      }

      search.addEventListener('input', update);
      zoneFilter.addEventListener('change', update);
      distanceFilter.addEventListener('input', update);
      language.addEventListener('change', () => {
        locale = language.value;
        applyLanguage();
      });
      document.getElementById('reset').addEventListener('click', () => {
        search.value = '';
        zoneFilter.value = 'all';
        distanceFilter.value = '0';
        update();
        search.focus();
      });
      chart.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') clearSelection();
      });

      applyLanguage();
    })();
  </script>
</body>
</html>
HTML);
    }

    /**
     * @return array{
     *     summary: array{componentCount: int, meanDistance: float|null, cycleGroupCount: int},
     *     components: list<array{
     *         name: string,
     *         classCount: int,
     *         metricsEvaluable: bool,
     *         ca: int|null,
     *         ce: int|null,
     *         instability: float|null,
     *         abstractness: float,
     *         distance: float|null,
     *         zone: 'pain'|'useless'|null,
     *         classes: list<array{fqcn: string, kind: string}>
     *     }>,
     *     cycles: list<array{
     *         components: list<string>,
     *         componentCount: int,
     *         namespaceRelation: 'hierarchical'|'peer',
     *         representativePath: list<string>,
     *         omittedComponents: list<string>,
     *         dependencies: list<array{
     *             from: string,
     *             to: string,
     *             classDependencies: list<array{
     *                 from: string,
     *                 to: string,
     *                 evidence: list<array{kind: string, file: string, line: int}>
     *             }>
     *         }>
     *     }>
     * }
     */
    private function payload(ReportData $data): array
    {
        return [
            'summary' => [
                'componentCount' => count($data->componentMetrics),
                'meanDistance' => $data->summary->meanDistance === null ? null : round($data->summary->meanDistance, 4),
                'cycleGroupCount' => count($data->cycles),
            ],
            'components' => array_map($this->componentPayload(...), $data->componentMetrics),
            'cycles' => $data->cycleGroups(),
        ];
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
     *     zone: 'pain'|'useless'|null,
     *     classes: list<array{fqcn: string, kind: string}>
     * }
     */
    private function componentPayload(ComponentMetrics $metrics): array
    {
        $evaluable = $metrics->dependencyMetricsEvaluable;

        return [
            'name' => $metrics->component->name,
            'classCount' => count($metrics->component->classInfos),
            'metricsEvaluable' => $evaluable,
            'ca' => $evaluable ? $metrics->ca : null,
            'ce' => $evaluable ? $metrics->ce : null,
            'instability' => $evaluable ? round($metrics->instability, 4) : null,
            'abstractness' => round($metrics->abstractness, 4),
            'distance' => $evaluable ? round($metrics->distance, 4) : null,
            'zone' => match ($metrics->zone) {
                Zone::Pain => 'pain',
                Zone::Useless => 'useless',
                Zone::None => null,
            },
            'classes' => array_map(
                static fn ($classInfo): array => [
                    'fqcn' => $classInfo->fqcn,
                    'kind' => $classInfo->kind->label(),
                ],
                $metrics->component->classInfos,
            ),
        ];
    }
}

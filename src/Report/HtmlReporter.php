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
      grid-template-columns: repeat(3, minmax(88px, 1fr));
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
    .metric-grid div { padding: 10px; border-right: 1px solid var(--grid); border-bottom: 1px solid var(--grid); }
    .metric-grid dt { color: var(--muted); font-size: .66rem; text-transform: uppercase; }
    .metric-grid dd { margin: 2px 0 0; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-weight: 700; }
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
      .summary { width: 100%; }
      .toolbar { grid-template-columns: 1fr 1fr; }
      .inspector { border-top: 1px solid var(--grid); border-left: 0; }
    }

    @media (max-width: 580px) {
      .shell { width: min(100% - 20px, 1500px); padding-top: 24px; }
      .toolbar { grid-template-columns: 1fr; }
      .summary div { padding: 10px; }
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
        <p class="eyebrow">psap / architecture inspection board</p>
        <h1>Instability meets abstraction.</h1>
        <p class="dek">Each point is a namespace component. Hover for its SAP metrics; select it to inspect the classes gathered beneath that point.</p>
      </div>
      <dl class="summary" aria-label="Analysis summary">
        <div><dt>Components</dt><dd id="summary-components">—</dd></div>
        <div><dt>Plotted</dt><dd id="summary-plotted">—</dd></div>
        <div><dt>Mean D</dt><dd id="summary-distance">—</dd></div>
      </dl>
    </header>

    <section class="toolbar" aria-label="Graph filters">
      <div class="field">
        <label for="search">Find a component or class</label>
        <input id="search" type="search" placeholder="e.g. Domain or UserRepository" autocomplete="off">
      </div>
      <div class="field">
        <label for="zone-filter">Zone</label>
        <select id="zone-filter">
          <option value="all">All zones</option>
          <option value="none">Main sequence</option>
          <option value="pain">Pain zone</option>
          <option value="useless">Useless zone</option>
        </select>
      </div>
      <div class="field">
        <label for="distance-filter">Minimum distance (D)</label>
        <div class="range-line">
          <input id="distance-filter" type="range" min="0" max="1" step="0.01" value="0">
          <output id="distance-output" for="distance-filter">0.00</output>
        </div>
      </div>
      <button id="reset" class="reset" type="button">Reset filters</button>
    </section>

    <section class="workspace">
      <div class="plot-panel">
        <div class="plot-meta">
          <strong id="result-count" aria-live="polite">Loading components…</strong>
          <span>Keyboard: Tab to a point, Enter to select, Esc to clear.</span>
        </div>
        <div class="plot-wrap">
          <svg id="ia-chart" viewBox="0 0 640 620" role="group" aria-labelledby="chart-title chart-description">
            <title id="chart-title">SAP instability and abstractness graph</title>
            <desc id="chart-description">Instability runs from zero to one on the horizontal axis. Abstractness runs from zero to one on the vertical axis. Select a point to inspect its component classes.</desc>
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
              <text class="chart-copy zone-copy" x="92" y="545">Pain zone</text>
              <text class="chart-copy zone-copy" x="588" y="56" text-anchor="end">Useless zone</text>
              <text class="chart-copy" x="350" y="286" transform="rotate(45 350 286)">Main sequence · A + I = 1</text>
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
              <text class="chart-copy" x="340" y="613" text-anchor="middle">Instability (I)</text>
              <text class="chart-copy" x="15" y="300" text-anchor="middle" transform="rotate(-90 15 300)">Abstractness (A)</text>
            </g>
            <g id="projection-layer" aria-hidden="true"></g>
            <g id="point-layer"></g>
          </svg>
          <div id="tooltip" class="tooltip" role="tooltip" hidden><strong></strong><span></span></div>
        </div>
        <ul class="legend" aria-label="Point legend">
          <li><i></i>Main sequence</li>
          <li><i class="pain"></i>Pain zone</li>
          <li><i class="useless"></i>Useless zone</li>
        </ul>
        <p class="representation-note">Zone display: this HTML report draws the radius-based boundaries used by psap. Mermaid quadrant charts show the same zone concepts as quadrant labels, so their shapes are an approximation. Point metrics and coordinates come from the same analysis.</p>
      </div>

      <aside id="inspector" class="inspector" aria-live="polite" aria-label="Selected component">
        <p class="eyebrow">Selected component</p>
        <h2>No component selected</h2>
        <p class="inspector-empty">Choose a point or a row below. Its SAP metrics and contained classes will stay pinned here.</p>
      </aside>
    </section>

    <section class="table-panel" aria-label="Component data">
      <table>
        <caption>Components matching the current filters</caption>
        <thead><tr><th scope="col">Component</th><th scope="col">Types</th><th scope="col">Ca</th><th scope="col">Ce</th><th scope="col">I</th><th scope="col">A</th><th scope="col">D</th><th scope="col">Zone</th></tr></thead>
        <tbody id="component-rows"></tbody>
      </table>
    </section>
  </main>

  <script id="psap-data" type="application/json">__PSAP_DATA__</script>
  <script>
    (() => {
      'use strict';

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
      const plot = { left: 70, top: 30, size: 540 };
      let selected = null;

      const evaluable = report.components.filter((component) => component.metricsEvaluable);
      document.getElementById('summary-components').textContent = String(report.summary.componentCount);
      document.getElementById('summary-plotted').textContent = String(evaluable.length);
      document.getElementById('summary-distance').textContent = report.summary.meanDistance === null ? 'N/A' : report.summary.meanDistance.toFixed(2);

      function svgElement(name, attributes = {}) {
        const element = document.createElementNS(SVG_NS, name);
        Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
        return element;
      }

      function value(value) {
        return value === null ? 'N/A' : Number(value).toFixed(2);
      }

      function zoneName(zone) {
        if (zone === 'pain') return 'Pain';
        if (zone === 'useless') return 'Useless';
        return 'Main sequence';
      }

      function matches(component) {
        const query = search.value.trim().toLocaleLowerCase();
        const zone = zoneFilter.value;
        const minimumDistance = Number(distanceFilter.value);
        const haystack = [component.name, ...component.classes.map((item) => `${item.fqcn} ${item.kind}`)].join(' ').toLocaleLowerCase();
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
            ? `${component.name}. I ${value(component.instability)}, A ${value(component.abstractness)}, D ${value(component.distance)}`
            : `${group.length} components at I ${value(component.instability)}, A ${value(component.abstractness)}`,
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
        tooltip.querySelector('strong').textContent = group.length === 1 ? component.name : `${component.name} + ${group.length - 1} at same coordinates`;
        tooltip.querySelector('span').textContent = `I ${value(component.instability)} · A ${value(component.abstractness)} · D ${value(component.distance)} · Ca ${component.ca} · Ce ${component.ce} · ${component.classCount} types`;
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

      function renderInspector(component, coordinateGroup = [component]) {
        inspector.replaceChildren();
        appendTextElement(inspector, 'p', 'Selected component', 'eyebrow');
        appendTextElement(inspector, 'h2', component.name);
        appendTextElement(inspector, 'p', `${component.classCount} types · ${zoneName(component.zone)}`);

        const metrics = document.createElement('dl');
        metrics.className = 'metric-grid';
        [['I', component.instability], ['A', component.abstractness], ['D', component.distance], ['Ca', component.ca], ['Ce', component.ce], ['Types', component.classCount]].forEach(([label, metric]) => {
          const wrapper = document.createElement('div');
          appendTextElement(wrapper, 'dt', label);
          appendTextElement(wrapper, 'dd', typeof metric === 'number' && label !== 'Ca' && label !== 'Ce' && label !== 'Types' ? value(metric) : String(metric));
          metrics.append(wrapper);
        });
        inspector.append(metrics);

        if (coordinateGroup.length > 1) {
          appendTextElement(inspector, 'h3', `${coordinateGroup.length} components share this point`, 'class-heading');
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

        appendTextElement(inspector, 'h3', 'Contained classes', 'class-heading');
        const list = document.createElement('ul');
        list.className = 'class-list';
        if (component.classes.length === 0) {
          appendTextElement(list, 'li', 'No class declarations were recorded.');
        } else {
          component.classes.forEach((item) => {
            const entry = document.createElement('li');
            appendTextElement(entry, 'code', item.fqcn);
            appendTextElement(entry, 'span', item.kind, 'kind');
            list.append(entry);
          });
        }
        inspector.append(list);
      }

      function selectComponent(component, coordinateGroup = [component]) {
        selected = component;
        renderInspector(component, coordinateGroup);
        renderProjection(component);
        renderPoints(filteredComponents());
      }

      function clearSelection() {
        selected = null;
        projectionLayer.replaceChildren();
        inspector.replaceChildren();
        appendTextElement(inspector, 'p', 'Selected component', 'eyebrow');
        appendTextElement(inspector, 'h2', 'No component selected');
        appendTextElement(inspector, 'p', 'Choose a point or a row below. Its SAP metrics and contained classes will stay pinned here.', 'inspector-empty');
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
          cell.textContent = 'No components match these filters. Reset filters to see the full report.';
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
        resultCount.textContent = `${components.length} of ${evaluable.length} plotted components shown`;
        renderPoints(components);
        renderRows(components);
        if (selected && !components.some((component) => component.name === selected.name)) clearSelection();
      }

      search.addEventListener('input', update);
      zoneFilter.addEventListener('change', update);
      distanceFilter.addEventListener('input', update);
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

      update();
    })();
  </script>
</body>
</html>
HTML);
    }

    /**
     * @return array{
     *     summary: array{componentCount: int, meanDistance: float|null},
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
     *     }>
     * }
     */
    private function payload(ReportData $data): array
    {
        return [
            'summary' => [
                'componentCount' => count($data->componentMetrics),
                'meanDistance' => $data->summary->meanDistance === null ? null : round($data->summary->meanDistance, 4),
            ],
            'components' => array_map($this->componentPayload(...), $data->componentMetrics),
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

<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Report;

use JsonException;
use PHPUnit\Framework\TestCase;
use Psap\Analyzer\ClassInfo;
use Psap\Analyzer\TypeKind;
use Psap\Component\Component;
use Psap\Component\DependencyGraph;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\MetricsSummary;
use Psap\Metrics\Zone;
use Psap\Report\HtmlReporter;
use Psap\Report\PortalReporter;
use Psap\Report\ReportData;

/**
 * @phpstan-type PortalPayload array{
 *     summary: array{componentCount: int, plottedCount: int, meanDistance: float|null, cycleGroupCount: int},
 *     flowchart: array{edgeCount: int, maxEdges: int, renderable: bool}
 * }
 */
final class PortalReporterTest extends TestCase
{
    public function testRendersSelfContainedPortalDocument(): void
    {
        $data = $this->simpleData();

        $output = (new PortalReporter())->render($data);

        self::assertStringStartsWith('<!doctype html>', $output);
        self::assertStringContainsString('<title>psap — Architecture portal</title>', $output);
        self::assertStringContainsString('Content-Security-Policy', $output);
        // タブ / 各セクションが揃っている
        self::assertStringContainsString('id="panel-overview"', $output);
        self::assertStringContainsString('id="panel-interactive"', $output);
        self::assertStringContainsString('id="panel-diagrams"', $output);
        self::assertStringContainsString('id="panel-cycles"', $output);
        self::assertStringContainsString('id="panel-sources"', $output);
        // 日本語切替の辞書が同梱されている
        self::assertStringContainsString('<option value="ja">日本語</option>', $output);
        self::assertStringContainsString("tabDiagrams: '図'", $output);
        // mermaid 本体がインラインされ、strict で初期化される
        self::assertStringContainsString('globalThis["mermaid"]', $output);
        self::assertStringContainsString("securityLevel: 'strict'", $output);
        // ライセンス表記
        self::assertStringContainsString('Bundled Mermaid v11.16.0 is distributed under the MIT License', $output);
    }

    public function testAllPlaceholdersAreSubstituted(): void
    {
        $output = (new PortalReporter())->render($this->simpleData());

        self::assertStringNotContainsString('__PSAP_', $output);
    }

    /**
     * 出力に外部 URL 参照が含まれないことを検証する。
     *
     * 除外方法: 同梱した mermaid.min.js の <script> ブロック（ライブラリ本体および
     * その中のライセンス文字列 URL を含む）と、HTML コメント（mermaid ライセンス表記）を
     * 取り除いた「PortalReporter が生成するテンプレート部分」だけを検査対象にする。
     */
    public function testDoesNotReferenceExternalOrigins(): void
    {
        $output = (new PortalReporter())->render($this->simpleData());

        $inspectable = $this->withoutBundledMermaidAndComments($output);

        self::assertStringNotContainsString('http://', $inspectable);
        self::assertStringNotContainsString('https://', $inspectable);
    }

    public function testIframeSrcdocIsReversibleToHtmlReporterOutput(): void
    {
        $data = $this->simpleData();

        $output = (new PortalReporter())->render($data);

        $matched = preg_match('/srcdoc="(.*?)"/s', $output, $matches);
        self::assertSame(1, $matched, 'iframe srcdoc attribute was not found.');

        $decoded = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        self::assertSame((new HtmlReporter())->render($data), $decoded);
    }

    /**
     * @throws JsonException
     */
    public function testEmbedsFlowchartMetadataAndAllowsRenderingWhenSmall(): void
    {
        $payload = $this->payload((new PortalReporter())->render($this->simpleData()));

        self::assertTrue($payload['flowchart']['renderable']);
        self::assertSame(500, $payload['flowchart']['maxEdges']);
        self::assertSame(1, $payload['summary']['cycleGroupCount']);
        self::assertSame(2, $payload['summary']['componentCount']);
    }

    /**
     * @throws JsonException
     */
    public function testFlagsFlowchartAsNotRenderableWhenEdgesExceedThreshold(): void
    {
        $metrics = [
            $this->metrics('App\\A', 0.5, 0.0, 0.5),
            $this->metrics('App\\B', 0.5, 0.0, 0.5),
        ];
        // 501 本のエッジを持つグラフ（重複を許して DependencyGraph に直接渡す）。
        $graph = new DependencyGraph(
            ['App\\A', 'App\\B'],
            array_fill(0, 501, ['App\\A', 'App\\B']),
        );
        $data = new ReportData($metrics, MetricsSummary::from($metrics), [], [], $graph);

        $payload = $this->payload((new PortalReporter())->render($data));

        self::assertFalse($payload['flowchart']['renderable']);
        self::assertSame(501, $payload['flowchart']['edgeCount']);
    }

    public function testOverviewListsWorstDistanceComponentAndCoverage(): void
    {
        $data = $this->simpleData();

        $output = (new PortalReporter())->render($data);

        // Overview の D 値ワースト表にコンポーネント名と D 値が出る
        self::assertStringContainsString('App\\Infra', $output);
        self::assertStringContainsString('data-i18n="worstDistanceHeading"', $output);
    }

    public function testCyclesSectionRendersRepresentativePathAndEvidence(): void
    {
        $data = $this->simpleData();

        $output = (new PortalReporter())->render($data);

        self::assertStringContainsString('data-i18n="representativePath"', $output);
        self::assertStringContainsString('<code>App\\Domain</code>', $output);
        self::assertStringContainsString('parameter_type', $output);
        self::assertStringContainsString('src/Domain/Order.php:12', $output);
    }

    public function testCyclesSectionShowsAllClearWhenNoCycles(): void
    {
        $metrics = [$this->metrics('App\\Domain', 0.2, 0.75, 0.05)];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PortalReporter())->render($data);

        self::assertStringContainsString('data-i18n="noCyclesFound"', $output);
    }

    public function testEscapesDynamicValuesInServerRenderedSections(): void
    {
        $attack = 'App\\<script>Evil';
        $metrics = [$this->metrics($attack, 0.9, 0.1, 0.8)];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new PortalReporter())->render($data);

        self::assertStringNotContainsString('<script>Evil', $output);
        self::assertStringContainsString('App\\&lt;script&gt;Evil', $output);
    }

    /**
     * 外部 URL 検査の対象を「PortalReporter 自身が生成するテンプレート部分」に限定するため、
     * 次を取り除く:
     *   1. 同梱 mermaid.min.js の <script> ブロック（本体 3.5MB とその中のライセンス URL）
     *   2. iframe srcdoc に埋め込んだ HtmlReporter 出力（HtmlReporter 側に独自の外部URL検査が
     *      あり、かつ SVG 名前空間 URI http://www.w3.org/2000/svg のような非オリジン文字列を含む）
     *   3. mermaid ライセンスの HTML コメント
     * mermaid 本体は巨大で `.*?` の正規表現が PCRE のバックトラック上限に達するため、
     * strpos による切り出しで除去する。
     */
    private function withoutBundledMermaidAndComments(string $output): string
    {
        $inspectable = $this->removeBetween(
            $output,
            '<script>"use strict";var __esbuild_esm_mermaid_nm',
            '</script>',
        );
        $inspectable = $this->removeBetween($inspectable, 'srcdoc="', '"');

        $inspectable = preg_replace('/<!--.*?-->/s', '', $inspectable);
        self::assertIsString($inspectable);

        return $inspectable;
    }

    private function removeBetween(string $subject, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($subject, $startNeedle);
        self::assertNotFalse($start, sprintf('Marker "%s" was not found.', $startNeedle));
        $endMarker = strpos($subject, $endNeedle, $start + strlen($startNeedle));
        self::assertNotFalse($endMarker);

        return substr($subject, 0, $start) . substr($subject, $endMarker + strlen($endNeedle));
    }

    private function simpleData(): ReportData
    {
        $orderClass = new ClassInfo('App\\Domain\\Order', TypeKind::ConcreteClass, '/p/Order.php', []);
        $repoClass = new ClassInfo('App\\Infra\\Repository', TypeKind::ConcreteClass, '/p/Repository.php', []);
        $metrics = [
            $this->metrics('App\\Domain', 0.5, 0.0, 0.5, classInfos: [$orderClass]),
            $this->metrics('App\\Infra', 0.5, 0.0, 0.5, classInfos: [$repoClass]),
        ];
        $graph = new DependencyGraph(
            ['App\\Domain', 'App\\Infra'],
            [['App\\Domain', 'App\\Infra'], ['App\\Infra', 'App\\Domain']],
            [
                [
                    'from' => 'App\\Domain',
                    'to' => 'App\\Infra',
                    'classDependencies' => [[
                        'from' => 'App\\Domain\\Order',
                        'to' => 'App\\Infra\\Repository',
                        'evidence' => [[
                            'kind' => 'parameter_type',
                            'file' => 'src/Domain/Order.php',
                            'line' => 12,
                        ]],
                    ]],
                ],
                [
                    'from' => 'App\\Infra',
                    'to' => 'App\\Domain',
                    'classDependencies' => [[
                        'from' => 'App\\Infra\\Repository',
                        'to' => 'App\\Domain\\Order',
                        'evidence' => [],
                    ]],
                ],
            ],
        );

        return new ReportData(
            $metrics,
            MetricsSummary::from($metrics),
            [],
            [['App\\Domain', 'App\\Infra']],
            $graph,
        );
    }

    /**
     * @return PortalPayload
     * @throws JsonException
     */
    private function payload(string $output): array
    {
        $matched = preg_match(
            '/<script id="psap-portal-data" type="application\/json">(.*?)<\/script>/s',
            $output,
            $matches,
        );
        if ($matched !== 1) {
            self::fail('Embedded psap portal JSON payload was not found.');
        }

        /** @var PortalPayload $payload */
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @param list<ClassInfo> $classInfos
     */
    private function metrics(
        string $name,
        float $instability,
        float $abstractness,
        float $distance,
        int $ca = 0,
        int $ce = 0,
        array $classInfos = [],
        bool $evaluable = true,
    ): ComponentMetrics {
        return new ComponentMetrics(
            component: new Component($name, $classInfos),
            ca: $ca,
            ce: $ce,
            instability: $instability,
            abstractness: $abstractness,
            distance: $distance,
            zone: Zone::determine($instability, $abstractness),
            dependencyMetricsEvaluable: $evaluable,
        );
    }
}

<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Psap\Analyzer\DependencyKind;
use Psap\Component\OutOfScopeDependencyComponent;
use Psap\Component\OutOfScopeDependencyEvidence;
use Psap\Component\OutOfScopeDependencyGroup;
use Psap\Component\OutOfScopeDependencyReport;
use Psap\Metrics\MetricsSummary;
use Psap\Report\JsonReporter;
use Psap\Report\ReportData;

/**
 * JSON レポートの解析対象外依存を「バイト単位」で固定するテスト。
 *
 * 解析対象外依存のシリアライズは JsonReporter 専用の private メソッドから
 * 値オブジェクトの `jsonSerialize()` へ移した。デコード後の構造検査
 * （JsonReporterTest）ではキー順・インデント・空配列の表現（`[]` か `{}` か）まで
 * は保証できないため、ここでは出力文字列そのものを固定して退行を防ぐ。
 *
 * 期待値は `tests/Fixtures/Report/out-of-scope-dependencies-report.json`。
 * 入力は `tests/Fixtures/OutOfScopeProject` の解析結果に相当する形
 * （複数コンポーネント・`(global)` グループ・証拠あり）にしてある。
 */
final class JsonReporterOutOfScopeSerializationTest extends TestCase
{
    private const string EXPECTED_JSON_PATH = __DIR__ . '/../../Fixtures/Report/out-of-scope-dependencies-report.json';

    public function testRendersOutOfScopeDependenciesByteForByte(): void
    {
        $data = new ReportData(
            [],
            MetricsSummary::from([]),
            [],
            outOfScopeDependencies: $this->representativeReport(),
        );

        $output = (new JsonReporter())->render($data);

        // JsonReporter は末尾に改行を付けない。fixture はテキストファイルとして
        // 末尾改行を持つため、比較時に1つだけ足す。
        self::assertSame($this->expectedJson(), $output . "\n");
    }

    public function testRendersEmptyOutOfScopeDependenciesByteForByte(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $output = (new JsonReporter())->render($data);

        // 0 件でもキーを落とさず、`groups` / `components` は `{}` ではなく `[]` で出す
        self::assertStringContainsString(
            "    \"outOfScopeDependencies\": {\n"
            . "        \"dependencyCount\": 0,\n"
            . "        \"groupCount\": 0,\n"
            . "        \"groups\": [],\n"
            . "        \"components\": []\n"
            . "    },\n",
            $output,
        );
    }

    private function expectedJson(): string
    {
        $expected = file_get_contents(self::EXPECTED_JSON_PATH);
        self::assertIsString($expected, 'JSON 期待値の fixture を読み込めませんでした。');

        return $expected;
    }

    private function representativeReport(): OutOfScopeDependencyReport
    {
        return new OutOfScopeDependencyReport(
            groups: [
                new OutOfScopeDependencyGroup(
                    'Fixture\\Vendor\\Support\\Facades',
                    ['Fixture\\Vendor\\Support\\Facades\\Cache', 'Fixture\\Vendor\\Support\\Facades\\DB'],
                    ['Fixture\\OutOfScope\\Domain\\Report', 'Fixture\\OutOfScope\\Http\\ReportController'],
                    [],
                ),
                new OutOfScopeDependencyGroup(
                    '(global)',
                    ['GlobalFacade'],
                    ['Fixture\\OutOfScope\\Http\\ReportController'],
                    [],
                ),
                new OutOfScopeDependencyGroup(
                    'Fixture\\Vendor\\Log',
                    ['Fixture\\Vendor\\Log\\Logger'],
                    ['Fixture\\OutOfScope\\Http\\ReportController'],
                    [],
                ),
            ],
            components: [
                new OutOfScopeDependencyComponent('Fixture\\OutOfScope\\Http', [
                    new OutOfScopeDependencyGroup(
                        'Fixture\\Vendor\\Support\\Facades',
                        ['Fixture\\Vendor\\Support\\Facades\\Cache', 'Fixture\\Vendor\\Support\\Facades\\DB'],
                        ['Fixture\\OutOfScope\\Http\\ReportController'],
                        [
                            new OutOfScopeDependencyEvidence(
                                'Fixture\\OutOfScope\\Http\\ReportController',
                                'Fixture\\Vendor\\Support\\Facades\\Cache',
                                DependencyKind::StaticCall,
                                'Http/ReportController.php',
                                24,
                            ),
                            new OutOfScopeDependencyEvidence(
                                'Fixture\\OutOfScope\\Http\\ReportController',
                                'Fixture\\Vendor\\Support\\Facades\\DB',
                                DependencyKind::StaticCall,
                                'Http/ReportController.php',
                                18,
                            ),
                        ],
                    ),
                    new OutOfScopeDependencyGroup(
                        '(global)',
                        ['GlobalFacade'],
                        ['Fixture\\OutOfScope\\Http\\ReportController'],
                        [
                            new OutOfScopeDependencyEvidence(
                                'Fixture\\OutOfScope\\Http\\ReportController',
                                'GlobalFacade',
                                DependencyKind::StaticCall,
                                'Http/ReportController.php',
                                30,
                            ),
                        ],
                    ),
                    new OutOfScopeDependencyGroup(
                        'Fixture\\Vendor\\Log',
                        ['Fixture\\Vendor\\Log\\Logger'],
                        ['Fixture\\OutOfScope\\Http\\ReportController'],
                        [
                            new OutOfScopeDependencyEvidence(
                                'Fixture\\OutOfScope\\Http\\ReportController',
                                'Fixture\\Vendor\\Log\\Logger',
                                DependencyKind::ParameterType,
                                'Http/ReportController.php',
                                12,
                            ),
                        ],
                    ),
                ]),
                new OutOfScopeDependencyComponent('Fixture\\OutOfScope\\Domain', [
                    new OutOfScopeDependencyGroup(
                        'Fixture\\Vendor\\Support\\Facades',
                        ['Fixture\\Vendor\\Support\\Facades\\DB'],
                        ['Fixture\\OutOfScope\\Domain\\Report'],
                        [
                            new OutOfScopeDependencyEvidence(
                                'Fixture\\OutOfScope\\Domain\\Report',
                                'Fixture\\Vendor\\Support\\Facades\\DB',
                                DependencyKind::StaticCall,
                                'Domain/Report.php',
                                21,
                            ),
                        ],
                    ),
                ]),
            ],
        );
    }
}

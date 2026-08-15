<?php

declare(strict_types=1);

namespace Psap\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Psap\Console\AnalyzeCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * AnalyzeCommandのFeatureテスト。
 *
 * @phpstan-type Evidence array{kind: string, file: string, line: int}
 * @phpstan-type ClassDependency array{from: string, to: string, evidence: list<Evidence>}
 * @phpstan-type Dependency array{from: string, to: string, classDependencies: list<ClassDependency>}
 * @phpstan-type CycleGroup array{
 *     components: list<string>,
 *     componentCount: int,
 *     namespaceRelation: 'hierarchical'|'peer',
 *     representativePath: list<string>,
 *     omittedComponents: list<string>,
 *     dependencies: list<Dependency>,
 * }
 * @phpstan-type JsonReport array{
 *     summary: array{componentCount: int, namespaceDepth: int|null, metricsEvaluable: bool, meanDistance: float|null, varianceDistance: float|null},
 *     components: list<array{
 *         name: string,
 *         classCount: int,
 *         metricsEvaluable: bool,
 *         ca: int|null,
 *         ce: int|null,
 *         instability: float|null,
 *         abstractness: float,
 *         distance: float|null,
 *         zone: string|null,
 *         classes: list<array{fqcn: string, kind: string}>,
 *     }>,
 *     dependencies: list<Dependency>,
 *     outOfScopeDependencies: array{
 *         dependencyCount: int,
 *         groupCount: int,
 *         groups: list<array{namespace: string, dependencyCount: int, sourceCount: int, targets: list<string>}>,
 *         components: list<array{
 *             name: string,
 *             dependencyCount: int,
 *             groupCount: int,
 *             groups: list<array{
 *                 namespace: string,
 *                 dependencyCount: int,
 *                 sourceCount: int,
 *                 targets: list<string>,
 *                 sources: list<string>,
 *                 evidence: list<array{sourceFqcn: string, targetFqcn: string, kind: string, file: string, line: int}>,
 *             }>,
 *         }>,
 *     },
 *     cycles: list<list<string>>,
 *     cyclePaths: list<array{path: list<string>, dependencies: list<Dependency>}>,
 *     cycleGroups: list<CycleGroup>,
 *     cycleBaselineComparison: array{hasChanges: bool, newCycles: list<list<string>>, resolvedCycles: list<list<string>>}|null,
 *     fileCoverage: array{discovered: int, selected: int, analyzed: int, excluded: int, skipped: int, analysisCoverage: float|int|null}|null,
 *     diagnostics: list<array{
 *         code: string,
 *         severity: string,
 *         file: string|null,
 *         line: int|null,
 *         message: string,
 *         context: array<string, bool|float|int|string|null>,
 *         actions: list<array{code: string, option?: string}>,
 *     }>,
 *     warnings: list<string>,
 * }
 */
final class AnalyzeCommandTest extends TestCase
{
    private const SIMPLE_PROJECT = __DIR__ . '/../Fixtures/SimpleProject';
    private const CYCLIC_PROJECT = __DIR__ . '/../Fixtures/CyclicProject';
    private const DOCBLOCK_ONLY_PROJECT = __DIR__ . '/../Fixtures/DocblockOnlyProject';
    private const BROKEN_PROJECT = __DIR__ . '/../Fixtures/BrokenProject';
    private const FUNCTION_ONLY_PROJECT = __DIR__ . '/../Fixtures/FunctionOnlyProject';
    private const OUT_OF_SCOPE_PROJECT = __DIR__ . '/../Fixtures/OutOfScopeProject';

    public function testTextFormatRendersTableAndExitsSuccessfully(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT]]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('psap - Stable Abstractions Principle metrics', $tester->getDisplay());
        self::assertStringContainsString('Statistics: mean(D)=', $tester->getDisplay());
    }

    public function testJsonFormatRendersValidJson(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'json']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $decoded = $this->decodeJson($tester->getDisplay());
        self::assertArrayHasKey('summary', $decoded);
        self::assertArrayHasKey('components', $decoded);
        self::assertArrayHasKey('dependencies', $decoded);
        self::assertArrayHasKey('fileCoverage', $decoded);
        self::assertArrayHasKey('diagnostics', $decoded);
        self::assertArrayHasKey('warnings', $decoded);
        self::assertGreaterThan(0, $decoded['summary']['componentCount']);
    }

    public function testJsonFormatReportsCompleteFileCoverage(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'json']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $coverage = $this->decodeJson($tester->getDisplay())['fileCoverage'];
        self::assertNotNull($coverage);
        self::assertSame(12, $coverage['discovered']);
        self::assertSame(12, $coverage['selected']);
        self::assertSame(12, $coverage['analyzed']);
        self::assertSame(0, $coverage['excluded']);
        self::assertSame(0, $coverage['skipped']);
        self::assertEquals(1.0, $coverage['analysisCoverage']);
    }

    public function testMarkdownFormatRendersPromptReadyReport(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::CYCLIC_PROJECT], '--depth' => '3', '--format' => 'markdown']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('# psap Architecture Analysis', $display);
        self::assertStringContainsString('## Review Priorities', $display);
        self::assertStringContainsString('## Circular Dependencies', $display);
        self::assertStringContainsString('`parameter_type` at `A/Foo.php:', $display);
        self::assertStringContainsString('- Source paths `' . self::CYCLIC_PROJECT . '`', $display);
    }

    public function testMermaidFormatRendersQuadrantChart(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'mermaid']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('quadrantChart', $display);
        self::assertStringContainsString('quadrant-1 Useless Zone', $display);
        self::assertStringContainsString('quadrant-3 Pain Zone', $display);
        self::assertStringContainsString('"Fixture\\App\\Domain (D=', $display);
    }

    public function testHtmlFormatRendersInteractiveGraph(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'html']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringStartsWith('<!doctype html>', $display);
        self::assertStringContainsString('id="ia-chart"', $display);
        self::assertStringContainsString('Fixture\\\\App\\\\Domain', $display);
    }

    public function testPortalFormatRendersRawHtmlToStdout(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'portal']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringStartsWith('<!doctype html>', $display);
        self::assertStringContainsString('Content-Security-Policy', $display);
        self::assertStringContainsString('id="panel-diagrams"', $display);
        self::assertStringContainsString('srcdoc="', $display);
        // 図ソースにフィクスチャのコンポーネント名（HTML エスケープ済み）が現れる
        self::assertStringContainsString('Fixture\\\\App\\\\Domain', $display);
    }

    public function testPortalFormatWritesSelfContainedFile(): void
    {
        $outputPath = sys_get_temp_dir() . '/psap-portal-' . uniqid() . '.html';

        try {
            $tester = $this->commandTester();
            $exitCode = $tester->execute([
                'paths' => [self::CYCLIC_PROJECT],
                '--depth' => '3',
                '--format' => 'portal',
                '--output' => $outputPath,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileExists($outputPath);
            $report = (string) file_get_contents($outputPath);
            self::assertStringStartsWith('<!doctype html>', $report);
            self::assertStringContainsString('globalThis["mermaid"]', $report);
            self::assertStringContainsString('id="panel-cycles"', $report);
            self::assertStringNotContainsString('__PSAP_', $report);
            // 自己完結の担保: mermaid 本体（3.5MB）と iframe srcdoc を strpos で切り出して
            // 除いた「PortalReporter が生成する部分」に外部 URL 参照がないことを確認する。
            // `.*?` の正規表現は PCRE のバックトラック上限に達するため使わない。
            $inspectable = $this->removeBetween($report, '<script>"use strict";var __esbuild_esm_mermaid_nm', '</script>');
            $inspectable = $this->removeBetween($inspectable, 'srcdoc="', '"');
            self::assertStringNotContainsString('https://', $inspectable);
            self::assertStringNotContainsString('http://', $inspectable);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testStructuredFormatsReportInvalidUtf8FileAndContinue(): void
    {
        $directory = sys_get_temp_dir() . '/psap-invalid-utf8-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/Valid.php', "<?php\nnamespace Fixture\\Encoding;\nclass Valid {}\n");
        file_put_contents($directory . '/Latin1.php', "<?php\nnamespace Fixture\\Encoding;\nclass " . chr(0xA9) . " {}\n");

        try {
            foreach (['html', 'json'] as $format) {
                $outputPath = sprintf('%s/report.%s', $directory, $format);
                $tester = $this->commandTester();
                $exitCode = $tester->execute(
                    ['paths' => [$directory], '--format' => $format, '--output' => $outputPath],
                    ['capture_stderr_separately' => true],
                );

                self::assertSame(Command::SUCCESS, $exitCode);
                self::assertFileExists($outputPath);
                $report = (string) file_get_contents($outputPath);
                if ($format === 'html') {
                    self::assertStringStartsWith('<!doctype html>', $report);
                    self::assertStringContainsString('source.invalid_utf8', $report);
                    self::assertStringContainsString('Latin1.php', $report);
                } else {
                    $decoded = $this->decodeJson($report);
                    self::assertStringContainsString('Latin1.php:3', implode("\n", $decoded['warnings']));
                    self::assertSame('source.invalid_utf8', $decoded['diagnostics'][0]['code']);
                    self::assertSame('warning', $decoded['diagnostics'][0]['severity']);
                    self::assertSame('Latin1.php', $decoded['diagnostics'][0]['file']);
                    self::assertSame(3, $decoded['diagnostics'][0]['line']);
                    self::assertContains(['code' => 'exclude_file', 'option' => '--exclude'], $decoded['diagnostics'][0]['actions']);
                    self::assertStringContainsString('"context": {}', $report);
                    self::assertSame([
                        'discovered' => 2,
                        'selected' => 2,
                        'analyzed' => 1,
                        'excluded' => 0,
                        'skipped' => 1,
                        'analysisCoverage' => 0.5,
                    ], $decoded['fileCoverage']);
                }
                self::assertStringContainsString('source.invalid_utf8', $tester->getErrorOutput());
                self::assertStringContainsString('Latin1.php:3', $tester->getErrorOutput());
                self::assertStringContainsString('UTF-8', $tester->getErrorOutput());
                self::assertStringContainsString('--exclude', $tester->getErrorOutput());
                @unlink($outputPath);
            }
        } finally {
            @unlink($directory . '/report.html');
            @unlink($directory . '/report.json');
            @unlink($directory . '/Valid.php');
            @unlink($directory . '/Latin1.php');
            @rmdir($directory);
        }
    }

    public function testPlantUmlFormatRendersDependencyGraph(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'plantuml']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('@startuml', $display);
        self::assertStringContainsString('@enduml', $display);
        self::assertStringContainsString('rectangle "Fixture\\\\App\\\\Domain\\n', $display);
        self::assertStringContainsString('legend right', $display);
    }

    public function testUnknownFormatExitsWithInputErrorCode(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'yaml'], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('未知の出力形式です', $tester->getErrorOutput());
    }

    public function testNonExistentPathExitsWithInputErrorCode(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['paths' => [self::SIMPLE_PROJECT . '/DoesNotExist']], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('指定されたパスが存在しません', $tester->getErrorOutput());
    }

    public function testThresholdExceededExitsWithFailureCode(): void
    {
        $tester = $this->commandTester();

        // depth=3 にすると Domain / Infra が分かれ、D 値に差が出る。
        // 極端に低い閾値（0.0 以上はほぼ必ず超える）で確実に超過させる。
        $tester->execute(
            ['paths' => [self::SIMPLE_PROJECT], '--depth' => '3', '--threshold' => '0.0'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('D 値が閾値', $tester->getErrorOutput());
    }

    public function testThresholdNotExceededExitsSuccessfully(): void
    {
        $tester = $this->commandTester();

        // 閾値 1.0 は D の理論上の最大値なので、超過（>）することはない
        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--threshold' => '1.0']);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testInvalidDepthExitsWithInputErrorCode(): void
    {
        $tester = $this->commandTester();
        $tester->execute(
            ['paths' => [self::SIMPLE_PROJECT], '--depth' => 'not-a-number'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--depth', $tester->getErrorOutput());
    }

    public function testAutoDepthUsesTheLevelBelowTheCommonNamespace(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['paths' => [self::DOCBLOCK_ONLY_PROJECT], '--format' => 'json']);

        $decoded = $this->decodeJson($tester->getDisplay());

        self::assertSame(3, $decoded['summary']['namespaceDepth']);
        self::assertCount(2, $decoded['components']);
    }

    public function testInvalidThresholdExitsWithInputErrorCode(): void
    {
        $tester = $this->commandTester();
        $tester->execute(
            ['paths' => [self::SIMPLE_PROJECT], '--threshold' => '1.1'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--threshold', $tester->getErrorOutput());
    }

    public function testFailOnCycleExitsWithFailureCodeWhenCyclesExist(): void
    {
        $tester = $this->commandTester();

        // depth=3 で A/B/C/D/E が分かれる（depth=2 だと Fixture\Cyclic に統合され、
        // コンポーネント内依存として無視されてしまうため循環が消える）
        $tester->execute(
            ['paths' => [self::CYCLIC_PROJECT], '--depth' => '3', '--fail-on-cycle' => true],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('循環依存', $tester->getErrorOutput());
        self::assertStringContainsString('Components: Fixture\\Cyclic\\A, Fixture\\Cyclic\\B', $tester->getErrorOutput());
        self::assertStringContainsString(
            'Representative shortest path: Fixture\\Cyclic\\A -> Fixture\\Cyclic\\B -> Fixture\\Cyclic\\A',
            $tester->getErrorOutput(),
        );
    }

    public function testFailOnCycleExitsSuccessfullyWhenNoCyclesExist(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(
            ['paths' => [self::SIMPLE_PROJECT], '--fail-on-cycle' => true],
        );

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testGeneratedCycleBaselineAllowsExistingCycles(): void
    {
        $baselinePath = $this->temporaryBaselinePath();

        try {
            $generator = $this->commandTester();
            $generateExitCode = $generator->execute([
                'paths' => [self::CYCLIC_PROJECT],
                '--depth' => '3',
                '--generate-cycle-baseline' => $baselinePath,
            ]);

            self::assertSame(Command::SUCCESS, $generateExitCode);
            self::assertFileExists($baselinePath);
            /** @var array{schemaVersion: int, namespaceDepth: int, cycles: list<list<string>>} $baseline */
            $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $baseline['schemaVersion']);
            self::assertSame(3, $baseline['namespaceDepth']);
            self::assertCount(2, $baseline['cycles']);

            $tester = $this->commandTester();
            $exitCode = $tester->execute([
                'paths' => [self::CYCLIC_PROJECT],
                '--depth' => '3',
                '--cycle-baseline' => $baselinePath,
                '--fail-on-cycle' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('New cycles: 0', $tester->getDisplay());
        } finally {
            @unlink($baselinePath);
        }
    }

    public function testCycleBaselineFailsOnlyForNewCycles(): void
    {
        $baselinePath = $this->temporaryBaselinePath();

        try {
            $generator = $this->commandTester();
            $generator->execute([
                'paths' => [self::SIMPLE_PROJECT],
                '--depth' => '3',
                '--generate-cycle-baseline' => $baselinePath,
            ]);

            $tester = $this->commandTester();
            $tester->execute(
                [
                    'paths' => [self::CYCLIC_PROJECT],
                    '--depth' => '3',
                    '--cycle-baseline' => $baselinePath,
                    '--fail-on-cycle' => true,
                ],
                ['capture_stderr_separately' => true],
            );

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('Representative shortest path', $tester->getErrorOutput());
        } finally {
            @unlink($baselinePath);
        }
    }

    public function testCycleBaselineReportsResolvedCyclesInJson(): void
    {
        $baselinePath = $this->temporaryBaselinePath();

        try {
            $generator = $this->commandTester();
            $generator->execute([
                'paths' => [self::CYCLIC_PROJECT],
                '--depth' => '3',
                '--generate-cycle-baseline' => $baselinePath,
            ]);

            $tester = $this->commandTester();
            $tester->execute([
                'paths' => [self::SIMPLE_PROJECT],
                '--depth' => '3',
                '--cycle-baseline' => $baselinePath,
                '--format' => 'json',
            ]);
            $decoded = $this->decodeJson($tester->getDisplay());
            $comparison = $decoded['cycleBaselineComparison'];
            if ($comparison === null) {
                self::fail('循環ベースラインの比較結果がありません。');
            }

            self::assertTrue($comparison['hasChanges']);
            self::assertSame([], $comparison['newCycles']);
            self::assertCount(2, $comparison['resolvedCycles']);
        } finally {
            @unlink($baselinePath);
        }
    }

    public function testCycleBaselineRejectsDifferentDepth(): void
    {
        $baselinePath = $this->temporaryBaselinePath();

        try {
            $generator = $this->commandTester();
            $generator->execute([
                'paths' => [self::CYCLIC_PROJECT],
                '--depth' => '3',
                '--generate-cycle-baseline' => $baselinePath,
            ]);

            $tester = $this->commandTester();
            $tester->execute(
                [
                    'paths' => [self::CYCLIC_PROJECT],
                    '--depth' => '2',
                    '--cycle-baseline' => $baselinePath,
                ],
                ['capture_stderr_separately' => true],
            );

            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertStringContainsString('名前空間深度が一致しません', $tester->getErrorOutput());
        } finally {
            @unlink($baselinePath);
        }
    }

    public function testJsonFormatIncludesCyclesForCyclicFixture(): void
    {
        $tester = $this->commandTester();

        $tester->execute(['paths' => [self::CYCLIC_PROJECT], '--depth' => '3', '--format' => 'json']);

        $decoded = $this->decodeJson($tester->getDisplay());

        self::assertNotEmpty($decoded['cycles']);
        self::assertNotEmpty($decoded['cyclePaths']);
        self::assertNotEmpty($decoded['cycleGroups']);
        self::assertGreaterThanOrEqual(2, $decoded['cycleGroups'][0]['componentCount']);
        self::assertSame('peer', $decoded['cycleGroups'][0]['namespaceRelation']);
        $path = $decoded['cyclePaths'][0]['path'];
        if ($path === []) {
            self::fail('循環経路が空です。');
        }
        self::assertSame($path[0], $path[array_key_last($path)]);
        self::assertNotEmpty($decoded['dependencies']);
        self::assertNotEmpty($decoded['dependencies'][0]['classDependencies']);
        $evidence = $decoded['dependencies'][0]['classDependencies'][0]['evidence'];
        self::assertNotEmpty($evidence);
        self::assertContains($evidence[0]['kind'], ['parameter_type', 'return_type']);
        self::assertStringNotContainsString(self::CYCLIC_PROJECT, $evidence[0]['file']);
        self::assertGreaterThan(0, $evidence[0]['line']);
    }

    public function testWarnsWhenDepthCollapsesDeeperNamespacesIntoOneComponent(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['paths' => [self::DOCBLOCK_ONLY_PROJECT], '--depth' => '1', '--format' => 'json']);

        $decoded = $this->decodeJson($tester->getDisplay());

        self::assertCount(1, $decoded['components']);
        self::assertSame('analysis.single_component_depth', $decoded['diagnostics'][0]['code']);
        self::assertSame('info', $decoded['diagnostics'][0]['severity']);
        self::assertStringContainsString('--depth を増やしてください', $decoded['warnings'][0]);
    }

    public function testWarnsWhenProjectHasOnlyOneIndivisibleComponent(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['paths' => [self::BROKEN_PROJECT], '--format' => 'json']);

        $decoded = $this->decodeJson($tester->getDisplay());

        self::assertCount(1, $decoded['components']);
        self::assertSame('analysis.single_component_unevaluable', $decoded['diagnostics'][1]['code']);
        self::assertSame('info', $decoded['diagnostics'][1]['severity']);
        self::assertStringContainsString(
            'コンポーネント間のCa、Ce、I、D、循環依存は評価できません',
            implode("\n", $decoded['warnings']),
        );
        self::assertFalse($decoded['summary']['metricsEvaluable']);
        self::assertNull($decoded['summary']['meanDistance']);
        self::assertNull($decoded['components'][0]['ca']);
        self::assertNull($decoded['components'][0]['distance']);
    }

    public function testWarnsWhenNoClassLikeDeclarationsAreFound(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['paths' => [self::FUNCTION_ONLY_PROJECT], '--format' => 'json']);

        $decoded = $this->decodeJson($tester->getDisplay());

        self::assertSame(0, $decoded['summary']['componentCount']);
        self::assertSame('analysis.no_types', $decoded['diagnostics'][0]['code']);
        self::assertSame('warning', $decoded['diagnostics'][0]['severity']);
        self::assertFalse($decoded['summary']['metricsEvaluable']);
        self::assertNull($decoded['summary']['meanDistance']);
        self::assertStringContainsString('解析可能なクラス', implode("\n", $decoded['warnings']));
    }

    public function testThresholdIgnoresUnavailableSingleComponentMetrics(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::BROKEN_PROJECT], '--threshold' => '0']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('N/A', $tester->getDisplay());
    }

    public function testOutputOptionWritesToFileInsteadOfStdout(): void
    {
        $outputPath = sys_get_temp_dir() . '/psap-analyze-command-test-' . uniqid() . '.txt';

        try {
            $tester = $this->commandTester();
            $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--output' => $outputPath]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertSame('', $tester->getDisplay());
            self::assertFileExists($outputPath);
            self::assertStringContainsString('psap - Stable Abstractions Principle metrics', (string) file_get_contents($outputPath));
        } finally {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    public function testUnwritableOutputPathExitsWithFailureCode(): void
    {
        $tester = $this->commandTester();
        $outputPath = sys_get_temp_dir() . '/psap-missing-' . uniqid() . '/report.txt';

        $tester->execute(
            ['paths' => [self::SIMPLE_PROJECT], '--output' => $outputPath],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('出力ファイルに書き込めませんでした', $tester->getErrorOutput());
    }

    public function testExcludeOptionRemovesMatchingClassesFromOutput(): void
    {
        $tester = $this->commandTester();

        $tester->execute([
            'paths' => [self::SIMPLE_PROJECT],
            '--format' => 'json',
            '--exclude' => ['Generated/*'],
        ]);

        $decoded = $this->decodeJson($tester->getDisplay());
        $allFqcns = array_merge(...array_map(
            static fn (array $component): array => array_column($component['classes'], 'fqcn'),
            $decoded['components'],
        ));

        self::assertNotContains('Fixture\\App\\Generated\\Ignored', $allFqcns);
        self::assertNotNull($decoded['fileCoverage']);
        self::assertSame(12, $decoded['fileCoverage']['discovered']);
        self::assertSame(11, $decoded['fileCoverage']['selected']);
        self::assertSame(11, $decoded['fileCoverage']['analyzed']);
        self::assertSame(1, $decoded['fileCoverage']['excluded']);
        self::assertSame(0, $decoded['fileCoverage']['skipped']);
    }

    public function testVerboseFlagShowsAllComponentClassLists(): void
    {
        $tester = $this->commandTester();

        // -v は symfony/console が提供する冗長度オプション。CommandTester では verbosity で指定する
        $tester->execute(
            ['paths' => [self::SIMPLE_PROJECT]],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
        );

        // ゾーン非該当のコンポーネントでもクラス一覧が出ることを確認する
        self::assertStringContainsString('Classes in', $tester->getDisplay());
    }

    // docblock（@var）だけで Domain -> Catalog に依存するフィクスチャ。
    // デフォルト（docblock 解析あり）ではコンポーネント間依存として Ce/Ca にカウントされ、
    // --no-docblock を指定するとカウントされなくなることを確認する。
    public function testNoDocblockOptionDisablesDocblockDependencyCollection(): void
    {
        $withDocblock = $this->commandTester();
        $withDocblock->execute([
            'paths' => [self::DOCBLOCK_ONLY_PROJECT],
            '--format' => 'json',
            '--depth' => '3',
        ]);
        $decodedWithDocblock = $this->decodeJson($withDocblock->getDisplay());
        $domainWithDocblock = $this->findComponent($decodedWithDocblock, 'Fixture\\DocblockOnlyProject\\Domain');

        self::assertSame(1, $domainWithDocblock['ce']);

        $withoutDocblock = $this->commandTester();
        $withoutDocblock->execute([
            'paths' => [self::DOCBLOCK_ONLY_PROJECT],
            '--format' => 'json',
            '--depth' => '3',
            '--no-docblock' => true,
        ]);
        $decodedWithoutDocblock = $this->decodeJson($withoutDocblock->getDisplay());
        $domainWithoutDocblock = $this->findComponent($decodedWithoutDocblock, 'Fixture\\DocblockOnlyProject\\Domain');

        self::assertSame(0, $domainWithoutDocblock['ce']);
    }

    public function testReportsOutOfScopeDependenciesIdenticallyInTextMarkdownAndJson(): void
    {
        $decoded = $this->decodeJson($this->render(['--format' => 'json']));
        $outOfScope = $decoded['outOfScopeDependencies'];

        // 全体は distinct な FQCN 数（DB / Cache / Logger / GlobalFacade）で、
        // コンポーネント別の単純合計（4 + 1）とは一致しない
        self::assertSame(4, $outOfScope['dependencyCount']);
        self::assertSame(3, $outOfScope['groupCount']);
        self::assertSame(
            ['Fixture\\Vendor\\Support\\Facades', '(global)', 'Fixture\\Vendor\\Log'],
            array_column($outOfScope['groups'], 'namespace'),
        );
        self::assertSame(
            ['Fixture\\OutOfScope\\Http', 'Fixture\\OutOfScope\\Domain'],
            array_column($outOfScope['components'], 'name'),
        );
        self::assertSame(4, $outOfScope['components'][0]['dependencyCount']);
        self::assertSame(1, $outOfScope['components'][1]['dependencyCount']);
        self::assertSame([
            'sourceFqcn' => 'Fixture\\OutOfScope\\Domain\\Report',
            'targetFqcn' => 'Fixture\\Vendor\\Support\\Facades\\DB',
            'kind' => 'static_call',
            'file' => 'Domain/Report.php',
            'line' => 21,
        ], $outOfScope['components'][1]['groups'][0]['evidence'][0]);

        $text = $this->render([], verbosity: OutputInterface::VERBOSITY_VERBOSE);
        self::assertStringContainsString('Dependencies outside analysis scope: 4 classes in 3 namespaces', $text);
        self::assertStringContainsString('  - Fixture\\Vendor\\Support\\Facades: 2 classes referenced by 2 classes', $text);
        self::assertStringContainsString('  - (global): 1 class referenced by 1 class', $text);
        self::assertStringContainsString('  Fixture\\OutOfScope\\Http (4 classes):', $text);
        self::assertStringContainsString('  Fixture\\OutOfScope\\Domain (1 class):', $text);
        self::assertStringContainsString(
            'Fixture\\OutOfScope\\Domain\\Report -> Fixture\\Vendor\\Support\\Facades\\DB (static_call) at Domain/Report.php:21',
            $text,
        );

        $markdown = $this->render(['--format' => 'markdown']);
        self::assertStringContainsString('## Dependencies outside analysis scope', $markdown);
        self::assertStringContainsString('Distinct classes outside the analysis scope 4 in 3 namespaces.', $markdown);
        self::assertStringContainsString('| `Fixture\\Vendor\\Support\\Facades` | 2 | 2 |', $markdown);
        self::assertStringContainsString('| `(global)` | 1 | 1 |', $markdown);
        self::assertStringContainsString('### `Fixture\\OutOfScope\\Domain`', $markdown);
        self::assertStringContainsString(
            '`Fixture\\OutOfScope\\Domain\\Report` to `Fixture\\Vendor\\Support\\Facades\\DB` using `static_call` at `Domain/Report.php:21`',
            $markdown,
        );
    }

    public function testTextFormatShowsOnlyOutOfScopeSummaryWithoutVerbose(): void
    {
        $text = $this->render([]);

        self::assertStringContainsString('Dependencies outside analysis scope: 4 classes in 3 namespaces', $text);
        self::assertStringNotContainsString('Fixture\\OutOfScope\\Http (4 classes):', $text);
        self::assertStringNotContainsString('Domain/Report.php:21', $text);
    }

    public function testDoesNotReportPhpBuiltInTypesAsOutOfScopeDependencies(): void
    {
        // OutOfScopeProject は DateTimeImmutable / Countable / RuntimeException も参照しているが、
        // いずれも PHP 組み込みなので集計に出ない
        $outOfScope = $this->decodeJson($this->render(['--format' => 'json']))['outOfScopeDependencies'];

        self::assertSame([
            'Fixture\\Vendor\\Support\\Facades\\Cache',
            'Fixture\\Vendor\\Support\\Facades\\DB',
            'GlobalFacade',
            'Fixture\\Vendor\\Log\\Logger',
        ], array_merge(...array_column($outOfScope['groups'], 'targets')));

        // SimpleProject は Exception / RuntimeException / Countable / ArrayAccess /
        // DateTimeImmutable / Attribute を参照しているが、解析対象外依存は0件になる
        $simple = $this->commandTester();
        $simple->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'json']);
        $simpleOutOfScope = $this->decodeJson($simple->getDisplay())['outOfScopeDependencies'];

        self::assertSame(0, $simpleOutOfScope['dependencyCount']);
        self::assertSame([], $simpleOutOfScope['groups']);
        self::assertSame([], $simpleOutOfScope['components']);
    }

    public function testDoesNotTriggerAutoloadForOutOfScopeFqcns(): void
    {
        // 解析に必要なクラスを先に読み込ませ、検知対象を解析対象外 FQCN の解決だけにする
        $this->render(['--format' => 'json']);

        $requested = [];
        $spy = static function (string $class) use (&$requested): void {
            $requested[] = $class;
        };
        spl_autoload_register($spy);

        try {
            $this->render(['--format' => 'json']);
        } finally {
            spl_autoload_unregister($spy);
        }

        self::assertSame([], $requested);
    }

    public function testOutOfScopeDependencyOrderIsDeterministic(): void
    {
        $first = $this->render(['--format' => 'json']);
        $second = $this->render(['--format' => 'json']);
        $firstText = $this->render([], verbosity: OutputInterface::VERBOSITY_VERBOSE);
        $secondText = $this->render([], verbosity: OutputInterface::VERBOSITY_VERBOSE);

        self::assertSame($first, $second);
        self::assertSame($firstText, $secondText);
    }

    public function testMetricsAndCycleDetectionAreUnaffectedByOutOfScopeCollection(): void
    {
        $simple = $this->commandTester();
        $simpleExitCode = $simple->execute(['paths' => [self::SIMPLE_PROJECT], '--format' => 'json']);
        $simpleDecoded = $this->decodeJson($simple->getDisplay());

        self::assertSame(Command::SUCCESS, $simpleExitCode);
        self::assertSame([
            ['(global)', 0, 0],
            ['Fixture\\App\\Domain', 1, 0],
            ['Fixture\\App\\Generated', 0, 0],
            ['Fixture\\App\\Infra', 0, 1],
        ], array_map(
            static fn (array $component): array => [$component['name'], $component['ca'], $component['ce']],
            $simpleDecoded['components'],
        ));
        self::assertSame([], $simpleDecoded['cycles']);

        $cyclic = $this->commandTester();
        $cyclicExitCode = $cyclic->execute([
            'paths' => [self::CYCLIC_PROJECT],
            '--depth' => '3',
            '--format' => 'json',
        ]);
        $cyclicDecoded = $this->decodeJson($cyclic->getDisplay());

        self::assertSame(Command::SUCCESS, $cyclicExitCode);
        self::assertSame([
            ['Fixture\\Cyclic\\A', 1, 1, 0.5],
            ['Fixture\\Cyclic\\B', 1, 1, 0.5],
            ['Fixture\\Cyclic\\C', 1, 1, 0.5],
            ['Fixture\\Cyclic\\D', 1, 1, 0.5],
            ['Fixture\\Cyclic\\E', 1, 1, 0.5],
        ], array_map(
            static fn (array $component): array => [
                $component['name'],
                $component['ca'],
                $component['ce'],
                $component['distance'],
            ],
            $cyclicDecoded['components'],
        ));
        self::assertSame([
            ['Fixture\\Cyclic\\A', 'Fixture\\Cyclic\\B'],
            ['Fixture\\Cyclic\\C', 'Fixture\\Cyclic\\D', 'Fixture\\Cyclic\\E'],
        ], $cyclicDecoded['cycles']);
    }

    /**
     * OutOfScopeProject を解析して標準出力の内容を返す。
     *
     * @param array<string, string> $options
     */
    private function render(array $options, int $verbosity = OutputInterface::VERBOSITY_NORMAL): string
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute(
            ['paths' => [self::OUT_OF_SCOPE_PROJECT], ...$options],
            ['verbosity' => $verbosity],
        );
        self::assertSame(Command::SUCCESS, $exitCode);

        return $tester->getDisplay();
    }

    /**
     * @param JsonReport $decoded
     * @return array{name: string, ce: int|null, ca: int|null}
     */
    private function findComponent(array $decoded, string $name): array
    {
        foreach ($decoded['components'] as $component) {
            if ($component['name'] === $name) {
                return $component;
            }
        }

        self::fail(sprintf('コンポーネント %s が見つかりませんでした', $name));
    }

    private function commandTester(): CommandTester
    {
        $application = new Application('psap', 'test');
        $application->add(new AnalyzeCommand());
        $command = $application->find('analyze');

        return new CommandTester($command);
    }

    private function temporaryBaselinePath(): string
    {
        return sys_get_temp_dir() . '/psap-cycle-baseline-' . uniqid() . '.json';
    }

    private function removeBetween(string $subject, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($subject, $startNeedle);
        self::assertNotFalse($start, sprintf('Marker "%s" was not found.', $startNeedle));
        $endMarker = strpos($subject, $endNeedle, $start + strlen($startNeedle));
        self::assertNotFalse($endMarker);

        return substr($subject, 0, $start) . substr($subject, $endMarker + strlen($endNeedle));
    }

    /** @return JsonReport */
    private function decodeJson(string $json): array
    {
        /** @var JsonReport $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

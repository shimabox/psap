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
 *     cycles: list<list<string>>,
 *     cyclePaths: list<array{path: list<string>, dependencies: list<Dependency>}>,
 *     cycleGroups: list<CycleGroup>,
 *     cycleBaselineComparison: array{hasChanges: bool, newCycles: list<list<string>>, resolvedCycles: list<list<string>>}|null,
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
        self::assertArrayHasKey('warnings', $decoded);
        self::assertGreaterThan(0, $decoded['summary']['componentCount']);
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
                } else {
                    $decoded = $this->decodeJson($report);
                    self::assertStringContainsString('Latin1.php', implode("\n", $decoded['warnings']));
                }
                self::assertStringContainsString('Latin1.php', $tester->getErrorOutput());
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
        self::assertSame($path[0], array_last($path));
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
        self::assertStringContainsString('--depth を増やしてください', $decoded['warnings'][0]);
    }

    public function testWarnsWhenProjectHasOnlyOneIndivisibleComponent(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['paths' => [self::BROKEN_PROJECT], '--format' => 'json']);

        $decoded = $this->decodeJson($tester->getDisplay());

        self::assertCount(1, $decoded['components']);
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

    /** @return JsonReport */
    private function decodeJson(string $json): array
    {
        /** @var JsonReport $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

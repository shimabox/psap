<?php

declare(strict_types=1);

namespace Bobsap\Tests\Feature;

use Bobsap\Console\AnalyzeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

// AnalyzeCommand の Feature テスト。
// tests/Fixtures/SimpleProject に対して実行し、出力内容と exit code を確認する。
final class AnalyzeCommandTest extends TestCase
{
    private const SIMPLE_PROJECT = __DIR__ . '/../Fixtures/SimpleProject';

    public function testTextFormatRendersTableAndExitsSuccessfully(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT]]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('bobsap - Stable Abstractions Principle metrics', $tester->getDisplay());
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
        self::assertArrayHasKey('warnings', $decoded);
        self::assertGreaterThan(0, $decoded['summary']['componentCount']);
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

    public function testOutputOptionWritesToFileInsteadOfStdout(): void
    {
        $outputPath = sys_get_temp_dir() . '/bobsap-analyze-command-test-' . uniqid() . '.txt';

        try {
            $tester = $this->commandTester();
            $exitCode = $tester->execute(['paths' => [self::SIMPLE_PROJECT], '--output' => $outputPath]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertSame('', $tester->getDisplay());
            self::assertFileExists($outputPath);
            self::assertStringContainsString('bobsap - Stable Abstractions Principle metrics', (string) file_get_contents($outputPath));
        } finally {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
        }
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

    private function commandTester(): CommandTester
    {
        $application = new Application('bobsap', 'test');
        $application->add(new AnalyzeCommand());
        $command = $application->find('analyze');

        return new CommandTester($command);
    }

    /**
     * @return array{
     *     summary: array{componentCount: int, meanDistance: float, varianceDistance: float},
     *     components: list<array{
     *         name: string,
     *         classCount: int,
     *         ca: int,
     *         ce: int,
     *         instability: float,
     *         abstractness: float,
     *         distance: float,
     *         zone: string|null,
     *         classes: list<array{fqcn: string, kind: string}>,
     *     }>,
     *     warnings: list<string>,
     * }
     */
    private function decodeJson(string $json): array
    {
        /**
         * @var array{
         *     summary: array{componentCount: int, meanDistance: float, varianceDistance: float},
         *     components: list<array{
         *         name: string,
         *         classCount: int,
         *         ca: int,
         *         ce: int,
         *         instability: float,
         *         abstractness: float,
         *         distance: float,
         *         zone: string|null,
         *         classes: list<array{fqcn: string, kind: string}>,
         *     }>,
         *     warnings: list<string>,
         * } $decoded
         */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

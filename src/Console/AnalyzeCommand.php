<?php

declare(strict_types=1);

namespace Bobsap\Console;

use Bobsap\Analyzer\DependencyAnalyzer;
use Bobsap\Analyzer\SourceFinder;
use Bobsap\Component\ComponentClassifier;
use Bobsap\Component\CycleDetector;
use Bobsap\Component\DependencyGraph;
use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\MetricsCalculator;
use Bobsap\Metrics\MetricsSummary;
use Bobsap\Report\JsonReporter;
use Bobsap\Report\MermaidReporter;
use Bobsap\Report\PlantUmlReporter;
use Bobsap\Report\ReportData;
use Bobsap\Report\ReporterInterface;
use Bobsap\Report\TextReporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `analyze` コマンド。
 *
 * パイプライン: SourceFinder → DependencyAnalyzer → ComponentClassifier → MetricsCalculator → Reporter
 *
 * exit code 規約:
 *   0 = 正常終了
 *   1 = --threshold で指定した D 値を超えるコンポーネントがあった、
 *       または --fail-on-cycle 指定時に循環依存（ADP違反）が見つかった
 *   2 = 入力エラー（存在しないパス、未知の --format）
 */
#[AsCommand(name: 'analyze', description: 'PHP コードベースの SAP メトリクス（Ca/Ce/I/A/D）を計測する')]
final class AnalyzeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'paths',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                '解析対象ディレクトリ（複数指定可）',
            )
            ->addOption(
                'depth',
                null,
                InputOption::VALUE_REQUIRED,
                'コンポーネントに束ねる名前空間の深さ',
                '2',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('出力形式（%s）', implode(' | ', array_keys($this->reporterFactories()))),
                'text',
            )
            ->addOption(
                'output',
                null,
                InputOption::VALUE_REQUIRED,
                '出力先ファイル（省略時は標準出力）',
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                '除外パターン（fnmatch 形式、複数指定可）',
            )
            ->addOption(
                'threshold',
                null,
                InputOption::VALUE_REQUIRED,
                'D 値がこれを超えるコンポーネントがあれば exit code 1 にする',
            )
            ->addOption(
                'fail-on-cycle',
                null,
                InputOption::VALUE_NONE,
                '循環依存（ADP違反）が1つでもあれば exit code 1 にする',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $missingPath = $this->findMissingPath($paths);
        if ($missingPath !== null) {
            $errorOutput->writeln(sprintf('<error>指定されたパスが存在しません: %s</error>', $missingPath));

            return Command::INVALID;
        }

        /** @var string $format */
        $format = $input->getOption('format');
        $reporterFactory = $this->reporterFactories()[$format] ?? null;
        if ($reporterFactory === null) {
            $errorOutput->writeln(sprintf(
                '<error>未知の出力形式です: %s（利用可能: %s）</error>',
                $format,
                implode(', ', array_keys($this->reporterFactories())),
            ));

            return Command::INVALID;
        }

        /** @var string $depthOption */
        $depthOption = $input->getOption('depth');
        $depth = (int) $depthOption;

        /** @var list<string> $excludePatterns */
        $excludePatterns = $input->getOption('exclude');

        /** @var string|null $thresholdOption */
        $thresholdOption = $input->getOption('threshold');
        $threshold = $thresholdOption !== null ? (float) $thresholdOption : null;

        /** @var bool $failOnCycle */
        $failOnCycle = $input->getOption('fail-on-cycle');

        $files = (new SourceFinder())->find($paths, $excludePatterns);
        $analysisResult = (new DependencyAnalyzer())->analyze($files);
        $components = (new ComponentClassifier())->classify($analysisResult->classInfos, $depth);
        $componentMetrics = (new MetricsCalculator())->calculate($components);
        $summary = MetricsSummary::from($componentMetrics);
        $cycles = (new CycleDetector())->detect(DependencyGraph::fromComponents($components));

        $reportData = new ReportData($componentMetrics, $summary, $analysisResult->warnings, $cycles);
        $reporter = $reporterFactory($output->isVerbose());
        $rendered = $reporter->render($reportData);

        /** @var string|null $outputPath */
        $outputPath = $input->getOption('output');
        if ($outputPath !== null) {
            file_put_contents($outputPath, $rendered . PHP_EOL);
        } else {
            $output->writeln($rendered);
        }

        if ($threshold !== null) {
            $exceeded = array_values(array_filter(
                $componentMetrics,
                static fn (ComponentMetrics $metrics): bool => $metrics->distance > $threshold,
            ));

            if ($exceeded !== []) {
                $errorOutput->writeln(sprintf('<error>D 値が閾値 %.2f を超えるコンポーネントがあります:</error>', $threshold));
                foreach ($exceeded as $metrics) {
                    $errorOutput->writeln(sprintf('  - %s: D=%.2f', $metrics->component->name, $metrics->distance));
                }

                return Command::FAILURE;
            }
        }

        if ($failOnCycle && $cycles !== []) {
            $errorOutput->writeln('<error>循環依存（ADP違反）が見つかりました:</error>');
            foreach ($cycles as $cycle) {
                $errorOutput->writeln('  - ' . $this->formatCycle($cycle));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * 循環1件分の表示行を作る（TextReporter::cycleLine と同じ規則）。
     * 2ノードは `<->`、3ノード以上は先頭に戻る `->` チェーンで表す。
     *
     * @param list<string> $cycle
     */
    private function formatCycle(array $cycle): string
    {
        if (count($cycle) === 2) {
            return sprintf('%s <-> %s', $cycle[0], $cycle[1]);
        }

        return implode(' -> ', $cycle) . ' -> ' . $cycle[0];
    }

    /**
     * @param list<string> $paths
     */
    private function findMissingPath(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 出力形式名 → Reporter を作るファクトリのマッピング。
     * Phase 4 で mermaid / plantuml を足すときはここにエントリを追加するだけでよい。
     *
     * @return array<string, callable(bool): ReporterInterface>
     */
    private function reporterFactories(): array
    {
        return [
            'text' => static fn (bool $verbose): ReporterInterface => new TextReporter($verbose),
            'json' => static fn (bool $verbose): ReporterInterface => new JsonReporter(),
            'mermaid' => static fn (bool $verbose): ReporterInterface => new MermaidReporter(),
            'plantuml' => static fn (bool $verbose): ReporterInterface => new PlantUmlReporter(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Psap\Console;

use InvalidArgumentException;
use Psap\Analyzer\AnalysisCoverage;
use Psap\Analyzer\DependencyAnalyzer;
use Psap\Analyzer\SourceFinder;
use Psap\Baseline\CycleBaseline;
use Psap\Component\ComponentClassifier;
use Psap\Component\ComponentDepthResolver;
use Psap\Component\CycleDetector;
use Psap\Component\DependencyGraph;
use Psap\Diagnostic\Diagnostic;
use Psap\Diagnostic\DiagnosticAction;
use Psap\Diagnostic\DiagnosticCode;
use Psap\Diagnostic\DiagnosticFormatter;
use Psap\Diagnostic\DiagnosticSeverity;
use Psap\Metrics\ComponentMetrics;
use Psap\Metrics\MetricsCalculator;
use Psap\Metrics\MetricsSummary;
use Psap\Report\HtmlReporter;
use Psap\Report\JsonReporter;
use Psap\Report\MarkdownReporter;
use Psap\Report\MermaidReporter;
use Psap\Report\PlantUmlReporter;
use Psap\Report\PortalReporter;
use Psap\Report\ReportData;
use Psap\Report\ReporterInterface;
use Psap\Report\TextReporter;
use RuntimeException;
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
                'コンポーネントに束ねる名前空間の深さ（auto または1以上の整数）',
                'auto',
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
            )
            ->addOption(
                'generate-cycle-baseline',
                null,
                InputOption::VALUE_REQUIRED,
                '現在の循環依存をベースラインファイルへ保存する',
            )
            ->addOption(
                'cycle-baseline',
                null,
                InputOption::VALUE_REQUIRED,
                '既存循環のベースラインファイルと比較する',
            )
            ->addOption(
                'no-docblock',
                null,
                InputOption::VALUE_NONE,
                'docblock（@var / @param / @return / @throws）からの依存抽出を無効にする',
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
        $depth = $depthOption === 'auto'
            ? null
            : filter_var($depthOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($depth === false) {
            $errorOutput->writeln(sprintf('<error>--depth には auto または1以上の整数を指定してください: %s</error>', $depthOption));

            return Command::INVALID;
        }

        /** @var list<string> $excludePatterns */
        $excludePatterns = $input->getOption('exclude');

        /** @var string|null $thresholdOption */
        $thresholdOption = $input->getOption('threshold');
        $threshold = null;
        if ($thresholdOption !== null) {
            $parsedThreshold = filter_var($thresholdOption, FILTER_VALIDATE_FLOAT);
            if ($parsedThreshold === false || !is_finite($parsedThreshold) || $parsedThreshold < 0.0 || $parsedThreshold > 1.0) {
                $errorOutput->writeln(sprintf('<error>--threshold には0.0以上1.0以下の数値を指定してください: %s</error>', $thresholdOption));

                return Command::INVALID;
            }

            $threshold = $parsedThreshold;
        }

        /** @var bool $failOnCycle */
        $failOnCycle = $input->getOption('fail-on-cycle');

        /** @var string|null $generateCycleBaselinePath */
        $generateCycleBaselinePath = $input->getOption('generate-cycle-baseline');
        /** @var string|null $cycleBaselinePath */
        $cycleBaselinePath = $input->getOption('cycle-baseline');
        /** @var string|null $outputPath */
        $outputPath = $input->getOption('output');
        if ($generateCycleBaselinePath !== null && $cycleBaselinePath !== null) {
            $errorOutput->writeln('<error>--generate-cycle-baseline と --cycle-baseline は同時に指定できません。</error>');

            return Command::INVALID;
        }
        if ($generateCycleBaselinePath !== null && $failOnCycle) {
            $errorOutput->writeln('<error>--generate-cycle-baseline と --fail-on-cycle は同時に指定できません。</error>');

            return Command::INVALID;
        }
        if ($generateCycleBaselinePath !== null && $generateCycleBaselinePath === $outputPath) {
            $errorOutput->writeln('<error>循環ベースラインと解析レポートの出力先は別のファイルにしてください。</error>');

            return Command::INVALID;
        }

        $cycleBaseline = null;
        if ($cycleBaselinePath !== null) {
            $cycleBaseline = $this->loadCycleBaseline($cycleBaselinePath, $errorOutput);
            if ($cycleBaseline === null) {
                return Command::INVALID;
            }
        }

        /** @var bool $noDocblock */
        $noDocblock = $input->getOption('no-docblock');

        try {
            $sourceInventory = (new SourceFinder())->discover($paths, $excludePatterns);
        } catch (RuntimeException $e) {
            $errorOutput->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }
        $files = $sourceInventory->selectedFiles;
        $analysisResult = (new DependencyAnalyzer(useDocblock: !$noDocblock, sourceRoots: $paths))->analyze($files);
        $analysisCoverage = new AnalysisCoverage(
            discovered: $sourceInventory->discoveredFileCount,
            selected: $sourceInventory->selectedFileCount,
            analyzed: $analysisResult->analyzedFileCount,
            excluded: $sourceInventory->excludedFileCount,
            skipped: $analysisResult->skippedFileCount,
        );
        $depth ??= (new ComponentDepthResolver())->resolve($analysisResult->classInfos);
        $components = (new ComponentClassifier())->classify($analysisResult->classInfos, $depth);
        $componentMetrics = (new MetricsCalculator())->calculate($components);
        $summary = MetricsSummary::from($componentMetrics);
        $dependencyGraph = DependencyGraph::fromComponents($components);
        $cycles = (new CycleDetector())->detect($dependencyGraph);

        $cycleBaselineComparison = null;
        if ($cycleBaseline !== null) {
            try {
                $cycleBaseline->assertCompatible($depth, !$noDocblock, $excludePatterns);
            } catch (InvalidArgumentException $e) {
                $errorOutput->writeln(sprintf('<error>%s</error>', $e->getMessage()));

                return Command::INVALID;
            }
            $cycleBaselineComparison = $cycleBaseline->compare($cycles);
        }

        if ($generateCycleBaselinePath !== null) {
            $generatedBaseline = CycleBaseline::create($depth, !$noDocblock, $excludePatterns, $cycles);
            if (@file_put_contents($generateCycleBaselinePath, $generatedBaseline->toJson() . PHP_EOL) === false) {
                $errorOutput->writeln(sprintf('<error>循環ベースラインを書き込めませんでした: %s</error>', $generateCycleBaselinePath));

                return Command::FAILURE;
            }
        }

        $diagnostics = $analysisResult->diagnostics;
        if ($components === []) {
            $diagnostics[] = new Diagnostic(
                code: DiagnosticCode::AnalysisNoTypes,
                severity: DiagnosticSeverity::Warning,
                actions: [DiagnosticAction::ReviewSourcePaths],
            );
        } elseif (count($components) === 1) {
            $hasDeeperNamespaces = $this->hasDeeperNamespaces($analysisResult->classInfos, $components[0]->name);
            $diagnostics[] = new Diagnostic(
                code: $hasDeeperNamespaces
                    ? DiagnosticCode::AnalysisSingleComponentDepth
                    : DiagnosticCode::AnalysisSingleComponentUnevaluable,
                severity: DiagnosticSeverity::Info,
                actions: [$hasDeeperNamespaces
                    ? DiagnosticAction::IncreaseDepth
                    : DiagnosticAction::ReviewComponentBoundary],
            );
        }

        if ($output instanceof ConsoleOutputInterface) {
            $formatter = new DiagnosticFormatter('ja');
            foreach ($diagnostics as $diagnostic) {
                $errorOutput->writeln(sprintf(
                    '<comment>%s [%s]: %s</comment>',
                    ucfirst($diagnostic->severity->value),
                    $diagnostic->code->value,
                    $formatter->format($diagnostic),
                ));
            }
        }

        $reportData = new ReportData(
            $componentMetrics,
            $summary,
            [],
            $cycles,
            $dependencyGraph,
            $depth,
            $cycleBaselineComparison,
            $paths,
            !$noDocblock,
            $excludePatterns,
            $analysisCoverage,
            $diagnostics,
        );
        $reporter = $reporterFactory($output->isVerbose());
        $rendered = $reporter->render($reportData);

        if ($outputPath !== null) {
            if (@file_put_contents($outputPath, $rendered . PHP_EOL) === false) {
                $errorOutput->writeln(sprintf('<error>出力ファイルに書き込めませんでした: %s</error>', $outputPath));

                return Command::FAILURE;
            }
        } else {
            $rawFormat = $format === 'html' || $format === 'portal';
            $output->write($rendered . PHP_EOL, false, $rawFormat ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
        }

        if ($threshold !== null) {
            $exceeded = array_values(array_filter(
                $componentMetrics,
                static fn (ComponentMetrics $metrics): bool => $metrics->dependencyMetricsEvaluable
                    && $metrics->distance > $threshold,
            ));

            if ($exceeded !== []) {
                $errorOutput->writeln(sprintf('<error>D 値が閾値 %.2f を超えるコンポーネントがあります:</error>', $threshold));
                foreach ($exceeded as $metrics) {
                    $errorOutput->writeln(sprintf('  - %s: D=%.2f', $metrics->component->name, $metrics->distance));
                }

                return Command::FAILURE;
            }
        }

        $failingCycles = $cycleBaselineComparison === null ? $cycles : $cycleBaselineComparison->newCycles;
        if ($failOnCycle && $failingCycles !== []) {
            $errorOutput->writeln($cycleBaselineComparison === null
                ? '<error>循環依存（ADP違反）が見つかりました:</error>'
                : '<error>ベースラインにない循環依存（ADP違反）が見つかりました:</error>');
            foreach ($reportData->cycleGroups() as $group) {
                if (!in_array($group['components'], $failingCycles, true)) {
                    continue;
                }
                $errorOutput->writeln(sprintf(
                    '  - %d components, %s namespaces',
                    $group['componentCount'],
                    $group['namespaceRelation'],
                ));
                $errorOutput->writeln('    Components: ' . implode(', ', $group['components']));
                $errorOutput->writeln('    Representative shortest path: ' . implode(' -> ', $group['representativePath']));
                if ($group['omittedComponents'] !== []) {
                    $errorOutput->writeln(sprintf('    Omitted from path: %d', count($group['omittedComponents'])));
                }
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function loadCycleBaseline(string $path, OutputInterface $errorOutput): ?CycleBaseline
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            $errorOutput->writeln(sprintf('<error>循環ベースラインを読み込めませんでした: %s</error>', $path));

            return null;
        }

        try {
            return CycleBaseline::fromJson($json);
        } catch (InvalidArgumentException $e) {
            $errorOutput->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return null;
        }
    }

    /**
     * @param list<\Psap\Analyzer\ClassInfo> $classInfos
     */
    private function hasDeeperNamespaces(array $classInfos, string $componentName): bool
    {
        $prefix = $componentName . '\\';
        foreach ($classInfos as $classInfo) {
            $namespaceEnd = strrpos($classInfo->fqcn, '\\');
            if ($namespaceEnd === false) {
                continue;
            }

            $namespace = substr($classInfo->fqcn, 0, $namespaceEnd);
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }

        return false;
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
            'markdown' => static fn (bool $verbose): ReporterInterface => new MarkdownReporter(),
            'html' => static fn (bool $verbose): ReporterInterface => new HtmlReporter(),
            'mermaid' => static fn (bool $verbose): ReporterInterface => new MermaidReporter(),
            'plantuml' => static fn (bool $verbose): ReporterInterface => new PlantUmlReporter(),
            'portal' => static fn (bool $verbose): ReporterInterface => new PortalReporter(),
        ];
    }
}

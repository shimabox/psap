<?php

declare(strict_types=1);

namespace Psap\Analyzer;

/**
 * DependencyAnalyzer::analyze() の結果。
 *
 * 正常に解析できた ClassInfo 一覧、パースエラー等で発生した警告メッセージ一覧、
 * および解析成功・スキップしたファイル数を持つ。
 */
final readonly class AnalysisResult
{
    /**
     * @param list<ClassInfo> $classInfos
     * @param list<string> $warnings
     */
    public function __construct(
        public array $classInfos,
        public array $warnings,
        public int $analyzedFileCount = 0,
        public int $skippedFileCount = 0,
    ) {
    }
}

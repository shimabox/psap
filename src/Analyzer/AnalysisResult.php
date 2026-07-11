<?php

declare(strict_types=1);

namespace Bobsap\Analyzer;

/**
 * DependencyAnalyzer::analyze() の結果。
 *
 * 正常に解析できた ClassInfo 一覧と、パースエラー等で発生した警告メッセージ一覧を持つ。
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
    ) {
    }
}

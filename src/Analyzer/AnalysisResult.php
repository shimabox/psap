<?php

declare(strict_types=1);

namespace Psap\Analyzer;

use Psap\Diagnostic\Diagnostic;
use Psap\Diagnostic\DiagnosticFormatter;

/**
 * DependencyAnalyzer::analyze() の結果。
 *
 * 正常に解析できた ClassInfo 一覧、パースエラー等で発生した警告メッセージ一覧、
 * および解析成功・スキップしたファイル数を持つ。
 */
final readonly class AnalysisResult
{
    /** @var list<ClassInfo> */
    public array $classInfos;
    /** @var list<string> */
    public array $warnings;
    /** @var list<Diagnostic> */
    public array $diagnostics;
    public int $analyzedFileCount;
    public int $skippedFileCount;

    /**
     * @param list<ClassInfo> $classInfos
     * @param list<string> $warnings
     * @param array<mixed> $diagnostics
     */
    public function __construct(
        array $classInfos,
        array $warnings = [],
        int $analyzedFileCount = 0,
        int $skippedFileCount = 0,
        array $diagnostics = [],
    ) {
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof Diagnostic) {
                throw new \InvalidArgumentException('Analysis diagnostics must be Diagnostic values.');
            }
        }

        if (!array_is_list($diagnostics)) {
            throw new \InvalidArgumentException('Analysis diagnostics must be a list.');
        }

        $this->classInfos = $classInfos;
        /** @var list<Diagnostic> $diagnostics */
        $this->diagnostics = $diagnostics;
        $this->warnings = $diagnostics === []
            ? $warnings
            : array_map((new DiagnosticFormatter('ja'))->format(...), $diagnostics);
        $this->analyzedFileCount = $analyzedFileCount;
        $this->skippedFileCount = $skippedFileCount;
    }
}

<?php

declare(strict_types=1);

namespace Psap\Analyzer;

/**
 * ソース探索の結果と、exclude 適用前後のファイル数を表す。
 */
final readonly class SourceInventory
{
    public int $selectedFileCount;

    /**
     * @param list<string> $selectedFiles 解析対象となる .php ファイルの絶対パス一覧
     */
    public function __construct(
        public array $selectedFiles,
        public int $discoveredFileCount,
        public int $excludedFileCount,
    ) {
        $this->selectedFileCount = count($this->selectedFiles);

        if ($this->discoveredFileCount < 0 || $this->excludedFileCount < 0) {
            throw new \InvalidArgumentException('Source inventory counts must be non-negative integers.');
        }

        if ($this->discoveredFileCount !== $this->selectedFileCount + $this->excludedFileCount) {
            throw new \InvalidArgumentException('discoveredFileCount must equal selectedFileCount + excludedFileCount.');
        }
    }
}

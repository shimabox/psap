<?php

declare(strict_types=1);

namespace Psap\Analyzer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

/**
 * 指定ディレクトリ配下の .php ファイルを再帰的に列挙する。
 *
 * exclude パターンは fnmatch 形式で、各探索ディレクトリからの相対パスに対して適用する
 * （例: "Tests" ディレクトリ配下を除外するパターンを指定する）。
 */
final class SourceFinder
{
    /**
     * @param list<string> $directories 探索対象ディレクトリ（複数可）
     * @param list<string> $excludePatterns fnmatch 形式の除外パターン
     * @return list<string> 見つかった .php ファイルの絶対パス一覧（ソート済み・重複なし）
     */
    public function find(array $directories, array $excludePatterns = []): array
    {
        return $this->discover($directories, $excludePatterns)->selectedFiles;
    }

    /**
     * @param list<string> $directories 探索対象ディレクトリ（複数可）
     * @param list<string> $excludePatterns fnmatch 形式の除外パターン
     */
    public function discover(array $directories, array $excludePatterns = []): SourceInventory
    {
        /** @var array<string, bool> $selectedByRealPath */
        $selectedByRealPath = [];

        foreach ($directories as $directory) {
            foreach ($this->discoverInDirectory($directory, $excludePatterns) as $file) {
                $path = $file['path'];
                $isSelected = !$file['excluded'];

                // 重複するルートで判定が異なる場合は、一度でも選択された結果を優先する。
                $selectedByRealPath[$path] = ($selectedByRealPath[$path] ?? false) || $isSelected;
            }
        }

        $selectedFiles = array_keys(array_filter($selectedByRealPath));
        sort($selectedFiles);

        return new SourceInventory(
            selectedFiles: $selectedFiles,
            discoveredFileCount: count($selectedByRealPath),
            excludedFileCount: count(array_filter(
                $selectedByRealPath,
                static fn (bool $isSelected): bool => !$isSelected,
            )),
        );
    }

    /**
     * @param list<string> $excludePatterns
     * @return list<array{path: string, excluded: bool}>
     */
    private function discoverInDirectory(string $directory, array $excludePatterns): array
    {
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !is_dir($realDirectory) || !is_readable($realDirectory)) {
            throw new RuntimeException(sprintf('ディレクトリを読み取れません: %s', $directory));
        }

        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realDirectory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                if ($fileInfo->getExtension() !== 'php') {
                    continue;
                }

                $path = $fileInfo->getPathname();
                $relativePath = ltrim(substr($path, strlen($realDirectory)), DIRECTORY_SEPARATOR);
                $realPath = realpath($path);

                $files[] = [
                    'path' => $realPath === false ? $path : $realPath,
                    'excluded' => $this->isExcluded($relativePath, $excludePatterns),
                ];
            }
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException(sprintf('ディレクトリを読み取れません: %s', $directory), previous: $e);
        }

        return $files;
    }

    /**
     * @param list<string> $excludePatterns
     */
    private function isExcluded(string $relativePath, array $excludePatterns): bool
    {
        foreach ($excludePatterns as $pattern) {
            if (fnmatch($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}

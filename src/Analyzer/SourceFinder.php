<?php

declare(strict_types=1);

namespace Bobsap\Analyzer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
        $files = [];

        foreach ($directories as $directory) {
            foreach ($this->findInDirectory($directory, $excludePatterns) as $file) {
                $files[] = $file;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $excludePatterns
     * @return list<string>
     */
    private function findInDirectory(string $directory, array $excludePatterns): array
    {
        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            return [];
        }

        $files = [];

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

            if ($this->isExcluded($relativePath, $excludePatterns)) {
                continue;
            }

            $files[] = $path;
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

<?php

declare(strict_types=1);

namespace Fixture\Broken;

// 意図的な構文エラーのテスト用フィクスチャ。
// DependencyAnalyzer が例外を投げずに警告を収集し、このファイルをスキップすることを確認する。
final class Broken
{
    public function oops() {
        this is not valid php syntax !!!
    }
}

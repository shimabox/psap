<?php

declare(strict_types=1);

namespace Fixture\Broken;

// 同じディレクトリに構文エラーファイルがあっても、他の有効なファイルは
// 問題なく解析できることを確認するためのフィクスチャ
final class Valid
{
    public function ok(): bool
    {
        return true;
    }
}

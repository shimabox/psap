<?php

declare(strict_types=1);

// グローバル名前空間のクラスが FQCN（短い名前そのまま）として扱えることの確認用フィクスチャ。
// 1ファイルに複数の型宣言があってもすべて拾えることの確認も兼ねる。

final class LegacyGlobalThing
{
    public function make(): GlobalHelper
    {
        return new GlobalHelper();
    }
}

final class GlobalHelper
{
}

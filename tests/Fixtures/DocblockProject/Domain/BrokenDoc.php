<?php

declare(strict_types=1);

namespace Fixture\DocblockProject\Domain;

// 壊れた docblock を含むフィクスチャ。パース失敗しても例外を投げず、
// 実コードの型宣言（Product）は問題なく依存として拾えることを確認する。
final class BrokenDoc
{
    /** @var array< */
    private $broken;

    public function normal(): Product
    {
        return new Product();
    }
}

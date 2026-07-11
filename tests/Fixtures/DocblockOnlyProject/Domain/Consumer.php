<?php

declare(strict_types=1);

namespace Fixture\DocblockOnlyProject\Domain;

use Fixture\DocblockOnlyProject\Catalog\Item;

// 実コードの型宣言では一切 Item を参照せず、docblock（@var）だけで依存する。
// --no-docblock の有無でコンポーネント間メトリクス（Ce/Ca）が変わることを確認するためのフィクスチャ
final class Consumer
{
    /** @var Item */
    private $item;
}

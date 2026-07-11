<?php

declare(strict_types=1);

namespace Fixture\DocblockProject\Domain;

use Fixture\DocblockProject\Domain\Product as ProductAlias;

// docblock（@var / @param / @return）だけから依存を拾えることを確認するフィクスチャ。
// あえて実コードの型宣言は一切使わず、Product への依存が docblock 由来であることを明確にする。
// ProductAlias という use 文のエイリアスを使い、短縮名の FQCN 解決も同時に確認する。
final class Order
{
    /** @var ProductAlias */
    private $mainProduct;

    /** @var ProductAlias[] */
    private $items;

    /**
     * プロモートされたコンストラクタ引数の @param（コンストラクタの docblock に書く）
     *
     * @param ProductAlias $product
     */
    public function __construct(
        private $product,
    ) {
        $this->mainProduct = $product;
        $this->items = [];
    }

    /**
     * @return ProductAlias
     */
    public function mainProduct()
    {
        return $this->mainProduct;
    }

    /**
     * @param array<int, ProductAlias> $items array<int, X> 形式の確認
     */
    public function setItems($items): void
    {
        $this->items = $items;
    }
}

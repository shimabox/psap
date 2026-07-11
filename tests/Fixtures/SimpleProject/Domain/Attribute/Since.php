<?php

declare(strict_types=1);

namespace Fixture\App\Domain\Attribute;

use Attribute;

// アトリビュートクラス自身が組み込みアトリビュート #[Attribute] に依存していることの確認用フィクスチャ
#[Attribute]
final class Since
{
    public function __construct(public readonly string $version)
    {
    }
}

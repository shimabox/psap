<?php

declare(strict_types=1);

namespace Fixture\OutOfScope\Domain;

use Countable;
use DateTimeImmutable;
use Fixture\Vendor\Support\Facades\DB;

// 別コンポーネントからも同じ解析対象外 FQCN（DB）を参照する確認用フィクスチャ。
// 全体の件数がコンポーネント別件数の単純合計にならないことを確かめる。
final class Report implements Countable
{
    public function __construct(private readonly DateTimeImmutable $createdAt)
    {
    }

    public function count(): int
    {
        DB::table('reports');

        return $this->createdAt->getTimestamp();
    }
}

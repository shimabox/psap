<?php

declare(strict_types=1);

namespace Fixture\App\Domain;

// TypeKind::Trait_ 判定と、PHP 組み込みクラス（\DateTimeImmutable）への依存が
// フィルタリングされずそのまま抽出されることの確認用フィクスチャ
trait HasTimestamps
{
    private \DateTimeImmutable $createdAt;

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

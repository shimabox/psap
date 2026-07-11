<?php

declare(strict_types=1);

namespace Fixture\App\Domain;

// プロパティ・引数型宣言による依存抽出の確認用フィクスチャ
final class Address
{
    public function __construct(
        private readonly string $city,
    ) {
    }

    public function city(): string
    {
        return $this->city;
    }
}

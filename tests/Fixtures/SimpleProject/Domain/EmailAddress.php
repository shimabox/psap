<?php

declare(strict_types=1);

namespace Fixture\App\Domain;

// static 呼び出し（X::method()）の抽出と、new self(...) / self 戻り値型が
// 依存として除外されることの確認用フィクスチャ
final class EmailAddress
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}

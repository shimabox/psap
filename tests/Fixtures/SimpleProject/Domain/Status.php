<?php

declare(strict_types=1);

namespace Fixture\App\Domain;

// TypeKind::Enum_ 判定と、enum の implements 依存の確認用フィクスチャ
enum Status: string implements Nameable
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return $this->value;
    }
}

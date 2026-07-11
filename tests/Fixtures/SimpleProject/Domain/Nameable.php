<?php

declare(strict_types=1);

namespace Fixture\App\Domain;

// TypeKind::Interface_ 判定用フィクスチャ
interface Nameable
{
    public function label(): string;
}

<?php

declare(strict_types=1);

namespace Fixture\App\Domain;

// TypeKind::AbstractClass 判定用フィクスチャ
abstract class AbstractEntity
{
    abstract public function id(): string;
}

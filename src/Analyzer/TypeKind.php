<?php

declare(strict_types=1);

namespace Bobsap\Analyzer;

/**
 * PHP の型宣言の種別。
 *
 * `interface` / `trait` / `enum` は PHP の予約語と衝突するため、
 * 末尾にアンダースコアを付けたケース名にしている。
 */
enum TypeKind
{
    case Interface_;
    case AbstractClass;
    case ConcreteClass;
    case Enum_;
    case Trait_;

    /**
     * 抽象度（A = 抽象型数 / 総型数）の計算で「抽象型」として数えるかどうか。
     * interface と abstract class のみ抽象型として扱う。
     */
    public function isAbstract(): bool
    {
        return match ($this) {
            self::Interface_, self::AbstractClass => true,
            self::ConcreteClass, self::Enum_, self::Trait_ => false,
        };
    }
}

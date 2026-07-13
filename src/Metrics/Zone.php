<?php

declare(strict_types=1);

namespace Psap\Metrics;

/**
 * コンポーネントが「主系列」から見て問題領域（ゾーン）にあるかどうか。
 *
 * - Pain（苦痛ゾーン）: I（不安定さ）も A（抽象度）も低く、(0,0) 付近にある。
 *   安定していて具象的なため、変更コストが高い。
 * - Useless（無駄ゾーン）: I も A も高く、(1,1) 付近にある。
 *   不安定なのに抽象的すぎるため、使われない抽象が多い。
 */
enum Zone
{
    case None;
    case Pain;
    case Useless;

    /** 表示用の日本語ラベル。該当ゾーンなしの場合は空文字。 */
    public function label(): string
    {
        return match ($this) {
            self::None => '',
            self::Pain => '苦痛ゾーン',
            self::Useless => '無駄ゾーン',
        };
    }

    /**
     * I（不安定さ）と A（抽象度）から所属ゾーンを判定する。
     *
     * (0,0) からの距離が 0.5 未満なら苦痛ゾーン、(1,1) からの距離が 0.5 未満なら無駄ゾーン。
     * 2つの円は重ならないため両方に該当することはない。境界のちょうど 0.5 は None とする。
     */
    public static function determine(float $instability, float $abstractness): self
    {
        $distanceFromOrigin = sqrt($instability ** 2 + $abstractness ** 2);
        if ($distanceFromOrigin < 0.5) {
            return self::Pain;
        }

        $distanceFromMaxCorner = sqrt((1 - $instability) ** 2 + (1 - $abstractness) ** 2);
        if ($distanceFromMaxCorner < 0.5) {
            return self::Useless;
        }

        return self::None;
    }
}

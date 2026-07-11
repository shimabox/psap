<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Metrics;

use Bobsap\Metrics\Zone;
use PHPUnit\Framework\TestCase;

// Zone::determine() のゾーン判定境界値のテスト。
// (0,0) からの距離 < 0.5 なら苦痛ゾーン、(1,1) からの距離 < 0.5 なら無駄ゾーン。
// 境界のちょうど 0.5 は None（厳密に未満のみ該当）とする。
final class ZoneTest extends TestCase
{
    public function testOriginIsPainZone(): void
    {
        self::assertSame(Zone::Pain, Zone::determine(0.0, 0.0));
    }

    public function testCornerIsUselessZone(): void
    {
        self::assertSame(Zone::Useless, Zone::determine(1.0, 1.0));
    }

    public function testExactBoundaryDistanceFromOriginIsNone(): void
    {
        // I=0.5, A=0.0 → 原点からの距離はちょうど 0.5
        self::assertSame(Zone::None, Zone::determine(0.5, 0.0));
    }

    public function testExactBoundaryDistanceFromCornerIsNone(): void
    {
        // I=0.5, A=1.0 → (1,1) からの距離はちょうど 0.5
        self::assertSame(Zone::None, Zone::determine(0.5, 1.0));
    }

    public function testMidpointIsNone(): void
    {
        self::assertSame(Zone::None, Zone::determine(0.5, 0.5));
    }

    public function testLabels(): void
    {
        self::assertSame('苦痛ゾーン', Zone::Pain->label());
        self::assertSame('無駄ゾーン', Zone::Useless->label());
        self::assertSame('', Zone::None->label());
    }
}

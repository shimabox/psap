<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Analyzer;

use Bobsap\Analyzer\TypeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// TypeKind::isAbstract() が「抽象型（interface / abstract class）」のときだけ true を返すことのテスト
final class TypeKindTest extends TestCase
{
    #[DataProvider('kindProvider')]
    public function testIsAbstract(TypeKind $kind, bool $expected): void
    {
        self::assertSame($expected, $kind->isAbstract());
    }

    /**
     * @return array<string, array{TypeKind, bool}>
     */
    public static function kindProvider(): array
    {
        return [
            'interface は抽象型' => [TypeKind::Interface_, true],
            'abstract class は抽象型' => [TypeKind::AbstractClass, true],
            'concrete class は具象型' => [TypeKind::ConcreteClass, false],
            'enum は具象型' => [TypeKind::Enum_, false],
            'trait は具象型' => [TypeKind::Trait_, false],
        ];
    }
}

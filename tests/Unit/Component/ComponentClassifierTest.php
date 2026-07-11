<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Component;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\TypeKind;
use Bobsap\Component\Component;
use Bobsap\Component\ComponentClassifier;
use PHPUnit\Framework\TestCase;

// ComponentClassifier が depth 指定で名前空間をコンポーネントに束ねることのテスト。
// depth より「深い / ちょうど / 浅い」名前空間の境界と、グローバル名前空間の扱いを確認する。
final class ComponentClassifierTest extends TestCase
{
    public function testTruncatesDeeperNamespaceToDepth(): void
    {
        // App\Domain\Model\User の名前空間は App\Domain\Model（3階層）で depth=2 より深いので
        // 先頭 2 階層 App\Domain に切り詰められる
        $classInfos = [$this->classInfo('App\\Domain\\Model\\User')];

        $components = (new ComponentClassifier())->classify($classInfos, 2);

        self::assertCount(1, $components);
        self::assertSame('App\\Domain', $components[0]->name);
    }

    public function testKeepsNamespaceUnchangedWhenExactlyAtDepth(): void
    {
        // App\Bootstrap\Loader の名前空間は App\Bootstrap（ちょうど2階層）なのでそのまま
        $classInfos = [$this->classInfo('App\\Bootstrap\\Loader')];

        $components = (new ComponentClassifier())->classify($classInfos, 2);

        self::assertSame('App\\Bootstrap', $components[0]->name);
    }

    public function testKeepsWholeNamespaceWhenShallowerThanDepth(): void
    {
        // App\Kernel は App 直下（名前空間は App のみで1階層）。depth=2 より浅いので
        // 名前空間全体（App）がコンポーネント名になる
        $classInfos = [$this->classInfo('App\\Kernel')];

        $components = (new ComponentClassifier())->classify($classInfos, 2);

        self::assertSame('App', $components[0]->name);
    }

    public function testGroupsGlobalNamespaceClassesTogether(): void
    {
        $classInfos = [
            $this->classInfo('LegacyThing'),
            $this->classInfo('AnotherLegacyThing'),
        ];

        $components = (new ComponentClassifier())->classify($classInfos, 2);

        self::assertCount(1, $components);
        self::assertSame('(global)', $components[0]->name);
        self::assertCount(2, $components[0]->classInfos);
    }

    public function testGroupsMultipleClassesAndSortsResultByComponentName(): void
    {
        $classInfos = [
            $this->classInfo('App\\Infra\\Repo'),
            $this->classInfo('App\\Domain\\User'),
            $this->classInfo('App\\Domain\\Order'),
        ];

        $components = (new ComponentClassifier())->classify($classInfos, 2);

        self::assertSame(
            ['App\\Domain', 'App\\Infra'],
            array_map(static fn (Component $component): string => $component->name, $components),
        );
        self::assertCount(2, $components[0]->classInfos);
        self::assertCount(1, $components[1]->classInfos);
    }

    /**
     * @param list<string> $dependencies
     */
    private function classInfo(string $fqcn, TypeKind $kind = TypeKind::ConcreteClass, array $dependencies = []): ClassInfo
    {
        return new ClassInfo($fqcn, $kind, '/dummy/path.php', $dependencies);
    }
}

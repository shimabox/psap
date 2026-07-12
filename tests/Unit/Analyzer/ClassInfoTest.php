<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Analyzer;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\DependencyEvidence;
use Bobsap\Analyzer\DependencyKind;
use Bobsap\Analyzer\TypeKind;
use PHPUnit\Framework\TestCase;

// ClassInfo が値オブジェクトとして「依存先の重複排除」「自分自身の除外」を
// コンストラクタ内で保証することのテスト
final class ClassInfoTest extends TestCase
{
    public function testDependenciesAreDeduplicated(): void
    {
        $classInfo = new ClassInfo(
            fqcn: 'App\\Domain\\User',
            kind: TypeKind::ConcreteClass,
            filePath: '/path/to/User.php',
            dependencies: ['App\\Domain\\Address', 'App\\Domain\\Address', 'App\\Domain\\Email'],
        );

        self::assertSame(
            ['App\\Domain\\Address', 'App\\Domain\\Email'],
            $classInfo->dependencies,
        );
    }

    public function testSelfReferenceIsExcludedFromDependencies(): void
    {
        $classInfo = new ClassInfo(
            fqcn: 'App\\Domain\\User',
            kind: TypeKind::ConcreteClass,
            filePath: '/path/to/User.php',
            dependencies: ['App\\Domain\\User', 'App\\Domain\\Address'],
        );

        self::assertSame(['App\\Domain\\Address'], $classInfo->dependencies);
    }

    public function testSelfReferenceIsExcludedCaseInsensitively(): void
    {
        $classInfo = new ClassInfo(
            fqcn: 'App\\Domain\\User',
            kind: TypeKind::ConcreteClass,
            filePath: '/path/to/User.php',
            dependencies: ['APP\\DOMAIN\\USER', 'App\\Domain\\Address'],
        );

        self::assertSame(['App\\Domain\\Address'], $classInfo->dependencies);
    }

    public function testHoldsBasicProperties(): void
    {
        $classInfo = new ClassInfo(
            fqcn: 'App\\Domain\\User',
            kind: TypeKind::Interface_,
            filePath: '/path/to/User.php',
            dependencies: [],
        );

        self::assertSame('App\\Domain\\User', $classInfo->fqcn);
        self::assertSame(TypeKind::Interface_, $classInfo->kind);
        self::assertSame('/path/to/User.php', $classInfo->filePath);
        self::assertSame([], $classInfo->dependencies);
    }

    public function testEvidenceAddsDependencyAndIsDeduplicated(): void
    {
        $evidence = new DependencyEvidence(
            'App\\Infra\\Repository',
            DependencyKind::ParameterType,
            'src/Domain/User.php',
            12,
        );
        $classInfo = new ClassInfo(
            fqcn: 'App\\Domain\\User',
            kind: TypeKind::ConcreteClass,
            filePath: '/path/to/User.php',
            dependencies: [],
            dependencyEvidence: [$evidence, $evidence],
        );

        self::assertSame(['App\\Infra\\Repository'], $classInfo->dependencies);
        self::assertSame([$evidence], $classInfo->dependencyEvidence);
    }

    public function testSelfReferenceEvidenceIsExcluded(): void
    {
        $classInfo = new ClassInfo(
            fqcn: 'App\\Domain\\User',
            kind: TypeKind::ConcreteClass,
            filePath: '/path/to/User.php',
            dependencies: [],
            dependencyEvidence: [new DependencyEvidence(
                'APP\\DOMAIN\\USER',
                DependencyKind::ReturnType,
                'src/Domain/User.php',
                10,
            )],
        );

        self::assertSame([], $classInfo->dependencies);
        self::assertSame([], $classInfo->dependencyEvidence);
    }
}

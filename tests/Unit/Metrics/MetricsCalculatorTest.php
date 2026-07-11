<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Metrics;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\TypeKind;
use Bobsap\Component\Component;
use Bobsap\Metrics\MetricsCalculator;
use PHPUnit\Framework\TestCase;

// MetricsCalculator の Ca/Ce/I/A/D 計算のテスト。
// クラス→コンポーネント対応表を使い、対応表にないクラス（解析対象外）への依存を無視すること、
// Ca/Ce が依存エッジ数ではなく「クラス単位」でカウントされることを重点的に確認する。
final class MetricsCalculatorTest extends TestCase
{
    public function testCalculatesCaAndCePerClassNotPerDependencyEdge(): void
    {
        // コンポーネントA: X, Y, Z
        //   X は内部の Y への依存（無視される）と、外部 B\P への依存を持つ
        //   Y は依存なし
        //   Z は対応表にない外部ライブラリへの依存のみを持つ（無視される）
        // コンポーネントB: P（抽象）, Q
        //   P は A\X, A\Y の2つに依存する（エッジは2本だが外部クラスとしては P の1クラス）
        //   Q は A\X に依存する
        $x = $this->classInfo('App\\A\\X', dependencies: ['App\\A\\Y', 'App\\B\\P']);
        $y = $this->classInfo('App\\A\\Y');
        $z = $this->classInfo('App\\A\\Z', dependencies: ['Vendor\\ThirdParty\\Lib']);
        $p = $this->classInfo('App\\B\\P', kind: TypeKind::Interface_, dependencies: ['App\\A\\X', 'App\\A\\Y']);
        $q = $this->classInfo('App\\B\\Q', dependencies: ['App\\A\\X']);

        $componentA = new Component('App\\A', [$x, $y, $z]);
        $componentB = new Component('App\\B', [$p, $q]);

        $metrics = (new MetricsCalculator())->calculate([$componentA, $componentB]);

        self::assertCount(2, $metrics);
        [$metricsA, $metricsB] = $metrics;

        // Ce(A) = 1（X のみ。Z の依存は対応表にないため無視、Y は内部依存のみ）
        self::assertSame(1, $metricsA->ce);
        // Ca(A) = 2（外部クラス P, Q が A 内のクラスに依存している。P からのエッジは2本あるが1クラスとして数える）
        self::assertSame(2, $metricsA->ca);

        // Ce(B) = 2（P, Q ともに A への依存を持つ。P からのエッジは2本だが1クラスとして数える）
        self::assertSame(2, $metricsB->ce);
        // Ca(B) = 1（外部クラス X のみが B（P）に依存している）
        self::assertSame(1, $metricsB->ca);
    }

    public function testCalculatesInstabilityAbstractnessAndDistance(): void
    {
        $x = $this->classInfo('App\\A\\X', dependencies: ['App\\A\\Y', 'App\\B\\P']);
        $y = $this->classInfo('App\\A\\Y');
        $z = $this->classInfo('App\\A\\Z', dependencies: ['Vendor\\ThirdParty\\Lib']);
        $p = $this->classInfo('App\\B\\P', kind: TypeKind::Interface_, dependencies: ['App\\A\\X', 'App\\A\\Y']);
        $q = $this->classInfo('App\\B\\Q', dependencies: ['App\\A\\X']);

        $componentA = new Component('App\\A', [$x, $y, $z]);
        $componentB = new Component('App\\B', [$p, $q]);

        $metrics = (new MetricsCalculator())->calculate([$componentA, $componentB]);
        [$metricsA, $metricsB] = $metrics;

        // A: Ca=2, Ce=1 → I = 1/3、抽象型なし → A = 0/3 = 0、D = |0 + 1/3 - 1| = 2/3
        self::assertEqualsWithDelta(1 / 3, $metricsA->instability, 0.0001);
        self::assertEqualsWithDelta(0.0, $metricsA->abstractness, 0.0001);
        self::assertEqualsWithDelta(2 / 3, $metricsA->distance, 0.0001);

        // B: Ca=1, Ce=2 → I = 2/3、抽象型1/2 → A = 0.5、D = |0.5 + 2/3 - 1| = 1/6
        self::assertEqualsWithDelta(2 / 3, $metricsB->instability, 0.0001);
        self::assertEqualsWithDelta(0.5, $metricsB->abstractness, 0.0001);
        self::assertEqualsWithDelta(1 / 6, $metricsB->distance, 0.0001);
    }

    public function testZeroDistanceOnMainSequence(): void
    {
        // 主系列（A + I = 1）上にあるコンポーネントは D = 0 になることの確認
        // D: 抽象interface(Iface, 外部Eに依存) + 具象(Concrete, 依存なし)
        // E: W が D\Concrete に依存する（D への Ca を作る）
        $iface = $this->classInfo('App\\D\\Iface', kind: TypeKind::Interface_, dependencies: ['App\\E\\W']);
        $concrete = $this->classInfo('App\\D\\Concrete');
        $w = $this->classInfo('App\\E\\W', dependencies: ['App\\D\\Concrete']);

        $componentD = new Component('App\\D', [$iface, $concrete]);
        $componentE = new Component('App\\E', [$w]);

        $metrics = (new MetricsCalculator())->calculate([$componentD, $componentE]);
        $metricsD = $metrics[0];

        self::assertSame('App\\D', $metricsD->component->name);
        self::assertEqualsWithDelta(0.5, $metricsD->instability, 0.0001);
        self::assertEqualsWithDelta(0.5, $metricsD->abstractness, 0.0001);
        self::assertEqualsWithDelta(0.0, $metricsD->distance, 0.0001);
    }

    public function testInstabilityIsZeroForIsolatedComponentWithNoDependencies(): void
    {
        // Ca=0, Ce=0 の孤立コンポーネントはゼロ除算せず I=0 とする
        // （依存していないものは不安定になりようがないため）
        $lonely = $this->classInfo('App\\Isolated\\Lonely');
        $component = new Component('App\\Isolated', [$lonely]);

        $metrics = (new MetricsCalculator())->calculate([$component]);

        self::assertSame(0, $metrics[0]->ca);
        self::assertSame(0, $metrics[0]->ce);
        self::assertEqualsWithDelta(0.0, $metrics[0]->instability, 0.0001);
    }

    public function testAbstractnessIsZeroWhenComponentHasNoClasses(): void
    {
        // 型数0のコンポーネントは通常起こり得ないが、ゼロ除算を起こさないことをガードとして確認する
        $component = new Component('App\\Empty', []);

        $metrics = (new MetricsCalculator())->calculate([$component]);

        self::assertEqualsWithDelta(0.0, $metrics[0]->abstractness, 0.0001);
    }

    /**
     * @param list<string> $dependencies
     */
    private function classInfo(string $fqcn, TypeKind $kind = TypeKind::ConcreteClass, array $dependencies = []): ClassInfo
    {
        return new ClassInfo($fqcn, $kind, '/dummy/path.php', $dependencies);
    }
}

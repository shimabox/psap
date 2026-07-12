<?php

declare(strict_types=1);

namespace Bobsap\Metrics;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Component\Component;

/**
 * Component[] から Ca / Ce / I / A / D / ゾーンを計算する。
 */
final class MetricsCalculator
{
    /**
     * @param list<Component> $components
     * @return list<ComponentMetrics>
     */
    public function calculate(array $components): array
    {
        $componentNameByFqcn = $this->buildComponentNameMap($components);
        $dependencyMetricsEvaluable = count($components) > 1;

        return array_map(
            fn (Component $component): ComponentMetrics => $this->calculateFor(
                $component,
                $components,
                $componentNameByFqcn,
                $dependencyMetricsEvaluable,
            ),
            $components,
        );
    }

    /**
     * クラスの FQCN → 所属コンポーネント名 の対応表を作る。
     * この対応表に存在しない FQCN への依存は「解析対象外への依存」として無視する。
     *
     * @param list<Component> $components
     * @return array<string, string>
     */
    private function buildComponentNameMap(array $components): array
    {
        $map = [];
        foreach ($components as $component) {
            foreach ($component->classInfos as $classInfo) {
                $map[strtolower($classInfo->fqcn)] = $component->name;
            }
        }

        return $map;
    }

    /**
     * @param list<Component> $allComponents
     * @param array<string, string> $componentNameByFqcn
     */
    private function calculateFor(
        Component $component,
        array $allComponents,
        array $componentNameByFqcn,
        bool $dependencyMetricsEvaluable,
    ): ComponentMetrics {
        $ce = $this->countCe($component, $componentNameByFqcn);
        $ca = $this->countCa($component, $allComponents, $componentNameByFqcn);

        // I = Ce / (Ca + Ce)。依存が全くない孤立コンポーネントは
        // 依存していない以上「不安定になりようがない」ため 0 とする（ゼロ除算ガード）
        $instability = ($ca + $ce) === 0 ? 0.0 : $ce / ($ca + $ce);

        // A = 抽象型数 / 総型数。総型数0はコンポーネントが空を意味し実際には起こり得ないが、
        // ゼロ除算を避けるためガードしておく
        $totalTypeCount = count($component->classInfos);
        $abstractTypeCount = count(array_filter(
            $component->classInfos,
            static fn (ClassInfo $classInfo): bool => $classInfo->kind->isAbstract(),
        ));
        $abstractness = $totalTypeCount === 0 ? 0.0 : $abstractTypeCount / $totalTypeCount;

        $distance = abs($abstractness + $instability - 1.0);
        $zone = ($ca + $ce) === 0
            ? Zone::None
            : Zone::determine($instability, $abstractness);

        return new ComponentMetrics(
            component: $component,
            ca: $ca,
            ce: $ce,
            instability: $instability,
            abstractness: $abstractness,
            distance: $distance,
            zone: $zone,
            dependencyMetricsEvaluable: $dependencyMetricsEvaluable,
        );
    }

    /**
     * Ce（ファン・アウト）: コンポーネント内にあって、コンポーネント外のクラスに依存しているクラスの数。
     * 同じクラスが複数の外部クラスに依存していても1として数える（クラス単位のカウント）。
     *
     * @param array<string, string> $componentNameByFqcn
     */
    private function countCe(Component $component, array $componentNameByFqcn): int
    {
        $count = 0;
        foreach ($component->classInfos as $classInfo) {
            if ($this->dependsOnOutsideOf($classInfo, $component->name, $componentNameByFqcn)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Ca（ファン・イン）: コンポーネント外にあって、コンポーネント内のクラスに依存しているクラスの数。
     * 同じ外部クラスが内部の複数クラスに依存していても1として数える（クラス単位のカウント）。
     *
     * @param list<Component> $allComponents
     * @param array<string, string> $componentNameByFqcn
     */
    private function countCa(Component $component, array $allComponents, array $componentNameByFqcn): int
    {
        $count = 0;
        foreach ($allComponents as $otherComponent) {
            if ($otherComponent->name === $component->name) {
                continue;
            }

            foreach ($otherComponent->classInfos as $classInfo) {
                if ($this->dependsOnComponent($classInfo, $component->name, $componentNameByFqcn)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param array<string, string> $componentNameByFqcn
     */
    private function dependsOnOutsideOf(ClassInfo $classInfo, string $ownComponentName, array $componentNameByFqcn): bool
    {
        foreach ($classInfo->dependencies as $dependency) {
            $dependencyComponentName = $componentNameByFqcn[strtolower($dependency)] ?? null;
            if ($dependencyComponentName !== null && $dependencyComponentName !== $ownComponentName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $componentNameByFqcn
     */
    private function dependsOnComponent(ClassInfo $classInfo, string $targetComponentName, array $componentNameByFqcn): bool
    {
        foreach ($classInfo->dependencies as $dependency) {
            if (($componentNameByFqcn[strtolower($dependency)] ?? null) === $targetComponentName) {
                return true;
            }
        }

        return false;
    }
}

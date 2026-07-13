<?php

declare(strict_types=1);

namespace Psap\Component;

use Psap\Analyzer\ClassInfo;

/**
 * ClassInfo[] を名前空間の深さ（depth）でコンポーネントに分類する。
 */
final class ComponentClassifier
{
    /** グローバル名前空間のクラスをまとめるコンポーネント名 */
    private const string GLOBAL_COMPONENT_NAME = '(global)';

    /**
     * @param list<ClassInfo> $classInfos
     * @return list<Component> コンポーネント名でソート済み
     */
    public function classify(array $classInfos, int $depth): array
    {
        /** @var array<string, list<ClassInfo>> $groupedByName */
        $groupedByName = [];
        foreach ($classInfos as $classInfo) {
            $name = $this->componentName($classInfo->fqcn, $depth);
            $groupedByName[$name][] = $classInfo;
        }

        $components = [];
        foreach ($groupedByName as $name => $classes) {
            $components[] = new Component($name, $classes);
        }

        usort($components, static fn (Component $a, Component $b): int => $a->name <=> $b->name);

        return $components;
    }

    /**
     * FQCN からコンポーネント名を決める。
     *
     * - 名前空間が depth より深い場合: 先頭 depth 階層に切り詰める
     * - 名前空間が depth 以下の場合: 名前空間全体をそのままコンポーネント名にする
     * - グローバル名前空間（名前空間区切りを含まない FQCN）の場合: `(global)`
     */
    private function componentName(string $fqcn, int $depth): string
    {
        $separatorPosition = strrpos($fqcn, '\\');
        if ($separatorPosition === false) {
            return self::GLOBAL_COMPONENT_NAME;
        }

        $namespace = substr($fqcn, 0, $separatorPosition);
        $parts = explode('\\', $namespace);

        if (count($parts) <= $depth) {
            return $namespace;
        }

        return implode('\\', array_slice($parts, 0, $depth));
    }
}

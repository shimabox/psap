<?php

declare(strict_types=1);

namespace Psap\Component;

use Psap\Analyzer\ClassInfo;
use ReflectionClass;

/**
 * 解析対象外依存（FQCN→コンポーネント対応表に載っていない依存先）を集計する。
 *
 * MetricsCalculator / DependencyGraph が「対応表にない」という理由で捨てている依存を、
 * メトリクスとは独立した別枠として拾い直す。対応表にない FQCN には vendor のクラスのほか、
 * --exclude したクラス・解析対象パス外の自社コード・解析できなかったクラス・
 * 存在しない FQCN も含まれる（詳細は docs/analysis.md）。
 *
 * PHP 組み込みの型（Exception / DateTimeImmutable / Countable など）はノイズにしかならないため
 * 集計から除外する。判定では autoload を一切走らせない（psap 自身の vendor を
 * 誤って解決してしまう事故を防ぐため）。
 *
 * 返す集計はすべて決定的にソート済みで、表示件数の制限は行わない（Reporter の責務）。
 */
final class OutOfScopeDependencyCollector
{
    /** @var array<string, bool> FQCN（小文字）→ PHP 組み込み型かどうかの判定キャッシュ */
    private array $internalTypeCache = [];

    /**
     * @param list<Component> $components
     */
    public function collect(array $components): OutOfScopeDependencyReport
    {
        $inScopeFqcns = $this->buildInScopeMap($components);

        /** @var array<string, array{targets: array<string, string>, sources: array<string, string>, evidence: array<string, OutOfScopeDependencyEvidence>}> $projectGroups */
        $projectGroups = [];
        $componentReports = [];

        foreach ($components as $component) {
            /** @var array<string, array{targets: array<string, string>, sources: array<string, string>, evidence: array<string, OutOfScopeDependencyEvidence>}> $componentGroups */
            $componentGroups = [];

            foreach ($component->classInfos as $classInfo) {
                foreach ($classInfo->dependencies as $dependency) {
                    if (isset($inScopeFqcns[strtolower($dependency)])) {
                        continue;
                    }
                    if ($this->isInternalType($dependency)) {
                        continue;
                    }

                    $namespace = $this->groupName($dependency);
                    $evidence = $this->evidenceFor($classInfo, $dependency);
                    $this->accumulate($componentGroups, $namespace, $classInfo->fqcn, $dependency, $evidence);
                    $this->accumulate($projectGroups, $namespace, $classInfo->fqcn, $dependency, $evidence);
                }
            }

            if ($componentGroups === []) {
                continue;
            }

            $componentReports[] = new OutOfScopeDependencyComponent(
                $component->name,
                $this->finalizeGroups($componentGroups),
            );
        }

        usort(
            $componentReports,
            static fn (OutOfScopeDependencyComponent $a, OutOfScopeDependencyComponent $b): int
                => [$b->dependencyCount(), $a->name] <=> [$a->dependencyCount(), $b->name],
        );

        return new OutOfScopeDependencyReport($this->finalizeGroups($projectGroups), $componentReports);
    }

    /**
     * 解析対象のクラス FQCN（小文字）の集合を作る。
     * MetricsCalculator / DependencyGraph の対応表と同じ考え方。
     *
     * @param list<Component> $components
     * @return array<string, true>
     */
    private function buildInScopeMap(array $components): array
    {
        $map = [];
        foreach ($components as $component) {
            foreach ($component->classInfos as $classInfo) {
                $map[strtolower($classInfo->fqcn)] = true;
            }
        }

        return $map;
    }

    /**
     * ClassInfo::dependencies の1件に対応する証拠を取り出す。
     * ClassInfo は証拠のない依存も表現できるため、空になり得る。
     *
     * @return list<OutOfScopeDependencyEvidence>
     */
    private function evidenceFor(ClassInfo $classInfo, string $dependency): array
    {
        $evidence = [];
        foreach ($classInfo->dependencyEvidence as $item) {
            if (strcasecmp($item->targetFqcn, $dependency) !== 0) {
                continue;
            }

            $evidence[] = new OutOfScopeDependencyEvidence(
                sourceFqcn: $classInfo->fqcn,
                targetFqcn: $item->targetFqcn,
                kind: $item->kind,
                file: $item->file,
                line: $item->line,
            );
        }

        return $evidence;
    }

    /**
     * @param array<string, array{targets: array<string, string>, sources: array<string, string>, evidence: array<string, OutOfScopeDependencyEvidence>}> $groups
     * @param list<OutOfScopeDependencyEvidence> $evidence
     * @param-out array<string, array{targets: array<string, string>, sources: array<string, string>, evidence: array<string, OutOfScopeDependencyEvidence>}> $groups
     */
    private function accumulate(
        array &$groups,
        string $namespace,
        string $sourceFqcn,
        string $targetFqcn,
        array $evidence,
    ): void {
        $groups[$namespace] ??= ['targets' => [], 'sources' => [], 'evidence' => []];
        $groups[$namespace]['targets'][strtolower($targetFqcn)] ??= $targetFqcn;
        $groups[$namespace]['sources'][strtolower($sourceFqcn)] ??= $sourceFqcn;

        foreach ($evidence as $item) {
            $key = implode("\0", [
                strtolower($item->sourceFqcn),
                strtolower($item->targetFqcn),
                $item->kind->value,
                $item->file,
                (string) $item->line,
            ]);
            $groups[$namespace]['evidence'][$key] = $item;
        }
    }

    /**
     * @param array<string, array{targets: array<string, string>, sources: array<string, string>, evidence: array<string, OutOfScopeDependencyEvidence>}> $groups
     * @return list<OutOfScopeDependencyGroup>
     */
    private function finalizeGroups(array $groups): array
    {
        $finalized = [];
        foreach ($groups as $namespace => $group) {
            $targets = array_values($group['targets']);
            sort($targets);
            $sources = array_values($group['sources']);
            sort($sources);

            $evidence = array_values($group['evidence']);
            usort(
                $evidence,
                static fn (OutOfScopeDependencyEvidence $a, OutOfScopeDependencyEvidence $b): int => [
                    $a->targetFqcn,
                    $a->sourceFqcn,
                    $a->file,
                    $a->line,
                    $a->kind->value,
                ] <=> [
                    $b->targetFqcn,
                    $b->sourceFqcn,
                    $b->file,
                    $b->line,
                    $b->kind->value,
                ],
            );

            $finalized[] = new OutOfScopeDependencyGroup((string) $namespace, $targets, $sources, $evidence);
        }

        // 件数降順 → 名前昇順。同値でも順序が揺れないタイブレークを入れて決定的にする
        usort(
            $finalized,
            static fn (OutOfScopeDependencyGroup $a, OutOfScopeDependencyGroup $b): int
                => [$b->dependencyCount(), $a->namespace] <=> [$a->dependencyCount(), $b->namespace],
        );

        return $finalized;
    }

    /**
     * 依存先 FQCN を束ねる名前空間グループ名を決める。
     * 名前空間を持たないグローバルなクラスは `(global)` にまとめる。
     */
    private function groupName(string $fqcn): string
    {
        $separatorPosition = strrpos($fqcn, '\\');
        if ($separatorPosition === false || $separatorPosition === 0) {
            return OutOfScopeDependencyGroup::GLOBAL_NAMESPACE;
        }

        return substr($fqcn, 0, $separatorPosition);
    }

    /**
     * PHP 組み込みの型かどうかを判定する。
     *
     * autoload を抑止したうえで存在確認し、**存在した場合だけ** ReflectionClass を作る。
     * 読み込み済みの userland クラスは isInternal() === false なので除外されない。
     */
    private function isInternalType(string $fqcn): bool
    {
        $key = strtolower($fqcn);
        if (isset($this->internalTypeCache[$key])) {
            return $this->internalTypeCache[$key];
        }

        $exists = class_exists($fqcn, false)
            || interface_exists($fqcn, false)
            || trait_exists($fqcn, false)
            || enum_exists($fqcn, false);

        return $this->internalTypeCache[$key] = $exists && (new ReflectionClass($fqcn))->isInternal();
    }
}

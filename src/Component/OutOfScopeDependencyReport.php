<?php

declare(strict_types=1);

namespace Psap\Component;

/**
 * 解析対象外依存（FQCN→コンポーネント対応表に載っていない依存先）の集計結果。
 *
 * Ca / Ce / I / A / D はコンポーネント間の依存だけで計算するため、
 * 対応表にない依存先はメトリクスからは除かれる。ここではその捨てられている情報を
 * 別枠で集計して可視化する。メトリクスそのものには影響しない。
 *
 * `$groups` はプロジェクト全体の集計で、件数はコンポーネント別の単純合計ではなく
 * プロジェクト全体で distinct な FQCN 数になる（複数コンポーネントから
 * 同じ FQCN を参照していても1件と数える）。
 */
final readonly class OutOfScopeDependencyReport
{
    /**
     * @param list<OutOfScopeDependencyGroup> $groups プロジェクト全体の名前空間グループ（件数降順→名前昇順）
     * @param list<OutOfScopeDependencyComponent> $components コンポーネント別集計（件数降順→名前昇順）
     */
    public function __construct(
        public array $groups,
        public array $components,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /** プロジェクト全体で distinct な解析対象外クラス数 */
    public function dependencyCount(): int
    {
        return array_sum(array_map(
            static fn (OutOfScopeDependencyGroup $group): int => $group->dependencyCount(),
            $this->groups,
        ));
    }

    public function groupCount(): int
    {
        return count($this->groups);
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}

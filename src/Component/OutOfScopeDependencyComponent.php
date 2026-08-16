<?php

declare(strict_types=1);

namespace Psap\Component;

/**
 * 1コンポーネントぶんの解析対象外依存の集計。
 */
final readonly class OutOfScopeDependencyComponent
{
    /**
     * @param string $name コンポーネント名
     * @param list<OutOfScopeDependencyGroup> $groups 名前空間グループ（件数降順→名前昇順）
     */
    public function __construct(
        public string $name,
        public array $groups,
    ) {
    }

    /**
     * このコンポーネントから参照している解析対象外クラス数（distinct FQCN）。
     * グループは名前空間で分割されており FQCN が重複しないため単純合計でよい。
     */
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
}

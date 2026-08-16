<?php

declare(strict_types=1);

namespace Psap\Component;

use JsonSerializable;

/**
 * 1コンポーネントぶんの解析対象外依存の集計。
 */
final readonly class OutOfScopeDependencyComponent implements JsonSerializable
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

    /**
     * コンポーネント別の詳細形式。名前空間グループは参照元と証拠を含む詳細形式で持つ。
     *
     * @return array{
     *     name: string,
     *     dependencyCount: int,
     *     groupCount: int,
     *     groups: list<array{
     *         namespace: string,
     *         dependencyCount: int,
     *         sourceCount: int,
     *         targets: list<string>,
     *         sources: list<string>,
     *         evidence: list<array{sourceFqcn: string, targetFqcn: string, kind: string, file: string, line: int}>,
     *     }>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'dependencyCount' => $this->dependencyCount(),
            'groupCount' => $this->groupCount(),
            'groups' => array_map(
                static fn (OutOfScopeDependencyGroup $group): array => $group->jsonSerialize(),
                $this->groups,
            ),
        ];
    }
}

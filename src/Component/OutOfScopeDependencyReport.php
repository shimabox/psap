<?php

declare(strict_types=1);

namespace Psap\Component;

use JsonSerializable;

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
final readonly class OutOfScopeDependencyReport implements JsonSerializable
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

    /**
     * json / html レポートが共有するシリアライズ形式。
     *
     * スキーマは意図的に非対称にしてある。トップレベルの `groups` は
     * プロジェクト全体のサマリで、参照元 (`sources`) と証拠 (`evidence`) を持たない。
     * 証拠の全量は `components[].groups[]`（詳細形式）にだけ入る。
     * 0 件でもキーを落とさず、`groups` / `components` は空配列を出す。
     *
     * `dependencyCount` はプロジェクト全体で distinct な FQCN 数であり、
     * `components[].dependencyCount` の単純合計とは一致しない（同じ FQCN を
     * 複数コンポーネントから参照していても全体では1件と数えるため）。
     *
     * @return array{
     *     dependencyCount: int,
     *     groupCount: int,
     *     groups: list<array{namespace: string, dependencyCount: int, sourceCount: int, targets: list<string>}>,
     *     components: list<array{
     *         name: string,
     *         dependencyCount: int,
     *         groupCount: int,
     *         groups: list<array{
     *             namespace: string,
     *             dependencyCount: int,
     *             sourceCount: int,
     *             targets: list<string>,
     *             sources: list<string>,
     *             evidence: list<array{sourceFqcn: string, targetFqcn: string, kind: string, file: string, line: int}>,
     *         }>,
     *     }>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'dependencyCount' => $this->dependencyCount(),
            'groupCount' => $this->groupCount(),
            // トップレベルはサマリ形式へ明示変換する（Group::jsonSerialize() は詳細形式）
            'groups' => array_map(
                static fn (OutOfScopeDependencyGroup $group): array => [
                    'namespace' => $group->namespace,
                    'dependencyCount' => $group->dependencyCount(),
                    'sourceCount' => $group->sourceCount(),
                    'targets' => $group->targetFqcns,
                ],
                $this->groups,
            ),
            'components' => array_map(
                static fn (OutOfScopeDependencyComponent $component): array => $component->jsonSerialize(),
                $this->components,
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Psap\Component;

use JsonSerializable;

/**
 * 解析対象外依存を依存先の名前空間で束ねた集計単位。
 *
 * 名前空間はクラス名を除いた部分（例 `Illuminate\Support\Facades`）で、
 * 名前空間を持たないグローバルなクラスは `(global)` にまとめる。
 */
final readonly class OutOfScopeDependencyGroup implements JsonSerializable
{
    /** 名前空間を持たない解析対象外 FQCN をまとめるグループ名 */
    public const string GLOBAL_NAMESPACE = '(global)';

    /**
     * @param string $namespace 依存先の名前空間、またはグローバルの場合は `(global)`
     * @param list<string> $targetFqcns 依存先 FQCN 一覧（重複なし・昇順）
     * @param list<string> $sourceFqcns 参照元 FQCN 一覧（重複なし・昇順）
     * @param list<OutOfScopeDependencyEvidence> $evidence 証拠一覧（決定的にソート済み・証拠なしの依存では空になり得る）
     */
    public function __construct(
        public string $namespace,
        public array $targetFqcns,
        public array $sourceFqcns,
        public array $evidence,
    ) {
    }

    /** 依存先クラス数（distinct FQCN） */
    public function dependencyCount(): int
    {
        return count($this->targetFqcns);
    }

    /** 参照元クラス数（distinct FQCN） */
    public function sourceCount(): int
    {
        return count($this->sourceFqcns);
    }

    /**
     * 詳細形式（参照元と証拠を含む）。
     *
     * プロジェクト全体のグループはサマリ形式で出すため、この形式は
     * コンポーネント別集計からのみ使う（{@see OutOfScopeDependencyReport::jsonSerialize()}）。
     *
     * @return array{
     *     namespace: string,
     *     dependencyCount: int,
     *     sourceCount: int,
     *     targets: list<string>,
     *     sources: list<string>,
     *     evidence: list<array{sourceFqcn: string, targetFqcn: string, kind: string, file: string, line: int}>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'namespace' => $this->namespace,
            'dependencyCount' => $this->dependencyCount(),
            'sourceCount' => $this->sourceCount(),
            'targets' => $this->targetFqcns,
            'sources' => $this->sourceFqcns,
            'evidence' => array_map(
                static fn (OutOfScopeDependencyEvidence $evidence): array => $evidence->jsonSerialize(),
                $this->evidence,
            ),
        ];
    }
}

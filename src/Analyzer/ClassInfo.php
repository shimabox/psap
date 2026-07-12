<?php

declare(strict_types=1);

namespace Bobsap\Analyzer;

/**
 * 1つの型宣言（class / interface / enum / trait）の情報を表す値オブジェクト。
 *
 * 依存先 FQCN 一覧はコンストラクタ内で重複排除・自分自身の除外を行い、
 * 呼び出し側の実装によらず不変条件（重複なし・自己参照なし）を保証する。
 */
final readonly class ClassInfo
{
    /** @var list<string> 依存先 FQCN 一覧（重複なし・自分自身を含まない） */
    public array $dependencies;

    /** @var list<DependencyEvidence> 依存の構文種別とソース位置 */
    public array $dependencyEvidence;

    /**
     * @param string $fqcn 完全修飾クラス名（先頭の `\` なし）
     * @param string $filePath 定義元ファイルの絶対パス
     * @param list<string> $dependencies 依存先 FQCN 一覧（重複・自己参照が含まれていてもよい）
     * @param list<DependencyEvidence> $dependencyEvidence
     */
    public function __construct(
        public string $fqcn,
        public TypeKind $kind,
        public string $filePath,
        array $dependencies,
        array $dependencyEvidence = [],
    ) {
        $evidenceByKey = [];
        foreach ($dependencyEvidence as $evidence) {
            if (strcasecmp($evidence->targetFqcn, $this->fqcn) === 0) {
                continue;
            }
            $key = implode("\0", [
                strtolower($evidence->targetFqcn),
                $evidence->kind->value,
                $evidence->file,
                (string) $evidence->line,
            ]);
            $evidenceByKey[$key] = $evidence;
        }
        $this->dependencyEvidence = array_values($evidenceByKey);

        $this->dependencies = [...$dependencies, ...array_map(
            static fn (DependencyEvidence $evidence): string => $evidence->targetFqcn,
            $this->dependencyEvidence,
        )]
            |> (fn (array $items): array => array_filter(
                $items,
                fn (string $dependency): bool => strcasecmp($dependency, $this->fqcn) !== 0,
            ))
            |> array_unique(...)
            |> array_values(...);
    }
}

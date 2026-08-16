<?php

declare(strict_types=1);

namespace Psap\Component;

use JsonSerializable;
use Psap\Analyzer\DependencyKind;

/**
 * 解析対象外依存が発生した1箇所を表す値オブジェクト。
 *
 * 依存グラフの edgeDetails と同じ粒度（構文種別とソース位置）に、
 * どのクラスからどの解析対象外 FQCN への依存かを添えたもの。
 */
final readonly class OutOfScopeDependencyEvidence implements JsonSerializable
{
    public function __construct(
        public string $sourceFqcn,
        public string $targetFqcn,
        public DependencyKind $kind,
        public string $file,
        public int $line,
    ) {
    }

    /**
     * 証拠形式。json / html レポートが共通で使う。
     *
     * @return array{sourceFqcn: string, targetFqcn: string, kind: string, file: string, line: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'sourceFqcn' => $this->sourceFqcn,
            'targetFqcn' => $this->targetFqcn,
            'kind' => $this->kind->value,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }
}

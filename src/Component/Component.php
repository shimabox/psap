<?php

declare(strict_types=1);

namespace Psap\Component;

use Psap\Analyzer\ClassInfo;

/**
 * コンポーネント（名前空間で束ねたクラス群）を表す値オブジェクト。
 */
final readonly class Component
{
    /**
     * @param string $name コンポーネント名（束ねた名前空間、またはグローバル名前空間の場合は `(global)`）
     * @param list<ClassInfo> $classInfos このコンポーネントに属するクラス一覧
     */
    public function __construct(
        public string $name,
        public array $classInfos,
    ) {
    }
}

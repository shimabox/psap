<?php

declare(strict_types=1);

namespace Psap\Baseline;

final readonly class CycleBaselineComparison
{
    /**
     * @param list<list<string>> $newCycles
     * @param list<list<string>> $resolvedCycles
     */
    public function __construct(
        public array $newCycles,
        public array $resolvedCycles,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->newCycles !== [] || $this->resolvedCycles !== [];
    }
}

<?php

declare(strict_types=1);

namespace Psap\Analyzer;

/**
 * PHPファイルの発見から解析完了までの会計を表す値オブジェクト。
 */
final readonly class AnalysisCoverage
{
    public function __construct(
        public int $discovered,
        public int $selected,
        public int $analyzed,
        public int $excluded,
        public int $skipped,
    ) {
        foreach ([
            'discovered' => $this->discovered,
            'selected' => $this->selected,
            'analyzed' => $this->analyzed,
            'excluded' => $this->excluded,
            'skipped' => $this->skipped,
        ] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(sprintf('%s must be a non-negative integer.', $name));
            }
        }

        if ($this->selected !== $this->discovered - $this->excluded) {
            throw new \InvalidArgumentException('selected must equal discovered - excluded.');
        }

        if ($this->selected !== $this->analyzed + $this->skipped) {
            throw new \InvalidArgumentException('selected must equal analyzed + skipped.');
        }
    }

    public function ratio(): ?float
    {
        if ($this->selected === 0) {
            return null;
        }

        return $this->analyzed / $this->selected;
    }
}

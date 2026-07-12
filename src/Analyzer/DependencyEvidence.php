<?php

declare(strict_types=1);

namespace Bobsap\Analyzer;

final readonly class DependencyEvidence
{
    public function __construct(
        public string $targetFqcn,
        public DependencyKind $kind,
        public string $file,
        public int $line,
    ) {
    }
}

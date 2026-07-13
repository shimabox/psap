<?php

declare(strict_types=1);

namespace Psap\Analyzer\Internal;

use Psap\Analyzer\DependencyKind;

final readonly class DependencyReference
{
    public function __construct(
        public string $fqcn,
        public DependencyKind $kind,
        public int $line,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Bobsap\Analyzer\Internal;

use Bobsap\Analyzer\DependencyKind;

final readonly class DependencyReference
{
    public function __construct(
        public string $fqcn,
        public DependencyKind $kind,
        public int $line,
    ) {
    }
}

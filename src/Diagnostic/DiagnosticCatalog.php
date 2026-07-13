<?php

declare(strict_types=1);

namespace Psap\Diagnostic;

interface DiagnosticCatalog
{
    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    public function message(DiagnosticCode $code, array $context): string;

    public function action(DiagnosticAction $action): string;
}

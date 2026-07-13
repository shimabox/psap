<?php

declare(strict_types=1);

namespace Psap\Diagnostic;

enum DiagnosticSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}

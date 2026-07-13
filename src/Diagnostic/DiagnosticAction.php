<?php

declare(strict_types=1);

namespace Psap\Diagnostic;

enum DiagnosticAction: string
{
    case CheckPermissions = 'check_permissions';
    case ExcludeFile = 'exclude_file';
    case ConvertToUtf8 = 'convert_to_utf8';
    case FixSource = 'fix_source';
    case ReviewDuplicate = 'review_duplicate';
    case ReviewSourcePaths = 'review_source_paths';
    case IncreaseDepth = 'increase_depth';
    case ReviewComponentBoundary = 'review_component_boundary';
}

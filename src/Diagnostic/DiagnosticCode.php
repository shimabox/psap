<?php

declare(strict_types=1);

namespace Psap\Diagnostic;

enum DiagnosticCode: string
{
    case SourceReadFailed = 'source.read_failed';
    case SourceInvalidUtf8 = 'source.invalid_utf8';
    case SourceParseFailed = 'source.parse_failed';
    case SourceNameResolutionFailed = 'source.name_resolution_failed';
    case DeclarationDuplicateFqcn = 'declaration.duplicate_fqcn';
    case DeclarationKindConflict = 'declaration.kind_conflict';
    case AnalysisNoTypes = 'analysis.no_types';
    case AnalysisSingleComponentDepth = 'analysis.single_component_depth';
    case AnalysisSingleComponentUnevaluable = 'analysis.single_component_unevaluable';
}

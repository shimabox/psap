<?php

declare(strict_types=1);

namespace Psap\Diagnostic;

final class EnglishDiagnosticCatalog implements DiagnosticCatalog
{
    public function message(DiagnosticCode $code, array $context): string
    {
        return match ($code) {
            DiagnosticCode::SourceReadFailed => 'Source file could not be read and was skipped.',
            DiagnosticCode::SourceInvalidUtf8 => 'Source file is not valid UTF-8 and was skipped.',
            DiagnosticCode::SourceParseFailed => $this->withDetail('Source file could not be parsed and was skipped.', $context),
            DiagnosticCode::SourceNameResolutionFailed => $this->withDetail('Names in the source file could not be resolved and it was skipped.', $context),
            DiagnosticCode::DeclarationDuplicateFqcn => sprintf('Declarations with the same FQCN were merged: %s.', $this->context($context, 'fqcn')),
            DiagnosticCode::DeclarationKindConflict => sprintf('Declarations with the same FQCN use different type kinds: %s.', $this->context($context, 'fqcn')),
            DiagnosticCode::AnalysisNoTypes => 'No analyzable classes, interfaces, traits, or enums were found.',
            DiagnosticCode::AnalysisSingleComponentDepth => 'All types were grouped into one component at the current namespace depth.',
            DiagnosticCode::AnalysisSingleComponentUnevaluable => 'Only one component was found, so inter-component Ca, Ce, I, D, and cycles cannot be evaluated.',
        };
    }

    public function action(DiagnosticAction $action): string
    {
        return match ($action) {
            DiagnosticAction::CheckPermissions => 'Check the file path and read permissions.',
            DiagnosticAction::ExcludeFile => 'Exclude the file with --exclude if it is outside the intended analysis scope.',
            DiagnosticAction::ConvertToUtf8 => 'Convert the file to UTF-8.',
            DiagnosticAction::FixSource => 'Fix the PHP source code.',
            DiagnosticAction::ReviewDuplicate => 'Review the duplicate declarations and keep a single compatible definition.',
            DiagnosticAction::ReviewSourcePaths => 'Review the source paths and exclusion patterns.',
            DiagnosticAction::IncreaseDepth => 'Increase --depth to inspect finer component boundaries.',
            DiagnosticAction::ReviewComponentBoundary => 'Review the selected source scope and component boundary.',
        };
    }

    /** @param array<string, bool|float|int|string|null> $context */
    private function withDetail(string $message, array $context): string
    {
        $detail = $context['detail'] ?? null;

        return is_string($detail) && $detail !== '' ? sprintf('%s %s', $message, $detail) : $message;
    }

    /** @param array<string, bool|float|int|string|null> $context */
    private function context(array $context, string $key): string
    {
        $value = $context[$key] ?? null;

        return is_scalar($value) ? (string) $value : 'unknown';
    }
}

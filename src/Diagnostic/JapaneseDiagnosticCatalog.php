<?php

declare(strict_types=1);

namespace Psap\Diagnostic;

final class JapaneseDiagnosticCatalog implements DiagnosticCatalog
{
    public function message(DiagnosticCode $code, array $context): string
    {
        return match ($code) {
            DiagnosticCode::SourceReadFailed => 'ファイルを読み込めないためスキップしました。',
            DiagnosticCode::SourceInvalidUtf8 => 'UTF-8として解釈できないためスキップしました。',
            DiagnosticCode::SourceParseFailed => $this->withDetail('パースエラーのためスキップしました。', $context),
            DiagnosticCode::SourceNameResolutionFailed => $this->withDetail('名前解決エラーのためスキップしました。', $context),
            DiagnosticCode::DeclarationDuplicateFqcn => sprintf('複数ファイルに同じFQCNの宣言があるため統合しました: %s。', $this->context($context, 'fqcn')),
            DiagnosticCode::DeclarationKindConflict => sprintf('同じFQCNに異なる型種別の宣言があります: %s。', $this->context($context, 'fqcn')),
            DiagnosticCode::AnalysisNoTypes => '解析可能なクラス、インターフェース、トレイト、enumが見つかりませんでした。',
            DiagnosticCode::AnalysisSingleComponentDepth => 'コンポーネントが1件のみです。',
            DiagnosticCode::AnalysisSingleComponentUnevaluable => 'コンポーネントが1件のみのため、コンポーネント間のCa、Ce、I、D、循環依存は評価できません。',
        };
    }

    public function action(DiagnosticAction $action): string
    {
        return match ($action) {
            DiagnosticAction::CheckPermissions => 'ファイルのパスと読み取り権限を確認してください。',
            DiagnosticAction::ExcludeFile => '解析対象外なら--excludeでファイルを除外してください。',
            DiagnosticAction::ConvertToUtf8 => 'ファイルをUTF-8へ変換してください。',
            DiagnosticAction::FixSource => 'PHPソースコードを修正してください。',
            DiagnosticAction::ReviewDuplicate => '重複した宣言を確認し、互換性のある定義を1つにしてください。',
            DiagnosticAction::ReviewSourcePaths => 'ソースパスと除外パターンを確認してください。',
            DiagnosticAction::IncreaseDepth => 'より細かく分析する場合は --depth を増やしてください。',
            DiagnosticAction::ReviewComponentBoundary => '解析対象とコンポーネント境界を確認してください。',
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

        return is_scalar($value) ? (string) $value : '不明';
    }
}

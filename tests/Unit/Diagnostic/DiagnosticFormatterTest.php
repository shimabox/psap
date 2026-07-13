<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Diagnostic;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psap\Diagnostic\Diagnostic;
use Psap\Diagnostic\DiagnosticAction;
use Psap\Diagnostic\DiagnosticCode;
use Psap\Diagnostic\DiagnosticFormatter;
use Psap\Diagnostic\DiagnosticSeverity;

final class DiagnosticFormatterTest extends TestCase
{
    public function testFormatsTheSameDiagnosticInEnglishAndJapanese(): void
    {
        $diagnostic = new Diagnostic(
            code: DiagnosticCode::SourceInvalidUtf8,
            severity: DiagnosticSeverity::Warning,
            file: 'Component/Value.php',
            line: 19,
            actions: [DiagnosticAction::ConvertToUtf8, DiagnosticAction::ExcludeFile],
        );

        $english = new DiagnosticFormatter('en');
        $japanese = new DiagnosticFormatter('ja');

        self::assertSame('Source file is not valid UTF-8 and was skipped.', $english->message($diagnostic));
        self::assertSame('UTF-8として解釈できないためスキップしました。', $japanese->message($diagnostic));
        self::assertSame('Component/Value.php:19', $english->location($diagnostic));
        self::assertStringContainsString('Action: Convert the file to UTF-8.', $english->format($diagnostic));
        self::assertStringContainsString('対処: ファイルをUTF-8へ変換してください。', $japanese->format($diagnostic));
    }

    #[DataProvider('messageProvider')]
    public function testCatalogsCoverEveryDiagnosticCode(DiagnosticCode $code, string $english, string $japanese): void
    {
        $diagnostic = new Diagnostic(
            code: $code,
            severity: DiagnosticSeverity::Warning,
            context: ['fqcn' => 'App\\Example', 'detail' => 'detail'],
        );

        self::assertStringContainsString($english, (new DiagnosticFormatter('en'))->message($diagnostic));
        self::assertStringContainsString($japanese, (new DiagnosticFormatter('ja'))->message($diagnostic));
    }

    /** @return iterable<string, array{DiagnosticCode, string, string}> */
    public static function messageProvider(): iterable
    {
        yield 'read failure' => [DiagnosticCode::SourceReadFailed, 'could not be read', '読み込めない'];
        yield 'invalid UTF-8' => [DiagnosticCode::SourceInvalidUtf8, 'not valid UTF-8', 'UTF-8として解釈できない'];
        yield 'parse failure' => [DiagnosticCode::SourceParseFailed, 'could not be parsed', 'パースエラー'];
        yield 'name resolution failure' => [DiagnosticCode::SourceNameResolutionFailed, 'could not be resolved', '名前解決エラー'];
        yield 'duplicate FQCN' => [DiagnosticCode::DeclarationDuplicateFqcn, 'were merged', '統合しました'];
        yield 'kind conflict' => [DiagnosticCode::DeclarationKindConflict, 'different type kinds', '異なる型種別'];
        yield 'no types' => [DiagnosticCode::AnalysisNoTypes, 'No analyzable', '解析可能'];
        yield 'single component depth' => [DiagnosticCode::AnalysisSingleComponentDepth, 'one component', 'コンポーネントが1件'];
        yield 'single unevaluable component' => [DiagnosticCode::AnalysisSingleComponentUnevaluable, 'cannot be evaluated', '評価できません'];
    }

    public function testRejectsUnsupportedLocale(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DiagnosticFormatter('fr');
    }
}

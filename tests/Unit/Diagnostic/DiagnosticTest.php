<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Diagnostic;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psap\Diagnostic\Diagnostic;
use Psap\Diagnostic\DiagnosticAction;
use Psap\Diagnostic\DiagnosticCode;
use Psap\Diagnostic\DiagnosticSeverity;

final class DiagnosticTest extends TestCase
{
    public function testKeepsLanguageNeutralDiagnosticData(): void
    {
        $diagnostic = new Diagnostic(
            code: DiagnosticCode::SourceInvalidUtf8,
            severity: DiagnosticSeverity::Warning,
            file: 'Component/Cache/Value.php',
            line: 19,
            context: ['encoding' => 'Latin-1'],
            actions: [DiagnosticAction::ConvertToUtf8, DiagnosticAction::ExcludeFile],
        );

        self::assertSame('source.invalid_utf8', $diagnostic->code->value);
        self::assertSame('warning', $diagnostic->severity->value);
        self::assertSame('Component/Cache/Value.php', $diagnostic->file);
        self::assertSame(19, $diagnostic->line);
        self::assertSame(['encoding' => 'Latin-1'], $diagnostic->context);
        self::assertSame(
            [DiagnosticAction::ConvertToUtf8, DiagnosticAction::ExcludeFile],
            $diagnostic->actions,
        );
    }

    /** @param callable(): Diagnostic $factory */
    #[DataProvider('invalidDiagnosticProvider')]
    public function testRejectsInvalidDiagnosticData(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $factory();
    }

    /** @return iterable<string, array{callable(): Diagnostic}> */
    public static function invalidDiagnosticProvider(): iterable
    {
        yield 'empty file' => [static fn (): Diagnostic => new Diagnostic(
            DiagnosticCode::SourceReadFailed,
            DiagnosticSeverity::Warning,
            file: '',
        )];
        yield 'line below one' => [static fn (): Diagnostic => new Diagnostic(
            DiagnosticCode::SourceReadFailed,
            DiagnosticSeverity::Warning,
            file: 'file.php',
            line: 0,
        )];
        yield 'line without file' => [static fn (): Diagnostic => new Diagnostic(
            DiagnosticCode::SourceReadFailed,
            DiagnosticSeverity::Warning,
            line: 1,
        )];
        yield 'empty context key' => [static fn (): Diagnostic => new Diagnostic(
            DiagnosticCode::SourceReadFailed,
            DiagnosticSeverity::Warning,
            context: ['' => 'value'],
        )];
        yield 'non-scalar context value' => [static fn (): Diagnostic => new Diagnostic(
            DiagnosticCode::SourceReadFailed,
            DiagnosticSeverity::Warning,
            context: ['items' => []],
        )];
        yield 'duplicate action' => [static fn (): Diagnostic => new Diagnostic(
            DiagnosticCode::SourceReadFailed,
            DiagnosticSeverity::Warning,
            actions: [DiagnosticAction::ExcludeFile, DiagnosticAction::ExcludeFile],
        )];
    }
}

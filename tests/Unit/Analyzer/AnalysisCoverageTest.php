<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Analyzer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psap\Analyzer\AnalysisCoverage;

final class AnalysisCoverageTest extends TestCase
{
    public function testKeepsValidFileAccountingAndCalculatesRatio(): void
    {
        $coverage = new AnalysisCoverage(
            discovered: 10,
            selected: 7,
            analyzed: 6,
            excluded: 3,
            skipped: 1,
        );

        self::assertSame(10, $coverage->discovered);
        self::assertSame(7, $coverage->selected);
        self::assertSame(6, $coverage->analyzed);
        self::assertSame(3, $coverage->excluded);
        self::assertSame(1, $coverage->skipped);
        self::assertEqualsWithDelta(6 / 7, $coverage->ratio(), 0.000001);
    }

    public function testRatioIsNullWhenNoFilesAreSelected(): void
    {
        $coverage = new AnalysisCoverage(
            discovered: 3,
            selected: 0,
            analyzed: 0,
            excluded: 3,
            skipped: 0,
        );

        self::assertNull($coverage->ratio());
    }

    /**
     * @param array{discovered: int, selected: int, analyzed: int, excluded: int, skipped: int} $values
     */
    #[DataProvider('invalidCoverageProvider')]
    public function testRejectsInvalidFileAccounting(array $values): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AnalysisCoverage(...$values);
    }

    /**
     * @return array<string, array{array{discovered: int, selected: int, analyzed: int, excluded: int, skipped: int}}>
     */
    public static function invalidCoverageProvider(): array
    {
        return [
            'negative value' => [[
                'discovered' => -1,
                'selected' => 0,
                'analyzed' => 0,
                'excluded' => 0,
                'skipped' => 0,
            ]],
            'selected does not match discovered minus excluded' => [[
                'discovered' => 10,
                'selected' => 8,
                'analyzed' => 8,
                'excluded' => 3,
                'skipped' => 0,
            ]],
            'selected does not match analyzed plus skipped' => [[
                'discovered' => 10,
                'selected' => 7,
                'analyzed' => 5,
                'excluded' => 3,
                'skipped' => 1,
            ]],
        ];
    }
}

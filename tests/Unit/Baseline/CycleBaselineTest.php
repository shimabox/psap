<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Baseline;

use Bobsap\Baseline\CycleBaseline;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CycleBaselineTest extends TestCase
{
    public function testNormalizesAndRoundTripsJson(): void
    {
        $baseline = CycleBaseline::create(
            namespaceDepth: 3,
            docblockEnabled: true,
            excludePatterns: ['Tests/*', 'Generated/*', 'Tests/*'],
            cycles: [['App\\B', 'App\\A'], ['App\\D', 'App\\C']],
        );

        $decoded = CycleBaseline::fromJson($baseline->toJson());

        self::assertSame(3, $decoded->namespaceDepth);
        self::assertTrue($decoded->docblockEnabled);
        self::assertSame(['Generated/*', 'Tests/*'], $decoded->excludePatterns);
        self::assertSame([['App\\A', 'App\\B'], ['App\\C', 'App\\D']], $decoded->cycles);
    }

    public function testComparesNewAndResolvedCycles(): void
    {
        $baseline = CycleBaseline::create(2, true, [], [
            ['App\\A', 'App\\B'],
            ['App\\C', 'App\\D'],
        ]);

        $comparison = $baseline->compare([
            ['App\\B', 'App\\A'],
            ['App\\E', 'App\\F'],
        ]);

        self::assertSame([['App\\E', 'App\\F']], $comparison->newCycles);
        self::assertSame([['App\\C', 'App\\D']], $comparison->resolvedCycles);
        self::assertTrue($comparison->hasChanges());
    }

    public function testReportsNoChangesForEquivalentCyclesInDifferentOrder(): void
    {
        $baseline = CycleBaseline::create(2, true, [], [['App\\A', 'App\\B']]);

        $comparison = $baseline->compare([['App\\B', 'App\\A']]);

        self::assertSame([], $comparison->newCycles);
        self::assertSame([], $comparison->resolvedCycles);
        self::assertFalse($comparison->hasChanges());
    }

    public function testRejectsIncompatibleAnalysisSettings(): void
    {
        $baseline = CycleBaseline::create(2, true, ['Tests/*'], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('名前空間深度が一致しません');

        $baseline->assertCompatible(3, true, ['Tests/*']);
    }

    public function testRejectsIncompatibleDocblockSetting(): void
    {
        $baseline = CycleBaseline::create(2, true, [], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('docblock設定が一致しません');

        $baseline->assertCompatible(2, false, []);
    }

    public function testRejectsIncompatibleExcludePatterns(): void
    {
        $baseline = CycleBaseline::create(2, true, ['Tests/*'], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('除外パターンが一致しません');

        $baseline->assertCompatible(2, true, []);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不正なJSON');

        CycleBaseline::fromJson('{');
    }

    public function testRejectsCycleWithoutTwoDistinctMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('内容が不正です');

        CycleBaseline::fromJson(json_encode([
            'schemaVersion' => 1,
            'namespaceDepth' => 2,
            'docblockEnabled' => true,
            'excludePatterns' => [],
            'cycles' => [['App\\A', 'App\\A']],
        ], JSON_THROW_ON_ERROR));
    }
}

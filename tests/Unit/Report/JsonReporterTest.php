<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Report;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\TypeKind;
use Bobsap\Component\Component;
use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\MetricsSummary;
use Bobsap\Metrics\Zone;
use Bobsap\Report\JsonReporter;
use Bobsap\Report\ReportData;
use PHPUnit\Framework\TestCase;

// JsonReporter: JSON スキーマ（summary/components/warnings）を固定するテスト
final class JsonReporterTest extends TestCase
{
    public function testEncodesSummary(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
            $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 0.1, distance: 0.0, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame(2, $decoded['summary']['componentCount']);
        self::assertEqualsWithDelta(0.025, $decoded['summary']['meanDistance'], 0.0001);
        self::assertArrayHasKey('varianceDistance', $decoded['summary']);
    }

    public function testEncodesComponentFieldsAndZoneAsNullWhenNoZone(): void
    {
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $decoded = $this->decode((new JsonReporter())->render($data));
        $component = $decoded['components'][0];

        self::assertSame('App\\Domain', $component['name']);
        self::assertSame(0, $component['classCount']);
        self::assertSame(8, $component['ca']);
        self::assertSame(2, $component['ce']);
        self::assertEqualsWithDelta(0.2, $component['instability'], 0.0001);
        self::assertEqualsWithDelta(0.75, $component['abstractness'], 0.0001);
        self::assertEqualsWithDelta(0.05, $component['distance'], 0.0001);
        self::assertNull($component['zone']);
    }

    public function testEncodesZoneAsPainOrUseless(): void
    {
        $pain = $this->metrics('App\\Legacy', ca: 6, ce: 1, instability: 0.14, abstractness: 0.0, distance: 0.86, zone: Zone::Pain);
        $useless = $this->metrics('App\\Infra', ca: 1, ce: 9, instability: 0.9, abstractness: 1.0, distance: 0.9, zone: Zone::Useless);
        $data = new ReportData([$pain, $useless], MetricsSummary::from([$pain, $useless]), []);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame('pain', $decoded['components'][0]['zone']);
        self::assertSame('useless', $decoded['components'][1]['zone']);
    }

    public function testAlwaysIncludesAllClassesRegardlessOfZone(): void
    {
        $classInfo = new ClassInfo('App\\Domain\\User', TypeKind::Interface_, '/dummy.php', []);
        $metrics = [
            $this->metrics('App\\Domain', ca: 8, ce: 2, instability: 0.2, abstractness: 0.75, distance: 0.05, zone: Zone::None, classInfos: [$classInfo]),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $decoded = $this->decode((new JsonReporter())->render($data));
        $classes = $decoded['components'][0]['classes'];

        self::assertCount(1, $classes);
        self::assertSame('App\\Domain\\User', $classes[0]['fqcn']);
        self::assertSame('interface', $classes[0]['kind']);
        self::assertSame(1, $decoded['components'][0]['classCount']);
    }

    public function testEncodesCycles(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), [], [['App\\Domain', 'App\\Infra']]);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame([['App\\Domain', 'App\\Infra']], $decoded['cycles']);
    }

    public function testEncodesEmptyCyclesArrayWhenNoCyclesExist(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), []);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame([], $decoded['cycles']);
    }

    public function testEncodesWarnings(): void
    {
        $data = new ReportData([], MetricsSummary::from([]), ['パースエラーのためスキップしました: /x.php']);

        $decoded = $this->decode((new JsonReporter())->render($data));

        self::assertSame(['パースエラーのためスキップしました: /x.php'], $decoded['warnings']);
    }

    public function testOutputIsUnescapedForSlashesAndUnicode(): void
    {
        $classInfo = new ClassInfo('App\\Domain\\User', TypeKind::ConcreteClass, '/dummy.php', []);
        $metrics = [
            $this->metrics('App\\Legacy', ca: 6, ce: 1, instability: 0.14, abstractness: 0.0, distance: 0.86, zone: Zone::Pain, classInfos: [$classInfo]),
        ];
        $data = new ReportData($metrics, MetricsSummary::from($metrics), []);

        $output = (new JsonReporter())->render($data);

        // JSON_UNESCAPED_SLASHES: FQCN の \ が \\/ にエスケープされない
        self::assertStringNotContainsString('\\/', $output);
        // JSON_PRETTY_PRINT: インデントされている
        self::assertStringContainsString("\n", $output);
    }

    /**
     * @return array{
     *     summary: array{componentCount: int, meanDistance: float, varianceDistance: float},
     *     components: list<array{
     *         name: string,
     *         classCount: int,
     *         ca: int,
     *         ce: int,
     *         instability: float,
     *         abstractness: float,
     *         distance: float,
     *         zone: string|null,
     *         classes: list<array{fqcn: string, kind: string}>,
     *     }>,
     *     cycles: list<list<string>>,
     *     warnings: list<string>,
     * }
     */
    private function decode(string $json): array
    {
        /**
         * @var array{
         *     summary: array{componentCount: int, meanDistance: float, varianceDistance: float},
         *     components: list<array{
         *         name: string,
         *         classCount: int,
         *         ca: int,
         *         ce: int,
         *         instability: float,
         *         abstractness: float,
         *         distance: float,
         *         zone: string|null,
         *         classes: list<array{fqcn: string, kind: string}>,
         *     }>,
         *     cycles: list<list<string>>,
         *     warnings: list<string>,
         * } $decoded
         */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param list<ClassInfo> $classInfos
     */
    private function metrics(
        string $name,
        int $ca,
        int $ce,
        float $instability,
        float $abstractness,
        float $distance,
        Zone $zone,
        array $classInfos = [],
    ): ComponentMetrics {
        return new ComponentMetrics(
            component: new Component($name, $classInfos),
            ca: $ca,
            ce: $ce,
            instability: $instability,
            abstractness: $abstractness,
            distance: $distance,
            zone: $zone,
        );
    }
}

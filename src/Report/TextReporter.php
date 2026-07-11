<?php

declare(strict_types=1);

namespace Bobsap\Report;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\Zone;

/**
 * 人間向けのテキスト表レポート。
 *
 * コンポーネント一覧を表形式で出し、苦痛ゾーン・無駄ゾーン該当コンポーネントには警告と
 * 所属クラス一覧を表示する。verbose=true のときは全コンポーネントのクラス一覧を表示する。
 */
final class TextReporter implements ReporterInterface
{
    /** ゾーン警告なしの通常行に合わせて数値列の幅を揃えるための最小幅（"0.00" 形式は常に4桁） */
    private const int DECIMAL_COLUMN_WIDTH = 4;

    /** 表の Zone 列の区切り線の長さ（見た目上の目安。値そのものは padding しない） */
    private const int ZONE_SEPARATOR_WIDTH = 10;

    public function __construct(
        private readonly bool $verbose = false,
    ) {
    }

    public function render(ReportData $data): string
    {
        $lines = [];
        $lines[] = 'bobsap - Stable Abstractions Principle metrics';
        $lines[] = '';

        $widths = $this->calculateColumnWidths($data->componentMetrics);
        $lines[] = $this->headerLine($widths);
        $lines[] = $this->separatorLine($widths);

        foreach ($data->componentMetrics as $metrics) {
            $lines[] = $this->rowLine($metrics, $widths);
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Statistics: mean(D)=%.2f, variance(D)=%.2f',
            $data->summary->meanDistance,
            $data->summary->varianceDistance,
        );

        if ($data->cycles !== []) {
            $lines[] = '';
            $lines[] = 'Cycles (ADP violation):';
            foreach ($data->cycles as $cycle) {
                $lines[] = '  - Components: ' . implode(', ', $cycle);
                $lines[] = '    Edges:';
                foreach ($data->edgesInCycle($cycle) as [$from, $to]) {
                    $lines[] = sprintf('      %s -> %s', $from, $to);
                }
            }
        }

        foreach ($data->componentMetrics as $metrics) {
            if (!$this->verbose && $metrics->zone === Zone::None) {
                continue;
            }

            $lines[] = '';
            $lines[] = sprintf('Classes in %s:', $metrics->component->name);
            foreach ($metrics->component->classInfos as $classInfo) {
                $lines[] = $this->classLine($classInfo);
            }
        }

        if ($data->warnings !== []) {
            $lines[] = '';
            $lines[] = 'Warnings:';
            foreach ($data->warnings as $warning) {
                $lines[] = '  - ' . $warning;
            }
        }

        return implode("\n", $lines);
    }

    private function classLine(ClassInfo $classInfo): string
    {
        return sprintf('  - %s (%s)', $classInfo->fqcn, $classInfo->kind->label());
    }

    /**
     * @param list<ComponentMetrics> $componentMetrics
     * @return array{name: int, classes: int, ca: int, ce: int, decimal: int}
     */
    private function calculateColumnWidths(array $componentMetrics): array
    {
        $nameWidth = mb_strlen('Component');
        $classesWidth = mb_strlen('Classes');
        $caWidth = mb_strlen('Ca');
        $ceWidth = mb_strlen('Ce');

        foreach ($componentMetrics as $metrics) {
            $nameWidth = max($nameWidth, mb_strlen($metrics->component->name));
            $classesWidth = max($classesWidth, mb_strlen((string) count($metrics->component->classInfos)));
            $caWidth = max($caWidth, mb_strlen((string) $metrics->ca));
            $ceWidth = max($ceWidth, mb_strlen((string) $metrics->ce));
        }

        return [
            'name' => $nameWidth,
            'classes' => $classesWidth,
            'ca' => $caWidth,
            'ce' => $ceWidth,
            'decimal' => self::DECIMAL_COLUMN_WIDTH,
        ];
    }

    /**
     * @param array{name: int, classes: int, ca: int, ce: int, decimal: int} $widths
     */
    private function headerLine(array $widths): string
    {
        return sprintf(
            '%-' . $widths['name'] . 's  %' . $widths['classes'] . 's  %' . $widths['ca'] . 's  %' . $widths['ce'] . 's  %' . $widths['decimal'] . 's  %' . $widths['decimal'] . 's  %' . $widths['decimal'] . 's  Zone',
            'Component',
            'Classes',
            'Ca',
            'Ce',
            'I',
            'A',
            'D',
        );
    }

    /**
     * @param array{name: int, classes: int, ca: int, ce: int, decimal: int} $widths
     */
    private function separatorLine(array $widths): string
    {
        return implode('  ', [
            str_repeat('-', $widths['name']),
            str_repeat('-', $widths['classes']),
            str_repeat('-', $widths['ca']),
            str_repeat('-', $widths['ce']),
            str_repeat('-', $widths['decimal']),
            str_repeat('-', $widths['decimal']),
            str_repeat('-', $widths['decimal']),
            str_repeat('-', self::ZONE_SEPARATOR_WIDTH),
        ]);
    }

    /**
     * @param array{name: int, classes: int, ca: int, ce: int, decimal: int} $widths
     */
    private function rowLine(ComponentMetrics $metrics, array $widths): string
    {
        $row = sprintf(
            '%-' . $widths['name'] . 's  %' . $widths['classes'] . 'd  %' . $widths['ca'] . 'd  %' . $widths['ce'] . 'd  %' . $widths['decimal'] . '.2f  %' . $widths['decimal'] . '.2f  %' . $widths['decimal'] . '.2f',
            $metrics->component->name,
            count($metrics->component->classInfos),
            $metrics->ca,
            $metrics->ce,
            $metrics->instability,
            $metrics->abstractness,
            $metrics->distance,
        );

        if ($metrics->zone !== Zone::None) {
            $row .= '  ⚠ ' . $metrics->zone->label();
        }

        return $row;
    }
}

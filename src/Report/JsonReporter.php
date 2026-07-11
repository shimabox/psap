<?php

declare(strict_types=1);

namespace Bobsap\Report;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Metrics\ComponentMetrics;
use Bobsap\Metrics\Zone;
use JsonException;

/**
 * 機械可読な JSON レポート。
 *
 * text と異なり、常に全コンポーネント・全クラスを出力する（フィルタリングは呼び出し側の責務）。
 */
final class JsonReporter implements ReporterInterface
{
    /** D 値・I 値・A 値の丸め桁数（浮動小数点誤差でノイズが出ないようにする） */
    private const int ROUND_PRECISION = 4;

    public function render(ReportData $data): string
    {
        $payload = [
            'summary' => [
                'componentCount' => count($data->componentMetrics),
                'meanDistance' => round($data->summary->meanDistance, self::ROUND_PRECISION),
                'varianceDistance' => round($data->summary->varianceDistance, self::ROUND_PRECISION),
            ],
            'components' => array_map($this->componentPayload(...), $data->componentMetrics),
            'warnings' => $data->warnings,
        ];

        try {
            return json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            // ここに到達するのは payload が JSON にエンコードできない場合のみで、
            // 上記の構築内容では通常起こり得ない
            throw new \RuntimeException('レポートの JSON エンコードに失敗しました: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array{
     *     name: string,
     *     classCount: int,
     *     ca: int,
     *     ce: int,
     *     instability: float,
     *     abstractness: float,
     *     distance: float,
     *     zone: string|null,
     *     classes: list<array{fqcn: string, kind: string}>,
     * }
     */
    private function componentPayload(ComponentMetrics $metrics): array
    {
        return [
            'name' => $metrics->component->name,
            'classCount' => count($metrics->component->classInfos),
            'ca' => $metrics->ca,
            'ce' => $metrics->ce,
            'instability' => round($metrics->instability, self::ROUND_PRECISION),
            'abstractness' => round($metrics->abstractness, self::ROUND_PRECISION),
            'distance' => round($metrics->distance, self::ROUND_PRECISION),
            'zone' => $this->zoneValue($metrics->zone),
            'classes' => array_map($this->classPayload(...), $metrics->component->classInfos),
        ];
    }

    private function zoneValue(Zone $zone): ?string
    {
        return match ($zone) {
            Zone::None => null,
            Zone::Pain => 'pain',
            Zone::Useless => 'useless',
        };
    }

    /**
     * @return array{fqcn: string, kind: string}
     */
    private function classPayload(ClassInfo $classInfo): array
    {
        return [
            'fqcn' => $classInfo->fqcn,
            'kind' => $classInfo->kind->label(),
        ];
    }
}

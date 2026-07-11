<?php

declare(strict_types=1);

namespace Bobsap\Metrics;

use Bobsap\Component\Component;

/**
 * 1コンポーネント分の計測結果。
 */
final readonly class ComponentMetrics
{
    /**
     * @param Component $component 計測対象のコンポーネント
     * @param int $ca ファン・イン（Ca）: 外部から自コンポーネントに依存しているクラス数
     * @param int $ce ファン・アウト（Ce）: 自コンポーネントから外部に依存しているクラス数
     * @param float $instability 不安定さ（I） = Ce / (Ca + Ce)
     * @param float $abstractness 抽象度（A） = 抽象型数 / 総型数
     * @param float $distance 主系列からの距離（D） = |A + I - 1|
     * @param Zone $zone 苦痛ゾーン・無駄ゾーンの判定結果
     */
    public function __construct(
        public Component $component,
        public int $ca,
        public int $ce,
        public float $instability,
        public float $abstractness,
        public float $distance,
        public Zone $zone,
    ) {
    }
}

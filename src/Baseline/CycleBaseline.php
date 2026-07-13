<?php

declare(strict_types=1);

namespace Psap\Baseline;

use InvalidArgumentException;
use JsonException;

final readonly class CycleBaseline
{
    private const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $excludePatterns
     * @param list<list<string>> $cycles
     */
    private function __construct(
        public int $namespaceDepth,
        public bool $docblockEnabled,
        public array $excludePatterns,
        public array $cycles,
    ) {
    }

    /**
     * @param list<string> $excludePatterns
     * @param list<list<string>> $cycles
     */
    public static function create(
        int $namespaceDepth,
        bool $docblockEnabled,
        array $excludePatterns,
        array $cycles,
    ): self {
        sort($excludePatterns);

        return new self(
            $namespaceDepth,
            $docblockEnabled,
            array_values(array_unique($excludePatterns)),
            self::normalizeCycles($cycles),
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('循環ベースラインが不正なJSONです。', previous: $e);
        }

        if (!is_array($payload) || ($payload['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(sprintf('未対応の循環ベースライン形式です。schemaVersionは%dである必要があります。', self::SCHEMA_VERSION));
        }

        $namespaceDepth = $payload['namespaceDepth'] ?? null;
        $docblockEnabled = $payload['docblockEnabled'] ?? null;
        $excludePatterns = $payload['excludePatterns'] ?? null;
        $cycles = $payload['cycles'] ?? null;
        if (!is_int($namespaceDepth) || $namespaceDepth < 1
            || !is_bool($docblockEnabled)
            || !self::isStringList($excludePatterns)
            || !self::isCycleList($cycles)) {
            throw new InvalidArgumentException('循環ベースラインの内容が不正です。');
        }

        /** @var list<string> $excludePatterns */
        /** @var list<list<string>> $cycles */
        return self::create($namespaceDepth, $docblockEnabled, $excludePatterns, $cycles);
    }

    public function toJson(): string
    {
        try {
            return json_encode(
                [
                    'schemaVersion' => self::SCHEMA_VERSION,
                    'namespaceDepth' => $this->namespaceDepth,
                    'docblockEnabled' => $this->docblockEnabled,
                    'excludePatterns' => $this->excludePatterns,
                    'cycles' => $this->cycles,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new \RuntimeException('循環ベースラインをJSONへ変換できませんでした。', previous: $e);
        }
    }

    /**
     * @param list<string> $excludePatterns
     */
    public function assertCompatible(int $namespaceDepth, bool $docblockEnabled, array $excludePatterns): void
    {
        sort($excludePatterns);
        $excludePatterns = array_values(array_unique($excludePatterns));

        if ($this->namespaceDepth !== $namespaceDepth) {
            throw new InvalidArgumentException(sprintf(
                '循環ベースラインの名前空間深度が一致しません。baseline=%d current=%d',
                $this->namespaceDepth,
                $namespaceDepth,
            ));
        }
        if ($this->docblockEnabled !== $docblockEnabled) {
            throw new InvalidArgumentException('循環ベースラインのdocblock設定が一致しません。');
        }
        if ($this->excludePatterns !== $excludePatterns) {
            throw new InvalidArgumentException('循環ベースラインの除外パターンが一致しません。');
        }
    }

    /**
     * @param list<list<string>> $currentCycles
     */
    public function compare(array $currentCycles): CycleBaselineComparison
    {
        $currentCycles = self::normalizeCycles($currentCycles);
        $baselineBySignature = self::bySignature($this->cycles);
        $currentBySignature = self::bySignature($currentCycles);

        return new CycleBaselineComparison(
            newCycles: array_values(array_diff_key($currentBySignature, $baselineBySignature)),
            resolvedCycles: array_values(array_diff_key($baselineBySignature, $currentBySignature)),
        );
    }

    /**
     * @param list<list<string>> $cycles
     * @return list<list<string>>
     */
    private static function normalizeCycles(array $cycles): array
    {
        foreach ($cycles as &$cycle) {
            sort($cycle);
            $cycle = array_values(array_unique($cycle));
        }
        unset($cycle);
        usort($cycles, static fn (array $left, array $right): int => $left <=> $right);

        return $cycles;
    }

    /**
     * @param list<list<string>> $cycles
     * @return array<string, list<string>>
     */
    private static function bySignature(array $cycles): array
    {
        $result = [];
        foreach ($cycles as $cycle) {
            $result[implode("\0", $cycle)] = $cycle;
        }

        return $result;
    }

    private static function isStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_string($item));
    }

    private static function isCycleList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $cycle): bool => self::isCycle($cycle));
    }

    private static function isCycle(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        $members = [];
        foreach ($value as $member) {
            if (!is_string($member)) {
                return false;
            }
            $members[$member] = true;
        }

        return count($members) >= 2;
    }
}

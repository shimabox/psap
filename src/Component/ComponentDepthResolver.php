<?php

declare(strict_types=1);

namespace Psap\Component;

use Psap\Analyzer\ClassInfo;

/**
 * 共通名前空間の直下をコンポーネントとして分ける深さを求める。
 */
final class ComponentDepthResolver
{
    /**
     * @param list<ClassInfo> $classInfos
     */
    public function resolve(array $classInfos): int
    {
        $namespaces = [];
        foreach ($classInfos as $classInfo) {
            $separatorPosition = strrpos($classInfo->fqcn, '\\');
            if ($separatorPosition !== false) {
                $namespaces[] = explode('\\', substr($classInfo->fqcn, 0, $separatorPosition));
            }
        }

        if ($namespaces === []) {
            return 1;
        }

        $maxDepth = max(array_map(count(...), $namespaces));
        $commonPrefix = array_shift($namespaces);
        foreach ($namespaces as $namespace) {
            $commonLength = 0;
            $limit = min(count($commonPrefix), count($namespace));
            while ($commonLength < $limit && $commonPrefix[$commonLength] === $namespace[$commonLength]) {
                $commonLength++;
            }
            $commonPrefix = array_slice($commonPrefix, 0, $commonLength);

            if ($commonPrefix === []) {
                break;
            }
        }

        return min(count($commonPrefix) + 1, $maxDepth);
    }
}

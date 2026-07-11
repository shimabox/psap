<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Component;

use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\TypeKind;
use Bobsap\Component\ComponentDepthResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComponentDepthResolverTest extends TestCase
{
    /**
     * @param list<string> $fqcns
     */
    #[DataProvider('namespaceProvider')]
    public function testResolvesDepthFromCommonNamespace(array $fqcns, int $expectedDepth): void
    {
        $classInfos = array_map(
            static fn (string $fqcn): ClassInfo => new ClassInfo($fqcn, TypeKind::ConcreteClass, '/dummy.php', []),
            $fqcns,
        );

        self::assertSame($expectedDepth, (new ComponentDepthResolver())->resolve($classInfos));
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function namespaceProvider(): iterable
    {
        yield 'single-segment package' => [
            ['FastRoute\\Route', 'FastRoute\\Cache\\FileCache'],
            2,
        ];
        yield 'vendor and package prefix' => [
            ['Nyholm\\Psr7\\Request', 'Nyholm\\Psr7\\Factory\\Psr17Factory'],
            3,
        ];
        yield 'one namespace cannot be split' => [
            ['GuzzleHttp\\Promise\\Promise', 'GuzzleHttp\\Promise\\Utils'],
            2,
        ];
        yield 'unrelated roots split at first segment' => [
            ['App\\Domain\\User', 'Vendor\\Package\\Service'],
            1,
        ];
        yield 'global declarations are ignored' => [
            ['LegacyClass', 'App\\Domain\\User', 'App\\Infra\\Repository'],
            2,
        ];
        yield 'only global declarations' => [
            ['LegacyClass', 'OtherClass'],
            1,
        ];
    }
}

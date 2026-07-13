<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Analyzer;

use PHPUnit\Framework\TestCase;
use Psap\Analyzer\SourceFinder;
use RuntimeException;

// SourceFinder: 指定ディレクトリからの .php 再帰列挙・exclude パターン・ソート順のテスト
final class SourceFinderTest extends TestCase
{
    private const SIMPLE_PROJECT = __DIR__ . '/../../Fixtures/SimpleProject';
    private const BROKEN_PROJECT = __DIR__ . '/../../Fixtures/BrokenProject';

    public function testFindsAllPhpFilesRecursively(): void
    {
        $finder = new SourceFinder();

        $files = $finder->find([self::SIMPLE_PROJECT]);

        $relativePaths = $this->toRelativePaths($files, self::SIMPLE_PROJECT);

        self::assertSame(
            [
                'Domain/AbstractEntity.php',
                'Domain/Address.php',
                'Domain/Attribute/Since.php',
                'Domain/EmailAddress.php',
                'Domain/HasTimestamps.php',
                'Domain/Nameable.php',
                'Domain/Status.php',
                'Domain/User.php',
                'Generated/Ignored.php',
                'Infra/RepositoryException.php',
                'Infra/UserRepository.php',
                'legacy-global.php',
            ],
            $relativePaths,
        );
    }

    public function testExcludesFilesMatchingFnmatchPattern(): void
    {
        $finder = new SourceFinder();

        // fnmatch は各探索ディレクトリからの相対パスに対して適用される
        $files = $finder->find([self::SIMPLE_PROJECT], ['Generated/*']);

        $relativePaths = $this->toRelativePaths($files, self::SIMPLE_PROJECT);

        self::assertNotContains('Generated/Ignored.php', $relativePaths);
        self::assertContains('Domain/User.php', $relativePaths);
    }

    public function testExcludesNestedDirectoryUsingWildcardPattern(): void
    {
        $finder = new SourceFinder();

        // ネストしたディレクトリを除外する典型例（例: "*/Tests/*" 相当）
        $files = $finder->find([self::SIMPLE_PROJECT], ['*/Attribute/*']);

        $relativePaths = $this->toRelativePaths($files, self::SIMPLE_PROJECT);

        self::assertNotContains('Domain/Attribute/Since.php', $relativePaths);
        self::assertContains('Domain/User.php', $relativePaths);
    }

    public function testResultIsSorted(): void
    {
        $finder = new SourceFinder();

        $files = $finder->find([self::SIMPLE_PROJECT]);

        $sorted = $files;
        sort($sorted);

        self::assertSame($sorted, $files);
    }

    public function testAcceptsMultipleDirectories(): void
    {
        $finder = new SourceFinder();

        $files = $finder->find([self::SIMPLE_PROJECT, self::BROKEN_PROJECT]);

        $paths = array_map(static fn (string $path): string => basename($path), $files);

        self::assertContains('User.php', $paths);
        self::assertContains('Broken.php', $paths);
        self::assertContains('Valid.php', $paths);
    }

    public function testUnreadableDirectoryRaisesDescriptiveException(): void
    {
        $path = self::SIMPLE_PROJECT . '/DoesNotExist';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ディレクトリを読み取れません: ' . $path);

        (new SourceFinder())->find([$path]);
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function toRelativePaths(array $files, string $baseDirectory): array
    {
        $base = (string) realpath($baseDirectory);

        return array_map(
            static fn (string $file): string => ltrim(substr($file, strlen($base)), '/'),
            $files,
        );
    }
}

<?php

declare(strict_types=1);

namespace Bobsap\Tests\Unit\Analyzer;

use Bobsap\Analyzer\AnalysisResult;
use Bobsap\Analyzer\ClassInfo;
use Bobsap\Analyzer\DependencyAnalyzer;
use Bobsap\Analyzer\DependencyKind;
use Bobsap\Analyzer\SourceFinder;
use Bobsap\Analyzer\TypeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// DependencyAnalyzer: 型種別の判定・依存関係抽出（数えるもの一式）・
// グローバル名前空間・パースエラー時の警告とスキップ・無名クラスの扱いのテスト
final class DependencyAnalyzerTest extends TestCase
{
    private const SIMPLE_PROJECT = __DIR__ . '/../../Fixtures/SimpleProject';
    private const BROKEN_PROJECT = __DIR__ . '/../../Fixtures/BrokenProject';
    private const DOCBLOCK_PROJECT = __DIR__ . '/../../Fixtures/DocblockProject';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
        $this->tempFiles = [];
    }

    // --- 型種別の判定（5種すべて） ---

    public function testDetectsAllFiveTypeKinds(): void
    {
        $classInfos = $this->analyzeFixtureProject(self::SIMPLE_PROJECT);

        self::assertSame(TypeKind::Interface_, $this->findByFqcn($classInfos, 'Fixture\\App\\Domain\\Nameable')->kind);
        self::assertSame(TypeKind::AbstractClass, $this->findByFqcn($classInfos, 'Fixture\\App\\Domain\\AbstractEntity')->kind);
        self::assertSame(TypeKind::ConcreteClass, $this->findByFqcn($classInfos, 'Fixture\\App\\Domain\\EmailAddress')->kind);
        self::assertSame(TypeKind::Enum_, $this->findByFqcn($classInfos, 'Fixture\\App\\Domain\\Status')->kind);
        self::assertSame(TypeKind::Trait_, $this->findByFqcn($classInfos, 'Fixture\\App\\Domain\\HasTimestamps')->kind);
    }

    // --- 組み込みクラスへの依存はフィルタリングせずそのまま記録する ---

    public function testBuiltinClassDependencyIsKeptAsIs(): void
    {
        $classInfos = $this->analyzeFixtureProject(self::SIMPLE_PROJECT);

        $repositoryException = $this->findByFqcn($classInfos, 'Fixture\\App\\Infra\\RepositoryException');
        self::assertSame(['Exception'], $repositoryException->dependencies);

        $hasTimestamps = $this->findByFqcn($classInfos, 'Fixture\\App\\Domain\\HasTimestamps');
        self::assertSame(['DateTimeImmutable'], $hasTimestamps->dependencies);
    }

    // --- グローバル名前空間 & 1ファイル複数型宣言 ---

    public function testGlobalNamespaceAndMultipleDeclarationsPerFile(): void
    {
        $finder = new SourceFinder();
        $analyzer = new DependencyAnalyzer();
        $files = $finder->find([self::SIMPLE_PROJECT]);
        $result = $analyzer->analyze($files);

        $legacy = $this->findByFqcn($result->classInfos, 'LegacyGlobalThing');
        $helper = $this->findByFqcn($result->classInfos, 'GlobalHelper');

        self::assertSame(TypeKind::ConcreteClass, $legacy->kind);
        self::assertSame(['GlobalHelper'], $legacy->dependencies);
        self::assertSame(TypeKind::ConcreteClass, $helper->kind);
        self::assertSame([], $helper->dependencies);
    }

    public function testMergesConditionalDeclarationsWithTheSameFqcn(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Fixture\Cases;
            class FirstDependency {}
            class SecondDependency {}
            if (PHP_VERSION_ID >= 80000) {
                trait CompatibleTrait { private FirstDependency $first; }
            } else {
                trait CompatibleTrait { private SecondDependency $second; }
            }
            PHP;

        $result = $this->analyzeCode($code);

        self::assertCount(3, $result->classInfos);
        self::assertSame(
            ['Fixture\\Cases\\FirstDependency', 'Fixture\\Cases\\SecondDependency'],
            $this->findByFqcn($result->classInfos, 'Fixture\\Cases\\CompatibleTrait')->dependencies,
        );
    }

    // --- パースエラーのファイルは例外を投げずスキップし、警告として収集する ---

    public function testParseErrorIsCollectedAsWarningAndSkipped(): void
    {
        $finder = new SourceFinder();
        $analyzer = new DependencyAnalyzer();
        $files = $finder->find([self::BROKEN_PROJECT]);

        $result = $analyzer->analyze($files);

        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('Broken.php', $result->warnings[0]);

        // 壊れていないファイルは問題なく解析できる
        $valid = $this->findByFqcn($result->classInfos, 'Fixture\\Broken\\Valid');
        self::assertSame(TypeKind::ConcreteClass, $valid->kind);

        // 壊れているファイル由来の ClassInfo は含まれない
        self::assertNull($this->tryFindByFqcn($result->classInfos, 'Fixture\\Broken\\Broken'));
    }

    public function testNameResolutionErrorIsCollectedAsWarningAndSkipped(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Fixture\Cases;
            use Fixture\One as Duplicate;
            use Fixture\Two as Duplicate;
            class Target {}
            PHP;

        $result = $this->analyzeCode($code);

        self::assertCount(0, $result->classInfos);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('名前解決エラーのためスキップしました', $result->warnings[0]);
    }

    // --- 無名クラス: 宣言はClassInfoにならず、内部の依存も外側に伝播しない ---

    public function testAnonymousClassIsNotCollectedAndItsDependenciesAreNotAttributedToOuterClass(): void
    {
        $code = <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Fixture\Cases;
            interface Iface {}
            class Inner {}
            class Target
            {
                public function make(): object
                {
                    return new class implements Iface {
                        private Inner $inner;
                    };
                }
            }
            PHP;

        $result = $this->analyzeCode($code);

        // Iface, Inner, Target の3件のみで、無名クラスはカウントされない
        self::assertCount(3, $result->classInfos);

        $target = $this->findByFqcn($result->classInfos, 'Fixture\\Cases\\Target');
        self::assertSame([], $target->dependencies);
    }

    // --- docblock からの依存抽出（Phase 7） ---

    // @var / @param（プロモートされたコンストラクタ引数含む）/ @return / array<int, X> 形式から
    // 依存が拾えること、docblock 内の use文エイリアス（短縮名）が FQCN に解決されることをまとめて確認する。
    // 実コードの型宣言は一切 Product を参照しないフィクスチャなので、依存は docblock 由来のみ。
    public function testExtractsDependenciesFromVarParamAndReturnDocblocks(): void
    {
        $finder = new SourceFinder();
        $analyzer = new DependencyAnalyzer();
        $files = $finder->find([self::DOCBLOCK_PROJECT]);

        $result = $analyzer->analyze($files);

        $order = $this->findByFqcn($result->classInfos, 'Fixture\\DocblockProject\\Domain\\Order');
        self::assertSame(['Fixture\\DocblockProject\\Domain\\Product'], $order->dependencies);
    }

    public function testDocblockAliasesUseTheContextOfEachNamespaceBlock(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Fixture\First {
                use Fixture\Catalog\Product as Item;
                class Consumer
                {
                    /** @var Item */
                    private mixed $item;
                }
            }
            namespace Fixture\Second {
                use Fixture\Other\WrongProduct as Item;
                class OtherConsumer
                {
                    /** @var Item */
                    private mixed $item;
                }
            }
            PHP;

        $result = $this->analyzeCode($code);

        self::assertSame(
            ['Fixture\\Catalog\\Product'],
            $this->findByFqcn($result->classInfos, 'Fixture\\First\\Consumer')->dependencies,
        );
        self::assertSame(
            ['Fixture\\Other\\WrongProduct'],
            $this->findByFqcn($result->classInfos, 'Fixture\\Second\\OtherConsumer')->dependencies,
        );
    }

    // useDocblock: false を指定すると docblock は一切解析されない
    public function testUseDocblockFalseDisablesDocblockDependencyCollection(): void
    {
        $finder = new SourceFinder();
        $analyzer = new DependencyAnalyzer(useDocblock: false);
        $files = $finder->find([self::DOCBLOCK_PROJECT]);

        $result = $analyzer->analyze($files);

        $order = $this->findByFqcn($result->classInfos, 'Fixture\\DocblockProject\\Domain\\Order');
        self::assertSame([], $order->dependencies);
    }

    public function testExtractsThrowsDependencyFromMethodDocblock(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Fixture\Cases;
            class DomainFailure extends \RuntimeException {}
            class Target
            {
                /** @throws DomainFailure */
                public function execute(): void {}
            }
            PHP;

        $result = $this->analyzeCode($code);

        self::assertSame(
            ['Fixture\\Cases\\DomainFailure'],
            $this->findByFqcn($result->classInfos, 'Fixture\\Cases\\Target')->dependencies,
        );
    }

    // 壊れた docblock は例外を投げず無視される。実コードの型宣言（Product）は引き続き拾える
    public function testBrokenDocblockIsIgnoredWithoutCrashing(): void
    {
        $finder = new SourceFinder();
        $analyzer = new DependencyAnalyzer();
        $files = $finder->find([self::DOCBLOCK_PROJECT]);

        $result = $analyzer->analyze($files);

        self::assertSame([], $result->warnings);
        $brokenDoc = $this->findByFqcn($result->classInfos, 'Fixture\\DocblockProject\\Domain\\BrokenDoc');
        self::assertSame(['Fixture\\DocblockProject\\Domain\\Product'], $brokenDoc->dependencies);
    }

    // --- 依存抽出（「数えるもの」を1パターンずつ） ---

    /**
     * @param list<string> $expectedDependencies
     */
    #[DataProvider('dependencyPatternProvider')]
    public function testExtractsDependencyPattern(string $code, array $expectedDependencies): void
    {
        $result = $this->analyzeCode($code);

        $target = $this->findByFqcn($result->classInfos, 'Fixture\\Cases\\Target');

        self::assertEqualsCanonicalizing($expectedDependencies, $target->dependencies);
    }

    public function testRecordsSyntaxKindRelativeFileAndLineForDependencies(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Fixture\Cases;
            #[\Attribute] class Marker {}
            class Base {}
            interface Contract {}
            trait HelperTrait {}
            class Dep { public const VALUE = 1; public static int $value; public static function run(): void {} }
            class Failure extends \RuntimeException {}
            class DocVar {}
            class DocParam {}
            class DocReturn {}
            class DocFailure extends \RuntimeException {}
            #[Marker]
            class Target extends Base implements Contract
            {
                use HelperTrait;
                private Dep $dependency;
                /** @var DocVar */
                private mixed $documented;
                /**
                 * @param DocParam $input
                 * @return DocReturn
                 * @throws DocFailure
                 */
                public function execute(Dep $dependency, mixed $input): Dep
                {
                    Dep::run();
                    Dep::$value;
                    Dep::VALUE;
                    $dependency instanceof Dep;
                    try {} catch (Failure $failure) {}
                    return new Dep();
                }
            }
            PHP;
        $path = $this->createTempFile($code);
        $result = (new DependencyAnalyzer(sourceRoots: [dirname($path)]))->analyze([$path]);
        $target = $this->findByFqcn($result->classInfos, 'Fixture\\Cases\\Target');

        $actualKinds = array_map(
            static fn ($evidence): string => $evidence->kind->value,
            $target->dependencyEvidence,
        );
        $expectedKinds = array_map(
            static fn (DependencyKind $kind): string => $kind->value,
            DependencyKind::cases(),
        );
        self::assertEqualsCanonicalizing($expectedKinds, array_values(array_unique($actualKinds)));
        foreach ($target->dependencyEvidence as $evidence) {
            self::assertSame(basename($path), $evidence->file);
            self::assertGreaterThan(0, $evidence->line);
        }
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function dependencyPatternProvider(): array
    {
        return [
            'extends' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Base {}
                    class Target extends Base {}
                    PHP,
                ['Fixture\\Cases\\Base'],
            ],
            'implements' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    interface Iface {}
                    class Target implements Iface {}
                    PHP,
                ['Fixture\\Cases\\Iface'],
            ],
            'trait use' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    trait T {}
                    class Target { use T; }
                    PHP,
                ['Fixture\\Cases\\T'],
            ],
            'プロパティ型宣言' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep {}
                    class Target { private Dep $d; }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            'nullable な引数型宣言' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep {}
                    class Target { public function __construct(private ?Dep $d = null) {} }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            'union型' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class A {}
                    class B {}
                    class Target { private A|B $x; }
                    PHP,
                ['Fixture\\Cases\\A', 'Fixture\\Cases\\B'],
            ],
            'intersection型' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    interface A {}
                    interface B {}
                    class Target { public function m(A&B $x): void {} }
                    PHP,
                ['Fixture\\Cases\\A', 'Fixture\\Cases\\B'],
            ],
            '戻り値型宣言' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep {}
                    class Target { public function m(): Dep { return new Dep(); } }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            'new X(...)' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep {}
                    class Target { public function m(): void { new Dep(); } }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            'X::method()（静的呼び出し）' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep { public static function m(): void {} }
                    class Target { public function call(): void { Dep::m(); } }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            'X::CONST（静的定数）' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep { public const FOO = 1; }
                    class Target { public function get(): int { return Dep::FOO; } }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            'X::class' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep {}
                    class Target { public function name(): string { return Dep::class; } }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            'instanceof' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep {}
                    class Target { public function check(mixed $x): bool { return $x instanceof Dep; } }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            'catch（単一）' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class DepException extends \Exception {}
                    class Target { public function run(): void { try { } catch (DepException $e) { throw $e; } } }
                    PHP,
                ['Fixture\\Cases\\DepException'],
            ],
            'catch（union）' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class DepExceptionA extends \Exception {}
                    class DepExceptionB extends \Exception {}
                    class Target { public function run(): void { try { } catch (DepExceptionA|DepExceptionB $e) { throw $e; } } }
                    PHP,
                ['Fixture\\Cases\\DepExceptionA', 'Fixture\\Cases\\DepExceptionB'],
            ],
            'アトリビュート #[X]' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    #[\Attribute]
                    class DepAttribute {}
                    #[DepAttribute]
                    class Target {}
                    PHP,
                ['Fixture\\Cases\\DepAttribute'],
            ],
            'self / parent / static は除外' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Base {}
                    class Target extends Base
                    {
                        public function usesSpecialNames(): static
                        {
                            $x = new self();
                            $y = new parent();
                            $z = new static();
                            return $z;
                        }
                    }
                    PHP,
                ['Fixture\\Cases\\Base'],
            ],
            '組み込みスカラー型は依存として数えない' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Target
                    {
                        public function m(int $a, string $b, ?array $c, bool $d): void {}
                    }
                    PHP,
                [],
            ],
            '組み込みクラスへの依存は保持する' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Target
                    {
                        private \DateTimeImmutable $createdAt;
                        public function boom(): void { throw new \RuntimeException('x'); }
                    }
                    PHP,
                ['DateTimeImmutable', 'RuntimeException'],
            ],
            '重複は排除される' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Dep {}
                    class Target
                    {
                        private Dep $a;
                        private Dep $b;
                        public function m(Dep $x): Dep { return new Dep(); }
                    }
                    PHP,
                ['Fixture\\Cases\\Dep'],
            ],
            '自分自身への参照は除外される' => [
                <<<'PHP'
                    <?php
                    declare(strict_types=1);
                    namespace Fixture\Cases;
                    class Target
                    {
                        public function make(): self { return new self(); }
                        private ?Target $child = null;
                    }
                    PHP,
                [],
            ],
        ];
    }

    // --- ヘルパー ---

    /**
     * @return list<ClassInfo>
     */
    private function analyzeFixtureProject(string $directory): array
    {
        $finder = new SourceFinder();
        $analyzer = new DependencyAnalyzer();
        $files = $finder->find([$directory]);

        return $analyzer->analyze($files)->classInfos;
    }

    private function analyzeCode(string $code): AnalysisResult
    {
        $path = $this->createTempFile($code);
        $analyzer = new DependencyAnalyzer();

        return $analyzer->analyze([$path]);
    }

    private function createTempFile(string $code): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bobsap_') . '.php';
        file_put_contents($path, $code);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param list<ClassInfo> $classInfos
     */
    private function findByFqcn(array $classInfos, string $fqcn): ClassInfo
    {
        $found = $this->tryFindByFqcn($classInfos, $fqcn);
        self::assertNotNull($found, sprintf('%s が見つかりませんでした', $fqcn));

        return $found;
    }

    /**
     * @param list<ClassInfo> $classInfos
     */
    private function tryFindByFqcn(array $classInfos, string $fqcn): ?ClassInfo
    {
        foreach ($classInfos as $classInfo) {
            if ($classInfo->fqcn === $fqcn) {
                return $classInfo;
            }
        }

        return null;
    }
}

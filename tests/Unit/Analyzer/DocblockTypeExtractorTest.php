<?php

declare(strict_types=1);

namespace Psap\Tests\Unit\Analyzer;

use PhpParser\ErrorHandler\Throwing;
use PhpParser\NameContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psap\Analyzer\Internal\DocblockTypeExtractor;

// DocblockTypeExtractor: docblock の型文字列（@var / @param / @return / @throws）を
// クラス名候補の FQCN 一覧に分解するテスト。
// 名前解決はグローバル名前空間・use文なしの NameContext を使うため、
// 素の型名がそのまま解決後の名前になる（先頭 `\` は剥がされる点だけ確認する）。
final class DocblockTypeExtractorTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('varTypeProvider')]
    public function testExtractVarTypeNames(string $docComment, array $expected): void
    {
        $extractor = new DocblockTypeExtractor();

        $result = $extractor->extractVarTypeNames($docComment, $this->globalNameContext());

        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function varTypeProvider(): array
    {
        return [
            '単純なクラス名' => [
                "/**\n * @var User\n */",
                ['User'],
            ],
            '配列 X[]' => [
                "/**\n * @var User[]\n */",
                ['User'],
            ],
            'ジェネリクス array<X>' => [
                "/**\n * @var array<User>\n */",
                ['User'],
            ],
            'ジェネリクス array<int, X>' => [
                "/**\n * @var array<int, User>\n */",
                ['User'],
            ],
            'nullable ?X' => [
                "/**\n * @var ?User\n */",
                ['User'],
            ],
            'union X|Y' => [
                "/**\n * @var User|Address\n */",
                ['User', 'Address'],
            ],
            'intersection X&Y' => [
                "/**\n * @var User&Stringable\n */",
                ['User', 'Stringable'],
            ],
            '先頭バックスラッシュは剥がして格納' => [
                "/**\n * @var \\Foo\\Bar\n */",
                ['Foo\\Bar'],
            ],
            'プリミティブ型は除外' => [
                "/**\n * @var int\n */",
                [],
            ],
            'array<プリミティブ, プリミティブ> は除外' => [
                "/**\n * @var array<int, string>\n */",
                [],
            ],
            'nullable配列（プリミティブ）は除外' => [
                "/**\n * @var ?int\n */",
                [],
            ],
            '疑似型 mixed / void / self 等は除外' => [
                "/**\n * @var mixed\n */",
                [],
            ],
            'ジェネリクスの外側の型名がクラスなら候補に含める' => [
                "/**\n * @var Collection<User>\n */",
                ['Collection', 'User'],
            ],
            '@var タグがなければ空' => [
                "/**\n * 説明だけのdocblock\n */",
                [],
            ],
        ];
    }

    public function testExtractParamTypeNamesMatchesByParameterName(): void
    {
        $doc = "/**\n * @param User \$u\n * @param Address \$a\n */";
        $extractor = new DocblockTypeExtractor();

        self::assertSame(['User'], $extractor->extractParamTypeNames($doc, 'u', $this->globalNameContext()));
        self::assertSame(['Address'], $extractor->extractParamTypeNames($doc, 'a', $this->globalNameContext()));
        self::assertSame([], $extractor->extractParamTypeNames($doc, 'notExist', $this->globalNameContext()));
    }

    public function testExtractReturnTypeNames(): void
    {
        $doc = "/**\n * @return User\n */";
        $extractor = new DocblockTypeExtractor();

        self::assertSame(['User'], $extractor->extractReturnTypeNames($doc, $this->globalNameContext()));
    }

    public function testExtractThrowsTypeNames(): void
    {
        $doc = "/**\n * @throws DomainException|RuntimeException\n */";
        $extractor = new DocblockTypeExtractor();

        self::assertSame(
            ['DomainException', 'RuntimeException'],
            $extractor->extractThrowsTypeNames($doc, $this->globalNameContext()),
        );
    }

    // --- 壊れた docblock は例外を投げず黙って空配列を返す ---

    public function testBrokenDocblockIsIgnoredSilently(): void
    {
        $extractor = new DocblockTypeExtractor();

        self::assertSame([], $extractor->extractVarTypeNames('not a doc comment at all', $this->globalNameContext()));
        self::assertSame([], $extractor->extractVarTypeNames('', $this->globalNameContext()));
        self::assertSame([], $extractor->extractVarTypeNames("/**\n * @var array<\n */", $this->globalNameContext()));
    }

    // --- 短縮名の FQCN 解決（use文あり）。DependencyAnalyzerTest 側でも実ファイル経由のテストを行う ---

    public function testResolvesShortNameUsingAliasFromNameContext(): void
    {
        $nameContext = new NameContext(new Throwing());
        $nameContext->startNamespace(new \PhpParser\Node\Name('App\\Domain'));
        $nameContext->addAlias(new \PhpParser\Node\Name('App\\Model\\User'), 'User', \PhpParser\Node\Stmt\Use_::TYPE_NORMAL);

        $extractor = new DocblockTypeExtractor();

        self::assertSame(
            ['App\\Model\\User'],
            $extractor->extractVarTypeNames("/**\n * @var User\n */", $nameContext),
        );
    }

    private function globalNameContext(): NameContext
    {
        $nameContext = new NameContext(new Throwing());
        $nameContext->startNamespace();

        return $nameContext;
    }
}

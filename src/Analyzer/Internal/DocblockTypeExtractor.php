<?php

declare(strict_types=1);

namespace Bobsap\Analyzer\Internal;

use PhpParser\NameContext;
use PhpParser\Node\Name;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

/**
 * docblock（`@var` / `@param` / `@return`）の型文字列からクラス名候補の FQCN を抽出する。
 *
 * phpstan/phpdoc-parser に docblock の文字列をそのまま渡してパースし（php-class-diagram の
 * ような正規表現前処理はしない）、得られた TypeNode を再帰的に分解して
 * IdentifierTypeNode（クラス名候補）だけを集める。プリミティブ・疑似型はリストで除外し、
 * 残った名前は php-parser の NameContext（NameResolver::getNameContext()）で FQCN 解決する。
 *
 * docblock はしばしば壊れている（構文誤り・古い書式）ため、パースに失敗した場合は
 * 例外を投げず黙って空配列を返す。
 *
 * @internal DependencyAnalyzer の実装詳細
 */
final class DocblockTypeExtractor
{
    /**
     * 依存として数えないプリミティブ型・疑似型。
     * 小文字始まりというだけでは疑似型と断定できない（ユーザー定義クラスが小文字始まりの
     * こともある）ため、判定は明示的なリストベースで行う。
     *
     * @var list<string>
     */
    private const PRIMITIVE_OR_PSEUDO_TYPES = [
        'int', 'integer', 'string', 'bool', 'boolean', 'float', 'double',
        'array', 'iterable', 'callable', 'mixed', 'void', 'null', 'self',
        'static', 'parent', 'object', 'true', 'false', 'never', 'scalar',
        'resource', 'this',
        // phpstan/psalm 拡張の疑似型（よく使われるもののみ列挙）
        'class-string', 'array-key', 'numeric', 'numeric-string',
        'positive-int', 'negative-int', 'non-empty-array', 'non-empty-string',
        'non-empty-list', 'non-falsy-string', 'truthy-string', 'callable-string',
        'list',
    ];

    private readonly Lexer $lexer;
    private readonly PhpDocParser $phpDocParser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
    }

    /**
     * @return list<string>
     */
    public function extractVarTypeNames(string $docComment, NameContext $nameContext): array
    {
        $doc = $this->parse($docComment);
        if ($doc === null) {
            return [];
        }

        $names = [];
        foreach ($doc->getVarTagValues() as $tag) {
            $names = [...$names, ...$this->flatten($tag->type)];
        }

        return $this->resolveAll($names, $nameContext);
    }

    /**
     * @return list<string>
     */
    public function extractReturnTypeNames(string $docComment, NameContext $nameContext): array
    {
        $doc = $this->parse($docComment);
        if ($doc === null) {
            return [];
        }

        $names = [];
        foreach ($doc->getReturnTagValues() as $tag) {
            $names = [...$names, ...$this->flatten($tag->type)];
        }

        return $this->resolveAll($names, $nameContext);
    }

    /**
     * @return list<string>
     */
    public function extractParamTypeNames(string $docComment, string $paramName, NameContext $nameContext): array
    {
        $doc = $this->parse($docComment);
        if ($doc === null) {
            return [];
        }

        $names = [];
        foreach ($doc->getParamTagValues() as $tag) {
            if ($tag->parameterName !== '$' . $paramName) {
                continue;
            }

            $names = [...$names, ...$this->flatten($tag->type)];
        }

        return $this->resolveAll($names, $nameContext);
    }

    private function parse(string $docComment): ?PhpDocNode
    {
        try {
            $tokens = new TokenIterator($this->lexer->tokenize($docComment));

            return $this->phpDocParser->parse($tokens);
        } catch (Throwable) {
            // 壊れた docblock は黙って無視する
            return null;
        }
    }

    /**
     * TypeNode を再帰的に分解し、クラス名候補（生の文字列。未解決）の一覧にする。
     *
     * @return list<string>
     */
    private function flatten(TypeNode $type): array
    {
        if ($type instanceof IdentifierTypeNode) {
            return $this->isPrimitiveOrPseudo($type->name) ? [] : [$type->name];
        }

        if ($type instanceof ArrayTypeNode) {
            // X[] -> X
            return $this->flatten($type->type);
        }

        if ($type instanceof GenericTypeNode) {
            // array<X> / array<int, X> -> X（array は除外）
            // Collection<X> のようにクラスの場合は外側の型名も候補に含める
            $names = $this->isPrimitiveOrPseudo($type->type->name) ? [] : [$type->type->name];
            foreach ($type->genericTypes as $inner) {
                $names = [...$names, ...$this->flatten($inner)];
            }

            return $names;
        }

        if ($type instanceof NullableTypeNode) {
            // ?X -> X
            return $this->flatten($type->type);
        }

        if ($type instanceof UnionTypeNode || $type instanceof IntersectionTypeNode) {
            // X|Y, X&Y -> X, Y
            $names = [];
            foreach ($type->types as $inner) {
                $names = [...$names, ...$this->flatten($inner)];
            }

            return $names;
        }

        // ArrayShapeNode / CallableTypeNode / ConditionalTypeNode 等の複雑な型は対象外
        return [];
    }

    private function isPrimitiveOrPseudo(string $name): bool
    {
        return $name
            |> (static fn (string $value): string => ltrim($value, '\\'))
            |> strtolower(...)
            |> (static fn (string $value): bool => in_array($value, self::PRIMITIVE_OR_PSEUDO_TYPES, true));
    }

    /**
     * @param list<string> $rawNames
     * @return list<string>
     */
    private function resolveAll(array $rawNames, NameContext $nameContext): array
    {
        return array_map(
            fn (string $rawName): string => $this->resolve($rawName, $nameContext),
            $rawNames,
        );
    }

    private function resolve(string $rawName, NameContext $nameContext): string
    {
        $name = str_starts_with($rawName, '\\')
            ? new Name\FullyQualified(substr($rawName, 1))
            : new Name($rawName);

        return $nameContext->getResolvedClassName($name)->toString();
    }
}

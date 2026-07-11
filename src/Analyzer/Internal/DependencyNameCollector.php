<?php

declare(strict_types=1);

namespace Bobsap\Analyzer\Internal;

use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * 1つの型宣言（$root）の subtree から依存先の FQCN 文字列を集める。
 *
 * NameResolver 適用後の AST を前提とし、解決済みの Name ノードから FQCN を取り出す。
 * self / parent / static は依存として数えない。
 * 入れ子の型宣言（無名クラスの本体等）は別スコープなので、そこに現れる依存は
 * $root の依存としては拾わない（境界で探索を止める）。
 *
 * docblock 解析（$docblockExtractor と $nameContext の両方が渡されたときだけ有効）は
 * プロパティの `@var` とメソッドの `@param` / `@return` / `@throws` を対象にする。
 * docblock 内の短縮名は $nameContext（NameResolver::getNameContext()）で FQCN 解決する。
 *
 * @internal DependencyAnalyzer の実装詳細
 */
final class DependencyNameCollector extends NodeVisitorAbstract
{
    /** @var list<string> */
    public array $names = [];

    public function __construct(
        private readonly ClassLike $root,
        private readonly ?DocblockTypeExtractor $docblockExtractor = null,
        private readonly ?NameContext $nameContext = null,
    ) {
    }

    public function enterNode(Node $node): int|null
    {
        if ($node instanceof ClassLike && $node !== $this->root) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        foreach ($this->extractFrom($node) as $name) {
            $this->names[] = $name;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractFrom(Node $node): array
    {
        if ($node instanceof ClassLike) {
            $names = $this->namesFromAttributeGroups($node->attrGroups);

            if ($node instanceof Stmt\Class_) {
                if ($node->extends !== null) {
                    $this->appendName($names, $node->extends);
                }
                foreach ($node->implements as $interface) {
                    $this->appendName($names, $interface);
                }
            } elseif ($node instanceof Stmt\Interface_) {
                foreach ($node->extends as $interface) {
                    $this->appendName($names, $interface);
                }
            } elseif ($node instanceof Stmt\Enum_) {
                foreach ($node->implements as $interface) {
                    $this->appendName($names, $interface);
                }
            }

            return $names;
        }

        if ($node instanceof Stmt\TraitUse) {
            $names = [];
            foreach ($node->traits as $trait) {
                $this->appendName($names, $trait);
            }

            return $names;
        }

        if ($node instanceof Stmt\Property) {
            $names = $this->namesFromAttributeGroups($node->attrGroups);
            $this->appendType($names, $node->type);
            $names = [...$names, ...$this->namesFromVarDocblock($node)];

            return $names;
        }

        if ($node instanceof Param) {
            $names = $this->namesFromAttributeGroups($node->attrGroups);
            $this->appendType($names, $node->type);

            return $names;
        }

        if ($node instanceof Stmt\ClassMethod) {
            $names = $this->namesFromAttributeGroups($node->attrGroups);
            $this->appendType($names, $node->returnType);
            $names = [...$names, ...$this->namesFromMethodDocblock($node)];

            return $names;
        }

        if ($node instanceof Expr\Closure) {
            $names = $this->namesFromAttributeGroups($node->attrGroups);
            $this->appendType($names, $node->returnType);

            return $names;
        }

        if ($node instanceof Expr\ArrowFunction) {
            $names = $this->namesFromAttributeGroups($node->attrGroups);
            $this->appendType($names, $node->returnType);

            return $names;
        }

        if ($node instanceof Expr\New_) {
            $names = [];
            if ($node->class instanceof Name) {
                $this->appendName($names, $node->class);
            }

            return $names;
        }

        if ($node instanceof Expr\StaticCall) {
            $names = [];
            if ($node->class instanceof Name) {
                $this->appendName($names, $node->class);
            }

            return $names;
        }

        if ($node instanceof Expr\StaticPropertyFetch) {
            $names = [];
            if ($node->class instanceof Name) {
                $this->appendName($names, $node->class);
            }

            return $names;
        }

        if ($node instanceof Expr\ClassConstFetch) {
            $names = [];
            if ($node->class instanceof Name) {
                $this->appendName($names, $node->class);
            }

            return $names;
        }

        if ($node instanceof Expr\Instanceof_) {
            $names = [];
            if ($node->class instanceof Name) {
                $this->appendName($names, $node->class);
            }

            return $names;
        }

        if ($node instanceof Stmt\Catch_) {
            $names = [];
            foreach ($node->types as $type) {
                $this->appendName($names, $type);
            }

            return $names;
        }

        return [];
    }

    /**
     * @param list<string> $names
     */
    private function appendName(array &$names, Name $name): void
    {
        if ($name->isSpecialClassName()) {
            // self / parent / static は依存として数えない
            return;
        }

        $names[] = $name->toString();
    }

    /**
     * @param list<string> $names
     */
    private function appendType(array &$names, ComplexType|Name|Identifier|null $type): void
    {
        foreach ($this->flattenType($type) as $name) {
            $this->appendName($names, $name);
        }
    }

    /**
     * 型宣言（nullable / union / intersection）を分解し、依存先候補の Name 一覧にする。
     * int / string 等の組み込みスカラー型は Identifier ノードになるため、ここで自然に除外される。
     *
     * @return list<Name>
     */
    private function flattenType(ComplexType|Name|Identifier|null $type): array
    {
        if ($type === null || $type instanceof Identifier) {
            return [];
        }

        if ($type instanceof Name) {
            return [$type];
        }

        if ($type instanceof NullableType) {
            return $this->flattenType($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];
            foreach ($type->types as $inner) {
                $names = [...$names, ...$this->flattenType($inner)];
            }

            return $names;
        }

        return [];
    }

    /**
     * プロパティの `@var` docblock からクラス名候補の FQCN を集める。
     *
     * @return list<string>
     */
    private function namesFromVarDocblock(Stmt\Property $node): array
    {
        if ($this->docblockExtractor === null || $this->nameContext === null) {
            return [];
        }

        $doc = $node->getDocComment();
        if ($doc === null) {
            return [];
        }

        return $this->docblockExtractor->extractVarTypeNames($doc->getText(), $this->nameContext);
    }

    /**
     * メソッドの docblock から `@return`、`@throws`、各引数の `@param` のクラス名候補を集める。
     * コンストラクタのプロモートされた引数もここで拾える（docblock はメソッド側に書かれるため）。
     *
     * @return list<string>
     */
    private function namesFromMethodDocblock(Stmt\ClassMethod $node): array
    {
        if ($this->docblockExtractor === null || $this->nameContext === null) {
            return [];
        }

        $doc = $node->getDocComment();
        if ($doc === null) {
            return [];
        }

        $docText = $doc->getText();
        $names = [
            ...$this->docblockExtractor->extractReturnTypeNames($docText, $this->nameContext),
            ...$this->docblockExtractor->extractThrowsTypeNames($docText, $this->nameContext),
        ];

        foreach ($node->getParams() as $param) {
            if (!$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            $names = [
                ...$names,
                ...$this->docblockExtractor->extractParamTypeNames($docText, $param->var->name, $this->nameContext),
            ];
        }

        return $names;
    }

    /**
     * @param array<Node\AttributeGroup> $attrGroups
     * @return list<string>
     */
    private function namesFromAttributeGroups(array $attrGroups): array
    {
        $names = [];
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $this->appendName($names, $attribute->name);
            }
        }

        return $names;
    }
}

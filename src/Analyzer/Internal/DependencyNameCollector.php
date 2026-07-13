<?php

declare(strict_types=1);

namespace Psap\Analyzer\Internal;

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
use Psap\Analyzer\DependencyKind;

/**
 * 1つの型宣言から依存先と、その依存を作った構文の種類・行番号を集める。
 *
 * NameResolver適用後のASTを前提とし、self / parent / staticと入れ子の型宣言は除外する。
 * docblock内の短縮名は宣言位置のNameContextでFQCNへ解決する。
 *
 * @internal DependencyAnalyzer の実装詳細
 */
final class DependencyNameCollector extends NodeVisitorAbstract
{
    /** @var list<DependencyReference> */
    public array $references = [];

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

        foreach ($this->extractFrom($node) as $reference) {
            $this->references[] = $reference;
        }

        return null;
    }

    /**
     * @return list<DependencyReference>
     */
    private function extractFrom(Node $node): array
    {
        if ($node instanceof Node\Attribute) {
            return $this->reference($node->name, DependencyKind::Attribute);
        }

        if ($node instanceof ClassLike) {
            $references = [];

            if ($node instanceof Stmt\Class_) {
                if ($node->extends !== null) {
                    $this->appendName($references, $node->extends, DependencyKind::Extends);
                }
                foreach ($node->implements as $interface) {
                    $this->appendName($references, $interface, DependencyKind::Implements);
                }
            } elseif ($node instanceof Stmt\Interface_) {
                foreach ($node->extends as $interface) {
                    $this->appendName($references, $interface, DependencyKind::Extends);
                }
            } elseif ($node instanceof Stmt\Enum_) {
                foreach ($node->implements as $interface) {
                    $this->appendName($references, $interface, DependencyKind::Implements);
                }
            }

            return $references;
        }

        if ($node instanceof Stmt\TraitUse) {
            $references = [];
            foreach ($node->traits as $trait) {
                $this->appendName($references, $trait, DependencyKind::TraitUse);
            }

            return $references;
        }

        if ($node instanceof Stmt\Property) {
            $references = [];
            $this->appendType($references, $node->type, DependencyKind::PropertyType);

            return [...$references, ...$this->referencesFromVarDocblock($node)];
        }

        if ($node instanceof Param) {
            $references = [];
            $this->appendType($references, $node->type, DependencyKind::ParameterType);

            return $references;
        }

        if ($node instanceof Stmt\ClassMethod) {
            $references = [];
            $this->appendType($references, $node->returnType, DependencyKind::ReturnType);

            return [...$references, ...$this->referencesFromMethodDocblock($node)];
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $references = [];
            $this->appendType($references, $node->returnType, DependencyKind::ReturnType);

            return $references;
        }

        if ($node instanceof Expr\New_) {
            return $node->class instanceof Name
                ? $this->reference($node->class, DependencyKind::New)
                : [];
        }

        if ($node instanceof Expr\StaticCall) {
            return $node->class instanceof Name
                ? $this->reference($node->class, DependencyKind::StaticCall)
                : [];
        }

        if ($node instanceof Expr\StaticPropertyFetch) {
            return $node->class instanceof Name
                ? $this->reference($node->class, DependencyKind::StaticProperty)
                : [];
        }

        if ($node instanceof Expr\ClassConstFetch) {
            return $node->class instanceof Name
                ? $this->reference($node->class, DependencyKind::ClassConstant)
                : [];
        }

        if ($node instanceof Expr\Instanceof_) {
            return $node->class instanceof Name
                ? $this->reference($node->class, DependencyKind::Instanceof)
                : [];
        }

        if ($node instanceof Stmt\Catch_) {
            $references = [];
            foreach ($node->types as $type) {
                $this->appendName($references, $type, DependencyKind::Catch);
            }

            return $references;
        }

        return [];
    }

    /**
     * @return list<DependencyReference>
     */
    private function reference(Name $name, DependencyKind $kind): array
    {
        $references = [];
        $this->appendName($references, $name, $kind);

        return $references;
    }

    /**
     * @param list<DependencyReference> $references
     */
    private function appendName(array &$references, Name $name, DependencyKind $kind): void
    {
        if ($name->isSpecialClassName()) {
            return;
        }

        $references[] = new DependencyReference($name->toString(), $kind, $name->getStartLine());
    }

    /**
     * @param list<DependencyReference> $references
     */
    private function appendType(
        array &$references,
        ComplexType|Name|Identifier|null $type,
        DependencyKind $kind,
    ): void {
        foreach ($this->flattenType($type) as $name) {
            $this->appendName($references, $name, $kind);
        }
    }

    /**
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
     * @return list<DependencyReference>
     */
    private function referencesFromVarDocblock(Stmt\Property $node): array
    {
        if ($this->docblockExtractor === null || $this->nameContext === null) {
            return [];
        }

        $doc = $node->getDocComment();
        if ($doc === null) {
            return [];
        }

        return $this->referencesFromNames(
            $this->docblockExtractor->extractVarTypeNames($doc->getText(), $this->nameContext),
            DependencyKind::DocblockVar,
            $doc->getStartLine(),
        );
    }

    /**
     * @return list<DependencyReference>
     */
    private function referencesFromMethodDocblock(Stmt\ClassMethod $node): array
    {
        if ($this->docblockExtractor === null || $this->nameContext === null) {
            return [];
        }

        $doc = $node->getDocComment();
        if ($doc === null) {
            return [];
        }

        $docText = $doc->getText();
        $line = $doc->getStartLine();
        $references = [
            ...$this->referencesFromNames(
                $this->docblockExtractor->extractReturnTypeNames($docText, $this->nameContext),
                DependencyKind::DocblockReturn,
                $line,
            ),
            ...$this->referencesFromNames(
                $this->docblockExtractor->extractThrowsTypeNames($docText, $this->nameContext),
                DependencyKind::DocblockThrows,
                $line,
            ),
        ];

        foreach ($node->getParams() as $param) {
            if (!$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                continue;
            }
            $references = [
                ...$references,
                ...$this->referencesFromNames(
                    $this->docblockExtractor->extractParamTypeNames($docText, $param->var->name, $this->nameContext),
                    DependencyKind::DocblockParam,
                    $line,
                ),
            ];
        }

        return $references;
    }

    /**
     * @param list<string> $names
     * @return list<DependencyReference>
     */
    private function referencesFromNames(array $names, DependencyKind $kind, int $line): array
    {
        return array_map(
            static fn (string $name): DependencyReference => new DependencyReference($name, $kind, $line),
            $names,
        );
    }

}

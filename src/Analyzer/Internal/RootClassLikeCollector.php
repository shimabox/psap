<?php

declare(strict_types=1);

namespace Bobsap\Analyzer\Internal;

use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/**
 * ファイル内の「名前を持つ」トップレベルの型宣言（class / interface / enum / trait）を集める。
 *
 * 無名クラス（`new class { ... }` の class 部分）は名前が null になるため対象外とし、
 * その内部（本体）も再帰的には探索しない。
 *
 * @internal DependencyAnalyzer の実装詳細
 */
final class RootClassLikeCollector extends NodeVisitorAbstract
{
    /** @var list<ClassLike> */
    public array $roots = [];

    /** @var array<int, NameContext> ClassLike の object id → 宣言位置の名前解決コンテキスト */
    public array $nameContexts = [];

    private int $classLikeDepth = 0;

    public function __construct(
        private readonly NameResolver $nameResolver,
    ) {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            if ($this->classLikeDepth === 0 && $node->name !== null) {
                $this->roots[] = $node;
                $this->nameContexts[spl_object_id($node)] = clone $this->nameResolver->getNameContext();
            }

            $this->classLikeDepth++;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            $this->classLikeDepth--;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Bobsap\Analyzer\Internal;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
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

    public function enterNode(Node $node): int|null
    {
        if ($node instanceof ClassLike) {
            if ($node->name !== null) {
                $this->roots[] = $node;
            }

            // 型宣言の内部（無名クラスの本体を含む）は別スコープなので踏み込まない
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }
}

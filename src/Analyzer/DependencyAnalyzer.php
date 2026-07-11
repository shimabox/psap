<?php

declare(strict_types=1);

namespace Bobsap\Analyzer;

use Bobsap\Analyzer\Internal\DependencyNameCollector;
use Bobsap\Analyzer\Internal\RootClassLikeCollector;
use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * PHP ソースファイル群を解析し、型宣言ごとの依存関係（ClassInfo）を抽出する。
 *
 * nikic/php-parser で AST を構築し、NameResolver で名前を FQCN に解決した上で、
 * extends / implements / trait use / 型宣言 / new / 静的呼び出し / instanceof / catch /
 * アトリビュートから依存先を収集する。
 *
 * PHP 組み込みクラス（\Exception 等）への依存もフィルタリングせずそのまま記録する。
 * 「解析対象外への依存を無視する」判断は Phase 2（メトリクス計算）の責務。
 */
final class DependencyAnalyzer
{
    private readonly Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param list<string> $filePaths
     */
    public function analyze(array $filePaths): AnalysisResult
    {
        $classInfos = [];
        $warnings = [];

        foreach ($filePaths as $filePath) {
            $code = @file_get_contents($filePath);
            if ($code === false) {
                $warnings[] = sprintf('ファイルを読み込めませんでした: %s', $filePath);

                continue;
            }

            try {
                $ast = $this->parser->parse($code);
            } catch (Error $e) {
                $warnings[] = sprintf('パースエラーのためスキップしました: %s (%s)', $filePath, $e->getMessage());

                continue;
            }

            if ($ast === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            /** @var list<Stmt> $resolvedAst */
            $resolvedAst = $traverser->traverse($ast);

            foreach ($this->extractClassInfos($resolvedAst, $filePath) as $classInfo) {
                $classInfos[] = $classInfo;
            }
        }

        return new AnalysisResult($classInfos, $warnings);
    }

    /**
     * @param list<Stmt> $ast
     * @return list<ClassInfo>
     */
    private function extractClassInfos(array $ast, string $filePath): array
    {
        $rootCollector = new RootClassLikeCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($rootCollector);
        $traverser->traverse($ast);

        $result = [];
        foreach ($rootCollector->roots as $root) {
            $fqcn = ($root->namespacedName ?? $root->name)?->toString();
            if ($fqcn === null) {
                // 無名クラス（RootClassLikeCollector で除外済みのはずだが念のため）
                continue;
            }

            $result[] = new ClassInfo(
                fqcn: $fqcn,
                kind: $this->resolveKind($root),
                filePath: $filePath,
                dependencies: $this->collectDependencyNames($root),
            );
        }

        return $result;
    }

    private function resolveKind(ClassLike $node): TypeKind
    {
        return match (true) {
            $node instanceof Stmt\Interface_ => TypeKind::Interface_,
            $node instanceof Stmt\Trait_ => TypeKind::Trait_,
            $node instanceof Stmt\Enum_ => TypeKind::Enum_,
            $node instanceof Stmt\Class_ => $node->isAbstract() ? TypeKind::AbstractClass : TypeKind::ConcreteClass,
            default => throw new \LogicException('未知の ClassLike サブタイプです: ' . $node::class),
        };
    }

    /**
     * @return list<string>
     */
    private function collectDependencyNames(ClassLike $root): array
    {
        $collector = new DependencyNameCollector($root);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse([$root]);

        return $collector->names;
    }
}

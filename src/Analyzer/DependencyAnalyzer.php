<?php

declare(strict_types=1);

namespace Bobsap\Analyzer;

use Bobsap\Analyzer\Internal\DependencyNameCollector;
use Bobsap\Analyzer\Internal\DocblockTypeExtractor;
use Bobsap\Analyzer\Internal\RootClassLikeCollector;
use PhpParser\Error;
use PhpParser\NameContext;
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
 *
 * $useDocblock（デフォルト true）を有効にすると、プロパティの `@var` とメソッドの
 * `@param` / `@return` からもクラス名候補を収集する（`--no-docblock` で無効化できる）。
 * 短縮名の解決には NameResolver が保持する NameContext をそのまま使う。
 */
final class DependencyAnalyzer
{
    private readonly Parser $parser;
    private readonly ?DocblockTypeExtractor $docblockExtractor;

    public function __construct(?Parser $parser = null, bool $useDocblock = true)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->docblockExtractor = $useDocblock ? new DocblockTypeExtractor() : null;
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

            try {
                $nameResolver = new NameResolver();
                $rootCollector = new RootClassLikeCollector($nameResolver);
                $traverser = new NodeTraverser();
                $traverser->addVisitor($nameResolver);
                $traverser->addVisitor($rootCollector);
                $traverser->traverse($ast);

                foreach ($this->extractClassInfos($rootCollector, $filePath) as $classInfo) {
                    $classInfos[] = $classInfo;
                }
            } catch (Error $e) {
                $warnings[] = sprintf('名前解決エラーのためスキップしました: %s (%s)', $filePath, $e->getMessage());
            }
        }

        return new AnalysisResult($classInfos, $warnings);
    }

    /**
     * @return list<ClassInfo>
     */
    private function extractClassInfos(RootClassLikeCollector $rootCollector, string $filePath): array
    {
        $result = [];
        foreach ($rootCollector->roots as $root) {
            $fqcn = ($root->namespacedName ?? $root->name)?->toString();
            if ($fqcn === null) {
                // 無名クラス（RootClassLikeCollector で除外済みのはずだが念のため）
                continue;
            }

            $nameContext = $rootCollector->nameContexts[spl_object_id($root)];
            $result[] = new ClassInfo(
                fqcn: $fqcn,
                kind: $this->resolveKind($root),
                filePath: $filePath,
                dependencies: $this->collectDependencyNames($root, $nameContext),
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
    private function collectDependencyNames(ClassLike $root, NameContext $nameContext): array
    {
        $collector = new DependencyNameCollector($root, $this->docblockExtractor, $nameContext);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse([$root]);

        return $collector->names;
    }
}

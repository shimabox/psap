<?php

declare(strict_types=1);

namespace Psap\Analyzer;

use PhpParser\Error;
use PhpParser\NameContext;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psap\Analyzer\Internal\DependencyNameCollector;
use Psap\Analyzer\Internal\DependencyReference;
use Psap\Analyzer\Internal\DocblockTypeExtractor;
use Psap\Analyzer\Internal\RootClassLikeCollector;
use Psap\Diagnostic\Diagnostic;
use Psap\Diagnostic\DiagnosticAction;
use Psap\Diagnostic\DiagnosticCode;
use Psap\Diagnostic\DiagnosticSeverity;

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
 * `@param` / `@return` / `@throws` からもクラス名候補を収集する（`--no-docblock` で無効化できる）。
 * 短縮名の解決には NameResolver が保持する NameContext をそのまま使う。
 */
final class DependencyAnalyzer
{
    private readonly Parser $parser;
    private readonly ?DocblockTypeExtractor $docblockExtractor;
    /** @var list<string> */
    private readonly array $sourceRoots;

    /**
     * @param list<string> $sourceRoots 依存根拠のファイルパスを相対化する基準
     */
    public function __construct(?Parser $parser = null, bool $useDocblock = true, array $sourceRoots = [])
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->docblockExtractor = $useDocblock ? new DocblockTypeExtractor() : null;
        $roots = array_map(
            static function (string $path): string {
                $normalized = realpath($path) ?: $path;
                $trimmed = rtrim($normalized, DIRECTORY_SEPARATOR);

                return $trimmed === '' ? DIRECTORY_SEPARATOR : $trimmed;
            },
            $sourceRoots,
        );
        usort($roots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->sourceRoots = array_values(array_unique($roots));
    }

    /**
     * @param list<string> $filePaths
     */
    public function analyze(array $filePaths): AnalysisResult
    {
        $classInfos = [];
        $diagnostics = [];
        $analyzedFileCount = 0;
        $skippedFileCount = 0;

        foreach ($filePaths as $filePath) {
            $code = @file_get_contents($filePath);
            if ($code === false) {
                $diagnostics[] = new Diagnostic(
                    code: DiagnosticCode::SourceReadFailed,
                    severity: DiagnosticSeverity::Warning,
                    file: $this->relativeFilePath($filePath),
                    actions: [DiagnosticAction::CheckPermissions, DiagnosticAction::ExcludeFile],
                );
                ++$skippedFileCount;

                continue;
            }

            $invalidUtf8Line = $this->firstInvalidUtf8Line($code);
            if ($invalidUtf8Line !== null) {
                $diagnostics[] = new Diagnostic(
                    code: DiagnosticCode::SourceInvalidUtf8,
                    severity: DiagnosticSeverity::Warning,
                    file: $this->relativeFilePath($filePath),
                    line: $invalidUtf8Line,
                    actions: [DiagnosticAction::ConvertToUtf8, DiagnosticAction::ExcludeFile],
                );
                ++$skippedFileCount;

                continue;
            }

            try {
                $ast = $this->parser->parse($code);
            } catch (Error $e) {
                $diagnostics[] = new Diagnostic(
                    code: DiagnosticCode::SourceParseFailed,
                    severity: DiagnosticSeverity::Warning,
                    file: $this->relativeFilePath($filePath),
                    line: $this->errorLine($e),
                    context: ['detail' => $e->getMessage()],
                    actions: [DiagnosticAction::FixSource, DiagnosticAction::ExcludeFile],
                );
                ++$skippedFileCount;

                continue;
            }

            if ($ast === null) {
                ++$analyzedFileCount;

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
                ++$analyzedFileCount;
            } catch (Error $e) {
                $diagnostics[] = new Diagnostic(
                    code: DiagnosticCode::SourceNameResolutionFailed,
                    severity: DiagnosticSeverity::Warning,
                    file: $this->relativeFilePath($filePath),
                    line: $this->errorLine($e),
                    context: ['detail' => $e->getMessage()],
                    actions: [DiagnosticAction::FixSource, DiagnosticAction::ExcludeFile],
                );
                ++$skippedFileCount;
            }
        }

        [$classInfos, $duplicateDiagnostics] = $this->mergeDuplicateDeclarations($classInfos);

        return new AnalysisResult(
            classInfos: $classInfos,
            diagnostics: [...$diagnostics, ...$duplicateDiagnostics],
            analyzedFileCount: $analyzedFileCount,
            skippedFileCount: $skippedFileCount,
        );
    }

    private function firstInvalidUtf8Line(string $code): ?int
    {
        if (preg_match('//u', $code) === 1) {
            return null;
        }

        foreach (explode("\n", $code) as $index => $line) {
            if (preg_match('//u', $line) !== 1) {
                return $index + 1;
            }
        }

        return 1;
    }

    private function errorLine(Error $error): ?int
    {
        $line = $error->getStartLine();

        return $line > 0 ? $line : null;
    }

    /**
     * 条件分岐による互換実装など、同じFQCNを持つ宣言を1型へまとめる。
     *
     * @param list<ClassInfo> $classInfos
     * @return array{list<ClassInfo>, list<Diagnostic>}
     */
    private function mergeDuplicateDeclarations(array $classInfos): array
    {
        /** @var array<string, ClassInfo> $mergedByFqcn */
        $mergedByFqcn = [];
        $diagnostics = [];

        foreach ($classInfos as $classInfo) {
            $key = strtolower($classInfo->fqcn);
            $existing = $mergedByFqcn[$key] ?? null;
            if ($existing === null) {
                $mergedByFqcn[$key] = $classInfo;

                continue;
            }

            if ($existing->kind !== $classInfo->kind) {
                $diagnostics[] = new Diagnostic(
                    code: DiagnosticCode::DeclarationKindConflict,
                    severity: DiagnosticSeverity::Warning,
                    file: $this->relativeFilePath($classInfo->filePath),
                    context: [
                        'fqcn' => $existing->fqcn,
                        'existingKind' => $existing->kind->label(),
                        'duplicateKind' => $classInfo->kind->label(),
                        'existingFile' => $this->relativeFilePath($existing->filePath),
                    ],
                    actions: [DiagnosticAction::ReviewDuplicate],
                );
            } elseif ($existing->filePath !== $classInfo->filePath) {
                $diagnostics[] = new Diagnostic(
                    code: DiagnosticCode::DeclarationDuplicateFqcn,
                    severity: DiagnosticSeverity::Warning,
                    file: $this->relativeFilePath($classInfo->filePath),
                    context: [
                        'fqcn' => $existing->fqcn,
                        'existingFile' => $this->relativeFilePath($existing->filePath),
                    ],
                    actions: [DiagnosticAction::ReviewDuplicate],
                );
            }

            $mergedByFqcn[$key] = new ClassInfo(
                fqcn: $existing->fqcn,
                kind: $existing->kind,
                filePath: $existing->filePath,
                dependencies: [...$existing->dependencies, ...$classInfo->dependencies],
                dependencyEvidence: [...$existing->dependencyEvidence, ...$classInfo->dependencyEvidence],
            );
        }

        return [array_values($mergedByFqcn), $diagnostics];
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
            $references = $this->collectDependencyReferences($root, $nameContext);
            $result[] = new ClassInfo(
                fqcn: $fqcn,
                kind: $this->resolveKind($root),
                filePath: $filePath,
                dependencies: array_map(
                    static fn (DependencyReference $reference): string => $reference->fqcn,
                    $references,
                ),
                dependencyEvidence: array_map(
                    fn (DependencyReference $reference): DependencyEvidence => new DependencyEvidence(
                        targetFqcn: $reference->fqcn,
                        kind: $reference->kind,
                        file: $this->relativeFilePath($filePath),
                        line: $reference->line,
                    ),
                    $references,
                ),
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
     * @return list<DependencyReference>
     */
    private function collectDependencyReferences(ClassLike $root, NameContext $nameContext): array
    {
        $collector = new DependencyNameCollector($root, $this->docblockExtractor, $nameContext);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse([$root]);

        return $collector->references;
    }

    private function relativeFilePath(string $filePath): string
    {
        $normalizedFile = realpath($filePath) ?: $filePath;
        foreach ($this->sourceRoots as $root) {
            if ($normalizedFile === $root) {
                return basename($normalizedFile);
            }
            if (str_starts_with($normalizedFile, $root . DIRECTORY_SEPARATOR)) {
                return str_replace(DIRECTORY_SEPARATOR, '/', substr($normalizedFile, strlen($root) + 1));
            }
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $filePath);
    }
}

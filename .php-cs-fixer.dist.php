<?php

// コーディングスタイル設定。@PSR12 をベースに、控えめな追加ルールのみ足す。

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    // tests/Fixtures は解析対象データ（意図的な構文エラーファイルを含む）であり、
    // プロダクトコードではないため cs-fixer の対象から除外する
    ->exclude('Fixtures');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);

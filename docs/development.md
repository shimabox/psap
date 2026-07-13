# 開発

ホスト側にPHPやComposerは不要です。

## コマンド

```bash
make setup
make test
make stan
make cs
make cs-fix
make phar
make build-dist
make build-plantuml
```

このリポジトリのCIはPHP 8.5でテスト、PHPStan、PHP CS Fixerを実行します。続いてpsap自身を解析し、各形式のレポートをArtifactとStep Summaryへ出力します。

## 処理の流れ

```text
SourceFinder -> DependencyAnalyzer -> ComponentClassifier -> MetricsCalculator -> Reporter
                                          |
                                          +-> DependencyGraph -> CycleDetector
```

解析、分類、計測、出力を分けています。新しい出力形式は`ReporterInterface`を実装し、`AnalyzeCommand`のファクトリへ追加します。

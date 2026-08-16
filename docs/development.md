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

このリポジトリのCIはPHP 8.3 / 8.4 / 8.5のマトリクスでテストとPHPStanを実行します。PHP CS Fixerは最低対応バージョンの8.3でのみ実行します（ローカルの`make cs` / `make cs-fix`も専用の`cs`サービス経由でPHP 8.3で動きます）。続いてpsap自身を解析し、各形式のレポートをArtifactとStep Summaryへ出力します。

## 処理の流れ

```text
SourceFinder -> DependencyAnalyzer -> ComponentClassifier -> MetricsCalculator -> Reporter
                                          |
                                          +-> DependencyGraph -> CycleDetector
                                          |
                                          +-> OutOfScopeDependencyCollector
```

解析、分類、計測、出力を分けています。新しい出力形式は`ReporterInterface`を実装し、`AnalyzeCommand`のファクトリへ追加します。

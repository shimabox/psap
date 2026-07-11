# bobsap

PHPコードを解析し、Stable Abstractions Principle（SAP）のメトリクスを名前空間ごとに計測するCLIツールです。テキスト、JSON、Mermaid、PlantUMLで結果を出力でき、閾値や循環依存をCIのゲートにできます。

## インストール

### Docker

配布用イメージをビルドし、解析対象のプロジェクトを`/workdir`へマウントします。

```bash
docker build -t bobsap --target dist -f docker/Dockerfile .
docker run --rm -v "$PWD":/workdir bobsap analyze src/
```

PlantUMLからPNGまで生成する場合は、PlantUML、Java、Graphviz、CJKフォントを含むイメージを使います。

```bash
docker build -t bobsap:plantuml --target dist-plantuml -f docker/Dockerfile .
docker run --rm -v "$PWD":/workdir bobsap:plantuml analyze-png src/ --depth 2
```

実行するとカレントディレクトリに`bobsap-report.png`が作成されます。

### PHAR

```bash
make phar
php bobsap.phar analyze src/
```

### Composer

Packagistにはまだ公開していません。GitHubリポジトリを直接指定してインストールできます。

```bash
composer config repositories.bobsap vcs https://github.com/shimabox/bobsap
composer require --dev shimabox/bobsap:dev-main
vendor/bin/bobsap analyze src/
```

PHP 8.5以降が必要です。

## メトリクス

| 指標 | 計算 | 意味 |
|---|---|---|
| Ca | 外部から内部へ依存するクラス数 | 大きいほど安定 |
| Ce | 内部から外部へ依存するクラス数 | 大きいほど不安定 |
| I | `Ce / (Ca + Ce)` | 0が最安定、1が最不安定 |
| A | `抽象型数 / 総型数` | 0が具象のみ、1が抽象のみ |
| D | `\|A + I - 1\|` | 主系列からの距離 |

`interface`と`abstract class`を抽象型として数えます。`class`、`enum`、`trait`は具象型です。

コンポーネントは名前空間単位です。`--depth 2`の場合、`App\Domain\Model\User`は`App\Domain`に属します。解析対象外のクラスへの依存はCaとCeに含めません。

## 使い方

```text
bobsap analyze <paths>... [options]
```

| オプション | 説明 | 初期値 |
|---|---|---|
| `--depth` | 名前空間を束ねる深さ | `2` |
| `--format` | `text`、`json`、`mermaid`、`plantuml` | `text` |
| `--output` | 出力先ファイル | 標準出力 |
| `--exclude` | fnmatch形式の除外パターン。複数指定可 | なし |
| `--threshold` | Dが指定値を超えた場合に失敗 | なし |
| `--fail-on-cycle` | 循環依存が見つかった場合に失敗 | 無効 |
| `--no-docblock` | docblockからの依存抽出を無効化 | 無効 |

複数のディレクトリと除外パターンを指定できます。

```bash
bobsap analyze src/ packages/ --depth 3 --exclude 'Generated/*' --exclude '*/Tests/*'
```

Dの閾値と循環依存を同時に検査できます。

```bash
bobsap analyze src/ --threshold 0.6 --fail-on-cycle
```

終了コードは、成功が`0`、ゲート違反が`1`、入力エラーが`2`です。

## 出力

テキスト形式ではCa、Ce、I、A、Dとゾーン判定を表で表示します。

```text
bobsap - Stable Abstractions Principle metrics

Component         Classes  Ca  Ce     I     A     D  Zone
----------------  -------  --  --  ----  ----  ----  ----------
App\Domain              8   3   1  0.25  0.25  0.50
App\Infrastructure      4   0   3  1.00  0.00  0.00

Statistics: mean(D)=0.25, variance(D)=0.06
```

`-v`を付けると、各コンポーネントに属するクラスも表示します。JSONにはコンポーネント間の依存と、その根拠になるクラス間依存も含まれます。

MermaidはIとAの散布図を生成します。依存エッジや循環依存を図示する場合はPlantUMLを使ってください。

解析結果が1コンポーネントにまとまり、より深い名前空間がある場合は`--depth`の見直しを促す警告が出ます。

```bash
bobsap analyze src/ --format json --output report.json
bobsap analyze src/ --format mermaid --output report.mmd
bobsap analyze src/ --format plantuml --output report.puml
```

## 解析範囲

ASTから次の参照を抽出します。

- 継承、インターフェイス、trait
- プロパティ、引数、戻り値の型
- union型、intersection型、nullable型
- `new`、静的呼び出し、クラス定数
- `instanceof`、`catch`
- PHP Attribute
- `@var`、`@param`、`@return`

docblockでは配列、generic、union、intersection、nullable型を分解し、`use`と名前空間を使ってクラス名を解決します。壊れたdocblockは無視されます。

次の参照は対象外です。

- `class_exists('X')`などの文字列参照
- `new $className`などの動的参照
- 無名クラスの宣言と内部依存
- 解析対象パス外のクラス

設定ファイル、ディレクトリベースのコンポーネント分類、HTMLレポートには対応していません。

## CI

```yaml
name: bobsap
on: [pull_request]
jobs:
  metrics:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: docker build -t bobsap --target dist -f docker/Dockerfile .
      - run: docker run --rm -v "$PWD":/workdir bobsap analyze src/ --threshold 0.6 --fail-on-cycle
```

このリポジトリのCIはPHP 8.5でテスト、PHPStan、PHP CS Fixerを実行します。続いてbobsap自身を解析し、各形式のレポートをArtifactとStep Summaryへ出力します。

## 開発

ホスト側にPHPやComposerは不要です。

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

コードは処理の段階ごとに分かれています。

```text
SourceFinder -> DependencyAnalyzer -> ComponentClassifier -> MetricsCalculator -> Reporter
                                          |
                                          +-> DependencyGraph -> CycleDetector
```

## ライセンス

[MIT License](LICENSE)

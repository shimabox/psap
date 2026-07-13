# 導入と基本操作

## Docker

リポジトリを取得し、配布用イメージをビルドします。

```bash
git clone https://github.com/shimabox/psap.git
docker build -t psap --target dist -f psap/docker/Dockerfile psap
```

解析対象のPHPプロジェクトへ移動して実行します。

```bash
docker run --rm -v "$PWD":/workdir psap analyze src/
```

複数のディレクトリをまとめて解析できます。

```bash
docker run --rm -v "$PWD":/workdir psap \
  analyze src/ packages/ --depth 3 --exclude 'Generated/*' --exclude '*/Tests/*'
```

## PHAR

リポジトリ内でPHARを作成します。

```bash
make phar
php psap.phar analyze src/
```

## Composer

Packagistにはまだ公開していません。GitHubリポジトリを指定してインストールします。

```bash
composer config repositories.psap vcs https://github.com/shimabox/psap
composer require --dev shimabox/psap:dev-main
vendor/bin/psap analyze src/
```

PHP 8.5以降が必要です。

## コマンド

```text
psap analyze <paths>... [options]
```

| オプション | 内容 | 初期値 |
|---|---|---|
| `--depth` | 名前空間を束ねる深さ。`auto`または1以上の整数 | `auto` |
| `--format` | `text`、`json`、`markdown`、`html`、`mermaid`、`plantuml` | `text` |
| `--output` | 出力先ファイル | 標準出力 |
| `--exclude` | fnmatch形式の除外パターン。複数指定可 | なし |
| `--threshold` | Dが指定値を超えた場合に失敗 | なし |
| `--fail-on-cycle` | 循環依存が見つかった場合に失敗 | 無効 |
| `--generate-cycle-baseline` | 現在の循環をベースラインへ保存 | なし |
| `--cycle-baseline` | ベースラインと循環を比較 | なし |
| `--no-docblock` | docblockからの依存抽出を無効化 | 無効 |

`--depth`はファイル探索の深さではなく、名前空間を束ねる階層です。`--depth auto`は共通名前空間の次の階層を選びます。まずは`auto`を使い、解析結果が1コンポーネントになる場合や、独立した責務が同じコンポーネントにまとまる場合に1段ずつ増やしてください。depthを増やすと内部依存が見える一方、コンポーネント数、計算量、レポートサイズも増える可能性があります。詳しい選び方と性能特性は[解析内容と出力形式](analysis.md#名前空間深度の選び方)を参照してください。

終了コードは、成功が`0`、閾値や循環の違反が`1`、入力エラーが`2`です。

## ファイルへの出力

```bash
psap analyze src/ --format text --output report.txt
psap analyze src/ --format json --output report.json
psap analyze src/ --format markdown --output report.md
psap analyze src/ --format html --output report.html
psap analyze src/ --format mermaid --output report.mmd
psap analyze src/ --format plantuml --output report.puml
```

`report.html`は外部アセットを必要としないため、そのままブラウザで開けます。点を選ぶと、名前空間コンポーネントの指標と所属クラスを確認できます。

PlantUMLからPNGまで生成する場合は、PlantUML、Java、Graphviz、CJKフォントを含むイメージを使います。

```bash
docker build -t psap:plantuml --target dist-plantuml -f docker/Dockerfile .
docker run --rm -v "$PWD":/workdir psap:plantuml analyze-png src/ --depth 2
```

カレントディレクトリに`psap-report.png`が作成されます。

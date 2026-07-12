# 導入と基本操作

## Docker

リポジトリを取得し、配布用イメージをビルドします。

```bash
git clone https://github.com/shimabox/bobsap.git
docker build -t bobsap --target dist -f bobsap/docker/Dockerfile bobsap
```

解析対象のPHPプロジェクトへ移動して実行します。

```bash
docker run --rm -v "$PWD":/workdir bobsap analyze src/
```

複数のディレクトリをまとめて解析できます。

```bash
docker run --rm -v "$PWD":/workdir bobsap \
  analyze src/ packages/ --depth 3 --exclude 'Generated/*' --exclude '*/Tests/*'
```

## PHAR

リポジトリ内でPHARを作成します。

```bash
make phar
php bobsap.phar analyze src/
```

## Composer

Packagistにはまだ公開していません。GitHubリポジトリを指定してインストールします。

```bash
composer config repositories.bobsap vcs https://github.com/shimabox/bobsap
composer require --dev shimabox/bobsap:dev-main
vendor/bin/bobsap analyze src/
```

PHP 8.5以降が必要です。

## コマンド

```text
bobsap analyze <paths>... [options]
```

| オプション | 内容 | 初期値 |
|---|---|---|
| `--depth` | 名前空間を束ねる深さ。`auto`または1以上の整数 | `auto` |
| `--format` | `text`、`json`、`markdown`、`mermaid`、`plantuml` | `text` |
| `--output` | 出力先ファイル | 標準出力 |
| `--exclude` | fnmatch形式の除外パターン。複数指定可 | なし |
| `--threshold` | Dが指定値を超えた場合に失敗 | なし |
| `--fail-on-cycle` | 循環依存が見つかった場合に失敗 | 無効 |
| `--generate-cycle-baseline` | 現在の循環をベースラインへ保存 | なし |
| `--cycle-baseline` | ベースラインと循環を比較 | なし |
| `--no-docblock` | docblockからの依存抽出を無効化 | 無効 |

`--depth auto`は共通名前空間の次の階層を選びます。解析結果が1コンポーネントだけになる場合は、必要に応じて`--depth`を増やしてください。

終了コードは、成功が`0`、閾値や循環の違反が`1`、入力エラーが`2`です。

## ファイルへの出力

```bash
bobsap analyze src/ --format text --output report.txt
bobsap analyze src/ --format json --output report.json
bobsap analyze src/ --format markdown --output report.md
bobsap analyze src/ --format mermaid --output report.mmd
bobsap analyze src/ --format plantuml --output report.puml
```

PlantUMLからPNGまで生成する場合は、PlantUML、Java、Graphviz、CJKフォントを含むイメージを使います。

```bash
docker build -t bobsap:plantuml --target dist-plantuml -f docker/Dockerfile .
docker run --rm -v "$PWD":/workdir bobsap:plantuml analyze-png src/ --depth 2
```

カレントディレクトリに`bobsap-report.png`が作成されます。

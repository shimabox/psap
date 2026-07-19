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

### Dockerで解析対象と出力先を指定する

Dockerでは、ホスト側のディレクトリをコンテナ内のパスへ割り当てます。次のコマンドでは、現在のディレクトリをコンテナ内の`/workdir`として見せています。

```bash
docker run --rm \
  -v "$PWD":/workdir \
  psap \
  analyze src/ \
  --format markdown \
  --output psap-report.md
```

各指定の意味は次のとおりです。

| 指定 | 意味 |
|---|---|
| `"$PWD"` | コマンドを実行しているホスト側のディレクトリ |
| `/workdir` | そのディレクトリをコンテナ内で参照するためのパス |
| `psap` | 実行するDockerイメージ名。解析対象のパスではない |
| `analyze src/` | コンテナ内の`/workdir/src/`を解析する |
| `--output psap-report.md` | コンテナ内の`/workdir/psap-report.md`へ出力する |

psapイメージの作業ディレクトリは`/workdir`です。そのため、`src/`や`psap-report.md`のような相対パスは`/workdir`を基準に解決されます。`/workdir`は`"$PWD"`からマウントされているため、結果はホスト側の`$PWD/psap-report.md`に残ります。

たとえば、ホスト側のプロジェクトが`/path/to/project`にある場合は、次の対応になります。

| ホスト側 | コンテナ内 |
|---|---|
| `/path/to/project` | `/workdir` |
| `/path/to/project/src` | `/workdir/src` |
| `/path/to/project/psap-report.md` | `/workdir/psap-report.md` |

#### `/path/to/dir`全体を解析する

現在いるディレクトリに関係なく、解析したい場所を絶対パスで指定できます。

```bash
docker run --rm \
  -v "/path/to/dir":/workdir \
  psap \
  analyze . \
  --format markdown \
  --output psap-report.md
```

`analyze .`は、マウント先の`/workdir`全体を解析します。結果はホスト側の`/path/to/dir/psap-report.md`へ出力されます。

プロジェクト全体ではなく`/path/to/project/src`だけを解析する場合は、プロジェクトをマウントして`analyze src/`を指定します。

```bash
docker run --rm \
  -v "/path/to/project":/workdir \
  psap \
  analyze src/ \
  --format html \
  --output psap-report.html
```

#### 出力先を別のディレクトリにする

解析対象と出力先を別々にマウントします。解析対象を`:ro`で読み取り専用にすると、psapがソースディレクトリへ書き込まないことも明示できます。

```bash
mkdir -p "/path/to/reports"
docker run --rm \
  -v "/path/to/dir":/target:ro \
  -v "/path/to/reports":/reports \
  psap \
  analyze /target \
  --format markdown \
  --output /reports/psap-report.md
```

この場合の対応は次のとおりです。

| 用途 | ホスト側 | コンテナ内 |
|---|---|---|
| 解析対象 | `/path/to/dir` | `/target` |
| 出力先 | `/path/to/reports` | `/reports` |
| 生成されるファイル | `/path/to/reports/psap-report.md` | `/reports/psap-report.md` |

`--output`で指定したコンテナ内の場所がホスト側へマウントされていない場合、`docker run --rm`の終了時に生成ファイルもコンテナと一緒に削除されます。結果を残すには、上記の`/workdir`または`/reports`のように、出力先を必ずホスト側のディレクトリへマウントしてください。

### 複数のディレクトリを解析する

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
| `--format` | `text`、`json`、`markdown`、`html`、`mermaid`、`plantuml`、`portal` | `text` |
| `--output` | 出力先ファイル | 標準出力 |
| `--exclude` | fnmatch形式の除外パターン。複数指定可 | なし |
| `--threshold` | Dが指定値を超えた場合に失敗 | なし |
| `--fail-on-cycle` | 循環依存が見つかった場合に失敗 | 無効 |
| `--generate-cycle-baseline` | 現在の循環をベースラインへ保存 | なし |
| `--cycle-baseline` | ベースラインと循環を比較 | なし |
| `--no-docblock` | docblockからの依存抽出を無効化 | 無効 |

`--depth`はファイル探索の深さではなく、名前空間を束ねる階層です。`--depth auto`は共通名前空間の次の階層を選びます。まずは`auto`を使い、解析結果が1コンポーネントになる場合や、独立した責務が同じコンポーネントにまとまる場合に1段ずつ増やしてください。depthを増やすと内部依存が見える一方、コンポーネント数、計算量、レポートサイズも増える可能性があります。詳しい選び方と性能特性は[解析内容と出力形式](analysis.md#名前空間深度の選び方)を参照してください。

終了コードは、成功が`0`、閾値や循環の違反が`1`、入力エラーが`2`です。

## 入力ファイルの文字コード

PHPソースはUTF-8として解析します。UTF-8として解釈できないファイルは、クラス名などの識別子を誤って変換しないようにスキップし、対象パス、不正なバイト列を含む最初の行番号、対処方法を標準エラーへ表示します。text、Markdown、JSON、HTML形式ではレポート内の診断欄にも記録されます。

警告されたファイルはUTF-8へ変換するか、アプリケーションのクラス構造を見るうえで不要なfixtureや生成データであれば`--exclude`で解析対象から除外してください。

## ファイルへの出力

```bash
psap analyze src/ --format text --output report.txt
psap analyze src/ --format json --output report.json
psap analyze src/ --format markdown --output report.md
psap analyze src/ --format html --output report.html
psap analyze src/ --format mermaid --output report.mmd
psap analyze src/ --format plantuml --output report.puml
psap analyze src/ --format portal --output psap-portal.html
```

`report.html`は外部アセットを必要としないため、そのままブラウザで開けます。点を選ぶと、名前空間コンポーネントの指標と所属クラスを確認できます。

`portal`は、サマリー・インタラクティブI/Aグラフ・Mermaid図・循環詳細・図ソースを1つの自己完結HTMLにまとめた入口です。標準名は`psap-portal.html`を推奨します。Diagramsタブの`quadrantChart`と依存フローチャートは、同梱したMermaidがブラウザ内で描画するため、追加ツールなしにその場で図として確認できます。図は`+`/`−`/Resetボタン、Ctrl/Cmd+スクロール、ドラッグで拡縮・移動できます。解析結果もMermaid本体もファイル内に含まれるので、オフラインで開けて外部への通信は発生しません。Mermaidを同梱する分、出力サイズは`html`より大きく、+3.5MB前後になります。依存フローチャートのエッジ数が500を超える場合は、ブラウザ内描画を省略してソース表示へフォールバックします。SourcesタブからはMermaid（`.mmd`）・PlantUML（`.puml`）の図ソースに加えて、Markdownレポート（`psap-report.md`）もコピー・ダウンロードできます。

```bash
docker run --rm -v "$PWD":/workdir psap \
  analyze src/ --format portal --output psap-portal.html
```

PlantUMLからPNGまで生成する場合は、PlantUML、Java、Graphviz、CJKフォントを含むイメージを使います。

```bash
docker build -t psap:plantuml --target dist-plantuml -f docker/Dockerfile .
docker run --rm -v "$PWD":/workdir psap:plantuml analyze-png src/ --depth 2
```

カレントディレクトリに`psap-report.png`が作成されます。

# psap

`psap`（PHP SAP）は、PHP コードベースを解析して *Clean Architecture*（Robert C. Martin 著）第14章「安定度・抽象度等価の原則（SAP: Stable Abstractions Principle）」のメトリクス（Ca / Ce / I / A / D）を計測する CLI ツールです。

設計が変更しづらくなっている場所、依存が集中している場所、循環依存の原因を見つけるために使います。解析はローカルで完結し、外部サービスへソースコードを送りません。

## 分かること

- 名前空間ごとの安定度と抽象度
- 依存が集中しているコンポーネント
- 循環依存を構成する具体的な経路
- 依存が発生した構文、ファイル、行番号
- 以前の解析から新しく増えた循環依存

## おすすめの使い方

最も有効なのは、既存プロジェクトのMarkdownレポートを作り、コードへアクセスできる生成AIと一緒に設計上の問題を確認する使い方です。

### 1 インストール

Dockerイメージをビルドします。

```bash
git clone https://github.com/shimabox/psap.git
docker build -t psap --target dist -f psap/docker/Dockerfile psap
```

インストールできたことを確認します。

```bash
docker run --rm psap --version
```

### 2 レポートを作る

解析したいPHPプロジェクトへ移動し、ソースディレクトリを指定します。

```bash
cd /path/to/your-project
docker run --rm -v "$PWD":/workdir psap \
  analyze src/ --format markdown --output psap-report.md
```

### 3 結果を確認する

レポートが作成されたことを確認します。

```bash
sed -n '1,120p' psap-report.md
```

最初に`Review Priorities`を読み、次に`Circular Dependencies`と`Dependency Hotspots`を確認します。循環依存には、原因となるクラス、構文、ファイル、行番号が表示されます。

IとAの分布をブラウザで確認する場合は、自己完結HTMLも生成できます。

```bash
docker run --rm -v "$PWD":/workdir psap \
  analyze src/ --format html --output psap-report.html
```

グラフの点へマウスを重ねると指標を確認でき、選択するとその名前空間コンポーネントに含まれるクラスを一覧できます。検索、ゾーン、最小Dによる絞り込みにも対応しています。

HTMLは実際のゾーン判定を円弧で表示します。Mermaidの`quadrantChart`は同じゾーンを象限として近似表示するため、形は異なりますが点の指標と座標は共通です。

### 4 生成AIへ渡す

Codexなど、解析対象のコードを読める生成AIに次のように依頼します。

```text
psap-report.mdを読んで、優先して直すべき問題を3件挙げてください。
レポートに記載されたソースコードも確認し、意図的な依存か問題のある依存かを判断してください。
それぞれについて、判断の根拠、影響、具体的な修正方針を示してください。
まだコードは変更しないでください。
```

レポートだけを渡す場合は、生成AIがソースコードを確認できないことも伝えてください。その場合の提案は、調査の出発点として扱います。

### 5 コードで確かめる

生成AIの提案と、レポートに記載されたファイル・行番号を照らし合わせます。循環依存が実際に不要か、名前空間の分け方が適切か、変更後の責務が明確になるかを確認してから修正します。

修正後に同じコマンドを再実行し、循環や依存が減ったことを確認します。継続して監視したい場合は、同じ解析条件をCIへ追加します。

<details>
<summary>レポートで得られる内容</summary>

名前空間ごとのクラス数、Ca、Ce、I、A、D、問題領域を一覧できます。

```text
Component         Classes  Ca  Ce     I     A     D  Zone
----------------  -------  --  --  ----  ----  ----  ----------
App\Domain              8   3   1  0.25  0.25  0.50
App\Infrastructure      4   0   3  1.00  0.00  0.00
```

循環依存がある場合は、原因となるクラスとコード位置まで表示します。

```text
App\Domain\Order -> App\Infrastructure\OrderRepository
  parameter_type at Domain/Order.php:18
```

Markdownレポートには、優先して確認する箇所、循環依存、依存の多い箇所、全コンポーネントの指標がまとまります。

</details>

<details>
<summary>コンポーネントが1件だけになる場合</summary>

警告に従って`--depth`を増やします。

```bash
docker run --rm -v "$PWD":/workdir psap \
  analyze src/ --depth 3 --format markdown --output psap-report.md
```

深さを増やしても1件の場合は、名前空間が分割されていないか、解析対象の指定が狭すぎる可能性があります。

</details>

<details>
<summary>複数のソースディレクトリを解析する場合</summary>

解析対象のパスを続けて指定します。

```bash
docker run --rm -v "$PWD":/workdir psap \
  analyze src/ packages/ --format markdown --output psap-report.md
```

</details>

## CIで使う

Dが閾値を超えた場合や、循環依存が見つかった場合に終了コード`1`を返せます。

```bash
docker run --rm -v "$PWD":/workdir psap \
  analyze src/ --threshold 0.6 --fail-on-cycle
```

既存の循環をベースラインとして保存し、新しく増えた循環だけを検出することもできます。

## ドキュメント

- [導入と基本操作](docs/getting-started.md)
- [解析内容と出力形式](docs/analysis.md)
- [CIでの利用](docs/ci.md)
- [開発](docs/development.md)

PHP 8.5以降が必要です。Dockerを使う場合、ホスト側のPHPは不要です。

## ライセンス

[MIT License](LICENSE)

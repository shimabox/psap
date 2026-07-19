# ポータルレポート（--format portal）の設計計画

## この計画の結論

`analyze --format portal` を追加し、1回の解析で「入口となる単一の自己完結HTML」を生成する。ポータルには次を含める。

1. **サマリー** — 解析カバレッジ、コンポーネント数、循環数、Review Priorities 相当の要約
2. **インタラクティブ I/A グラフ** — 既存 HTML レポートをそのまま埋め込む（iframe srcdoc）
3. **図** — Mermaid quadrantChart（I/A 散布図）と、新設する Mermaid flowchart（依存コンポーネント図）をブラウザ内で描画
4. **循環依存の詳細** — 経路、原因クラス、ファイル、行番号
5. **図のソース** — Mermaid / PlantUML ソースの表示・コピー・ダウンロード

図の描画は同梱した mermaid.js で行い、**外部サービスへの通信は一切発生させない**。PlantUML はブラウザだけでは描画できないため、依存図は Mermaid flowchart で代替し、PlantUML はソース提供のみとする。

実装は2つのPRに分ける。先に Mermaid 依存フローチャートの生成器を確定し、その後でポータル本体を実装する。

## 決定済み事項

2026-07-18 に以下を決定済み。

| 論点 | 決定 |
|---|---|
| 出力形態 | 単一の自己完結HTML（ディレクトリ出力はしない） |
| PlantUML の扱い | 図としては Mermaid flowchart で代替。PlantUML ソースは表示・コピー用に併載 |
| CLI | 新サブコマンドではなく `--format portal` を追加 |

## 判断用サマリー

| 判断項目 | 推奨 |
|---|---|
| この機能を実施するか | 実施を推奨する。現状は形式ごとに4回実行して各ファイルを別々のビューアで開く必要があり、全体像の確認コストが高い |
| 最初に届ける価値 | 1回の実行で、グラフ・図・循環詳細へ1ファイルからたどり着けること |
| 最大の設計リスク | mermaid.js 同梱により「自己完結・外部送信なし」の約束を守れるかの検証漏れ（CDN 参照の混入、phar へのアセット同梱漏れ） |
| リスクへの対策 | 出力 HTML に `http(s)://` への参照が含まれないことをテストで保証する。phar / Docker 両方でアセット同梱を受け入れ条件にする |
| 実装規模 | PR 1 は小規模（Reporter 1つ）、PR 2 は中〜大規模（テンプレート、i18n、アセット管理） |
| 互換性 | 既存の format には一切手を入れない。追加のみ |
| 性能 | mermaid.js 同梱で出力 HTML が +3MB 前後になる。大規模グラフ（エッジ500超）は flowchart の描画をスキップしソース表示へフォールバック |

## 何が問題なのか

psap は text / json / markdown / html / mermaid / plantuml の6形式を出力できるが、1回の実行で1形式しか出力されない。結果を多面的に見たいユーザーは次の手間を払っている。

1. 同じ解析を形式の数だけ再実行する（解析自体は毎回フルに走る）
2. `.mmd` は Mermaid 対応ビューアへ、`.puml` は PlantUML 環境へ、それぞれ自分で持ち込んで描画する
3. HTML・Markdown・図の間を自分の頭の中でリンクする

特に 2 が重い。Mermaid / PlantUML のソースを出力しても、描画環境を持たないユーザーには実質「見えない」。README では生成AIとの協働を推奨しているが、人間が全体像を素早く俯瞰する入口が存在しない。

## ユーザーにどんな価値があるか

- 1回の実行で、ブラウザで開くだけの1ファイルが手に入る
- Mermaid の quadrantChart と依存図が、追加ツールなしにその場で図として見える
- インタラクティブ I/A グラフ（既存 HTML）と静的な図を行き来しながら確認できる
- 1ファイルなのでチームへの共有は添付だけで済む
- 解析はローカルで完結し、名前空間名を含む一切の情報が外部へ送信されない（既存の約束を維持）

## 設計方針

### 全体構成

```
analyze --format portal --output psap-portal.html
```

PortalReporter は既存の `ReporterInterface`（`render(ReportData): string`）を実装し、`AnalyzeCommand::reporterFactories()` へ `'portal'` を追加するだけで組み込む。exit code 規約、`--threshold`、`--fail-on-cycle` など解析側の挙動は変更しない。

PortalReporter は内部で既存 Reporter を合成する。

```
PortalReporter
 ├─ HtmlReporter        → iframe srcdoc として埋め込み（無改修）
 ├─ MermaidReporter     → quadrantChart ソース（ブラウザ内で描画）
 ├─ MermaidFlowchartReporter（新設） → 依存図ソース（ブラウザ内で描画）
 ├─ PlantUmlReporter    → ソース表示・コピー・ダウンロードのみ
 └─ ReportData から直接 → サマリー、循環詳細（PHP 側で HTML 生成）
```

Markdown レポートを **HTML へ変換して表示することはしない**。サマリーと循環詳細は ReportData から直接 HTML を組み立てる方が、Markdown → HTML 変換ライブラリの同梱より小さく確実なため。

> 後続の仕様変更（2026-07-19）: Sources タブに、`MarkdownReporter` の出力を **変換せずそのまま**（プレーンテキストの `<pre>` 表示 + コピー + ダウンロード、ファイル名 `psap-report.md`）同梱するようにした。変換ライブラリを足さない当初の判断は維持したまま、図ソースと同じ扱いで Markdown も 1 ファイルから取り出せるようにしたもの。

### タブ構成（単一 HTML 内のセクション切替）

| タブ | 内容 | 生成方法 |
|---|---|---|
| Overview | 解析カバレッジ、コンポーネント数、循環数、D 値ワースト、診断 | PHP 側で HTML 生成 |
| Interactive I/A | 既存のインタラクティブ I/A グラフ | HtmlReporter の出力を `<iframe srcdoc="...">` へ埋め込み |
| Diagrams | quadrantChart と依存 flowchart | mermaid.js でクライアント描画 |
| Cycles | 循環グループごとの経路・原因クラス・ファイル・行番号 | PHP 側で HTML 生成 |
| Sources | .mmd / .puml / .md のソース表示、コピー、ダウンロード | `<pre>` + Clipboard API + Blob ダウンロード |

iframe srcdoc を選ぶ理由: HtmlReporter は 1,800 行超の自己完結テンプレートで、CSS / JS の名前空間がポータル側と衝突する。srcdoc なら無改修で分離埋め込みでき、既存レポートの変更リスクがゼロになる。srcdoc 属性値は HTML エスケープ（`&` `"` `<` `>`）して埋め込む。

### MermaidFlowchartReporter（新設、PR 1）

PlantUmlReporter と同じ情報を Mermaid の `flowchart TD` で表現する。

- ノード = コンポーネント。ラベルに I / A / D を併記
- ゾーン色分け: 苦痛ゾーン・無駄ゾーンを `classDef` で PlantUML と同系色に
- 循環依存の代表経路に含まれるエッジは赤の太線（`linkStyle`）で強調
- ノード ID は PlantUML 同様 `C1, C2, ...` の連番（`\` を含む名前空間名は ID に使えない）
- ラベルのエスケープ: Mermaid flowchart は `"` で囲んだラベル内でも `<` 等を HTML として解釈するため、実データで崩れないことをエスケープ表で確定する（`quadrantChart` とは規則が異なる点に注意）
- コードフェンスは含めない（MermaidReporter と同じ方針）

独立した `--format` として公開するかは実装前判断事項とする（内部利用だけなら公開 API を増やさずに済む。公開するなら `mermaid` との名前の整理が必要）。

### mermaid.js の同梱

- `resources/js/mermaid.min.js`（IIFE ビルド、MIT License）をリポジトリへバージョン固定で同梱する。v11 系で約 3MB
- ライセンス文を `resources/js/mermaid.LICENSE` として併置し、出力 HTML にもコメントで明記する
- PortalReporter が `file_get_contents(__DIR__ . '/../../resources/js/mermaid.min.js')` で読み込み `<script>` へインライン展開する。この相対参照は phar 内（`phar://` パス）でもそのまま動く
- 初期化は `mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' })` とし、描画対象のソースはテキストノードとして埋め込む（HTML 注入経路を作らない）
- **検証必須**: `make phar`（clue/phar-composer）と Docker dist イメージの両方に `resources/` が同梱されること

### 外部送信なしの保証

- 出力 HTML に `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; frame-src data: about:">` 相当を付け、仮に将来のリグレッションで外部参照が混入してもブラウザ側で遮断されるようにする（iframe srcdoc と両立する具体値は実装時に確定）
- ユニットテストで、出力 HTML に `https://` / `http://` の参照（コメント・ライセンス表記を除く）が含まれないことを検証する

### 大規模プロジェクトへの対策

- Mermaid flowchart の既定 `maxEdges` は 500。エッジ数がしきい値を超える場合は flowchart の描画を行わず、「グラフが大きいため描画をスキップした。ソースを外部ビューアで利用するか、`--depth` や `--exclude` で対象を絞る」旨のメッセージとソース表示へフォールバックする
- quadrantChart も点数が極端に多いと可読性が落ちるが、こちらは描画自体は破綻しないため初期実装ではそのまま描画する
- `maxTextSize` 超過エラーに備え、mermaid の初期化で上限を引き上げるか、事前にソースサイズで判定する

### i18n

既存 HtmlReporter と同じ方式（英語既定、右上セレクターで日本語切替、翻訳辞書を JS 内へ同梱）に揃える。iframe 内の既存レポートは独自のセレクターを持つため、初期実装ではポータルと iframe の言語連動はしない（実装前判断事項に記載）。

## 実装設計

### 変更点一覧

| ファイル | 変更 |
|---|---|
| `src/Report/MermaidFlowchartReporter.php` | 新設（PR 1） |
| `src/Report/PortalReporter.php` | 新設（PR 2） |
| `src/Console/AnalyzeCommand.php` | `reporterFactories()` へ `'portal'` を追加（PR 2） |
| `resources/js/mermaid.min.js` ほか | 新設（PR 2） |
| `README.md` / `docs/getting-started.md` | portal の使い方、Docker 例、スクリーンショット（PR 2） |
| `tests/Unit/Report/...` | 各 Reporter のユニットテスト |
| `tests/Feature/AnalyzeCommandTest.php` | `--format portal` の結合テスト |

### PortalReporter の内部構造

HtmlReporter と同様に「テンプレート + `__PSAP_*__` プレースホルダ置換」方式とする。埋め込むデータは次の通り。

- `__PSAP_DATA__`: サマリー・循環詳細用の JSON（HtmlReporter と同じ `JSON_HEX_*` フラグでエスケープ）
- `__PSAP_IFRAME_HTML__`: HtmlReporter 出力の HTML エスケープ済み文字列
- `__PSAP_MERMAID_QUADRANT__` / `__PSAP_MERMAID_FLOWCHART__` / `__PSAP_PLANTUML__`: 各図のソース（JSON 文字列として埋め込み、JS 側でテキストノード化）
- `__PSAP_MERMAID_JS__`: mermaid.min.js 本体

## PRの分割

### PR 1: Mermaid 依存フローチャート

- `MermaidFlowchartReporter` を新設し、PlantUmlReporter と同等の情報（ノード、I/A/D ラベル、ゾーン色、循環エッジ強調）を flowchart で出力する
- ユニットテスト: SimpleProject / CyclicProject 相当のデータで、ノード・エッジ・強調・エスケープを検証
- この時点では CLI へ公開しない（判断事項1の結論が「公開する」なら `--format` へ追加）

先行させる理由: flowchart のエスケープ規則とレイアウト崩れは Mermaid 実装依存の不確実性が最も高い部分であり、ポータル本体と切り離して先に確定させたい。

### PR 2: ポータル本体

- `resources/` へ mermaid.min.js とライセンスを追加
- `PortalReporter` とテンプレート（タブ、i18n、CSP、フォールバック）を実装
- `reporterFactories()` へ `'portal'` を追加
- README / getting-started へ使い方とスクリーンショットを追加
- phar / Docker dist へのアセット同梱を確認

## テスト計画

### ユニット

- MermaidFlowchartReporter: ノード数・エッジ数が ReportData と一致する。循環エッジだけが強調される。`\` や `<` を含むコンポーネント名が壊れない。評価不能コンポーネント（`dependencyMetricsEvaluable = false`）のラベルが `N/A` になる
- PortalReporter: 出力に各プレースホルダの置換結果がすべて含まれる。`http(s)://` 参照が含まれない。iframe srcdoc のエスケープが往復可能（デコードすると HtmlReporter 出力と一致する）。エッジ数がしきい値超のときフォールバック文言が入る

### 結合

- `--format portal --output` でファイルが生成され exit code 0
- `--format portal` 単体（標準出力）でも HTML が RAW 出力される（AnalyzeCommand の `OUTPUT_RAW` 分岐が `html` 決め打ちなので `portal` も対象に含める修正が必要。**見落としやすいので注意**）

### 手動・ブラウザ

- SimpleProject / CyclicProject を解析したポータルをブラウザで開き、オフライン（ネットワーク遮断）状態で全タブが機能することを確認
- 開発者ツールの Network タブで外部リクエストが 0 件であることを確認
- 実プロジェクト（コンポーネント数十規模）で描画時間と可読性を確認

## リスクと対策

| リスク | 対策 |
|---|---|
| mermaid.js のバージョン更新でエスケープ規則や描画が変わる | バージョンを固定し、更新は描画スナップショットの手動確認を伴うPRでのみ行う |
| phar / Docker にアセットが入らず実行時エラー | 受け入れ条件に phar / Docker での動作確認を含める。読み込み失敗時は明確なエラーメッセージを出す |
| 出力 HTML の肥大化（3MB 超） | 許容する（自己完結・オフラインの価値が上回る）。README にサイズ目安を記載 |
| 大規模グラフで flowchart が破綻 | エッジ数しきい値でソース表示へフォールバック |
| iframe srcdoc のエスケープ漏れで既存レポートが壊れる | 往復エスケープのユニットテストで担保 |
| HtmlReporter の将来変更がポータルにも波及 | srcdoc 埋め込みは無改修合成なので、HtmlReporter 単体のテストが通ればポータル側は再エスケープのみ。結合テストで検知 |

## 実装前に確認する判断事項（確定済み）

以下 5 項目は実装時に確定した。

### 1. MermaidFlowchartReporter を `--format` として公開するか

**確定: 内部利用のみ**（CLI の `--format` には未公開）。実装済みで、ラベルのエスケープ表も確定した: `&`→`#38;`、`<`→`#lt;`、`>`→`#gt;`、`"`→`#quot;`。`\`（名前空間区切り）は特殊文字として解釈されないためそのまま通す。要望が出た時点で公開を検討する。

### 2. mermaid.js の更新方針

**確定: セキュリティ修正時のみ手動更新**。自動更新はしない。同梱バージョンは `11.16.0`（`dist/mermaid.min.js`、グローバル/IIFE ビルド、MIT）。更新時はオフラインのブラウザで Diagrams タブの描画を目視確認する。手順とバージョンは `resources/js/README.md` に記録する。

### 3. ポータルと iframe の言語切替連動

**確定: 非連動で開始**。ポータルの言語セレクターと iframe 内 HTML レポートの言語セレクターは独立して動く。postMessage での連動は後続候補とする。

### 4. 既定ファイル名の案内

**確定: 案内する**。`--output` 省略時は標準出力（既存規約通り）だが、README / getting-started の例では `psap-portal.html` を標準名として案内する。

### 5. flowchart フォールバックのしきい値

**確定: Mermaid 既定の maxEdges=500**。エッジ数が 500 を超える場合は flowchart のクライアント描画をスキップし、ソース表示へフォールバックする（i18n 対応のメッセージ付き）。quadrantChart は点数によらず描画する。`maxTextSize` 超過に備え、mermaid の初期化で上限を引き上げる（`maxTextSize: 5000000`）。

## 実装時に確定した事項

- **CSP**: `default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; font-src data:; frame-src 'self' data: about:; base-uri 'none'; form-action 'none'`。iframe srcdoc（`about:srcdoc`）と同梱 mermaid の両方が動作することをブラウザで確認済み。mermaid は `Function("return this")` を `self` 参照で短絡するため `'unsafe-eval'` は不要。外部オリジンは一切許可しない。
- **外部 URL 検査テストの除外**: 検査対象を「PortalReporter が生成するテンプレート部分」に限定する。同梱 mermaid.min.js の `<script>` ブロック、iframe srcdoc に埋め込んだ HtmlReporter 出力（独自の外部URL検査があり、SVG 名前空間 URI `http://www.w3.org/2000/svg` を含む）、mermaid ライセンスの HTML コメントを除外したうえで `http(s)://` 参照がないことを検証する。

## 推奨する着手順

1. 判断事項1〜5を確定する
2. PR 1（MermaidFlowchartReporter）を実装し、実プロジェクトの出力を Mermaid Live Editor 等で目視確認する
3. mermaid.min.js を取得・固定し、phar / Docker への同梱を検証する
4. PR 2（PortalReporter 本体）を実装する
5. README / getting-started を更新し、スクリーンショットを差し替える

# bobsap 開発計画

> **bobsap** — Robert C. Martin (a.k.a. ボブおじさん) + SAP（安定度・抽象度等価の原則）
> PHP アプリケーションの依存性管理メトリクス（Clean Architecture 第14章）を計測する CLI ツール。
> 第14章 安定度・抽象度等価の原則（SAP）

このファイルは単体で開発を再開できるように書かれている。どのモデル・どのセッションでも、このファイルを読めばフェーズの続きから実装できる。

---

## 1. ゴール

PHP コードベースを解析し、コンポーネント（名前空間）ごとに以下を計測・可視化する CLI ツールを作る。

| 指標 | 定義 | 意味 |
|---|---|---|
| Ca（ファン・イン） | コンポーネント外から、コンポーネント内のクラスに依存しているクラス数 | 求心性結合。大きいほど安定 |
| Ce（ファン・アウト） | コンポーネント内から、コンポーネント外のクラスに依存しているクラス数 | 遠心性結合。大きいほど不安定 |
| I（不安定さ） | `Ce / (Ca + Ce)` | 0=最安定、1=最不安定 |
| A（抽象度） | `抽象型数 / 総型数` | 0=具象のみ、1=抽象のみ |
| D（主系列からの距離） | `\|A + I - 1\|` | 0=主系列上（理想）、1=最遠 |

- **抽象型**: `interface`、`abstract class`
- **具象型**: `class`、`enum`、`trait`（trait の扱いは議論の余地があるが、v1 では具象として数える。README に明記する）
- **ゾーン判定**: 苦痛ゾーン（(0,0) 付近 = I も A も低い）、無駄ゾーン（(1,1) 付近 = I も A も高い）。D 値とは別に、距離 `sqrt(I² + A²) < 0.5` / `sqrt((1-I)² + (1-A)²) < 0.5` 等の単純な基準で警告する（実装フェーズで調整可）
- **統計**: 全コンポーネントの D の平均・分散も出力する（書籍の「設計を統計的に分析する」に対応）

### 確定済みの意思決定（ユーザー合意済み）

1. **コンポーネントの単位 = 名前空間**。`--depth N`（デフォルト 2 を想定）で束ねる深さを指定。例: depth=2 なら `App\Domain\Model\User` は `App\Domain` コンポーネントに属する
2. **実装言語 = PHP（最新安定版） + nikic/php-parser v5**。ツール自体は最新 PHP で動かし、解析対象は古い PHP コードも受け付ける（php-parser の後方互換パースに委ねる。ここが「多少の妥協」ポイント）
3. **出力形式 = テキスト（表）/ Mermaid / PlantUML / JSON** の4種
4. **`--threshold` オプションあり**。D 値がしきい値を超えるコンポーネントがあれば exit code 1（CI ゲートとしても使える）
5. **ソースコード内のコメントは日本語**
6. **`tmp/` はコミットしない**（書籍キャプチャ置き場。.gitignore に入れる）
7. **開発者フレンドリー・シンプル最優先**。凝った機能より分かりやすさ

---

## 2. 全体像

### CLI インターフェイス（目標形）

```bash
# 基本
bobsap analyze src/

# オプション
bobsap analyze src/ \
  --depth 2                  # 名前空間を束ねる深さ（デフォルト: 2）
  --format text              # text | mermaid | plantuml | json（デフォルト: text）
  --output report.md         # 省略時は標準出力
  --threshold 0.3            # D 値がこれを超えるコンポーネントがあれば exit 1
  --exclude "*/Tests/*"      # 除外パターン（複数指定可）

# Docker
docker run --rm -v $PWD:/workdir ghcr.io/shimabox/bobsap analyze src/
```

### 出力イメージ（text）

```
bobsap - Stable Abstractions Principle metrics

Component            Classes  Ca   Ce   I     A     D     Zone
-------------------  -------  ---  ---  ----  ----  ----  ----------
App\Domain                12    8    2  0.20  0.75  0.05
App\Infrastructure         7    1    9  0.90  0.10  0.00
App\Legacy                 5    6    1  0.14  0.00  0.86  ⚠ 苦痛ゾーン

Statistics: mean(D)=0.30, variance(D)=0.15

Classes in App\Legacy:
  - App\Legacy\OrderManager (concrete)
  - ...
```

- **どのクラスが対象なのか分かる**ことが要件。コンポーネント→所属クラス一覧（種別付き）を必ず出せること（text は問題コンポーネントのみ詳細表示 + `-v` で全件、JSON は常に全件、を目安に実装フェーズで調整）

### 可視化

- **Mermaid**: `quadrantChart` で I/A 散布図（書籍の図14-13/14-14 相当。苦痛ゾーン・無駄ゾーンを象限ラベルで表現、各点=コンポーネント）
- **PlantUML**: コンポーネント依存グラフ。ノードに `I/A/D` を併記し、ゾーン該当コンポーネントを色分け

### アーキテクチャ

```
bobsap/
├── bin/bobsap                 # エントリポイント
├── src/
│   ├── Analyzer/              # ファイル走査・AST 解析・依存関係抽出
│   │   ├── ClassInfo.php      #   型情報（FQCN, 種別, ファイルパス, 依存先FQCN一覧）
│   │   ├── DependencyAnalyzer.php
│   │   └── SourceFinder.php   #   対象 .php ファイルの列挙（exclude 対応）
│   ├── Component/             # 名前空間 → コンポーネント分類
│   │   ├── Component.php
│   │   └── ComponentClassifier.php
│   ├── Metrics/               # I/A/D 計算・ゾーン判定・統計
│   │   ├── MetricsCalculator.php
│   │   ├── ComponentMetrics.php
│   │   └── Zone.php           #   enum: None | Pain | Useless
│   ├── Report/                # 出力レンダラー
│   │   ├── ReporterInterface.php
│   │   ├── TextReporter.php
│   │   ├── JsonReporter.php
│   │   ├── MermaidReporter.php
│   │   └── PlantUmlReporter.php
│   └── Console/
│       └── AnalyzeCommand.php # symfony/console
├── tests/
│   ├── Unit/                  # 各レイヤーの単体テスト
│   ├── Feature/               # コマンド実行の E2E
│   └── Fixtures/              # 解析対象のサンプル PHP プロジェクト（複数）
├── docker/Dockerfile
├── compose.yaml               # 開発用
├── composer.json
├── phpunit.xml.dist
└── README.md
```

依存パッケージ（最小限に保つ）:
- `nikic/php-parser` … AST 解析
- `symfony/console` … CLI
- dev: `phpunit/phpunit`, `phpstan/phpstan`（**level: max**）, `friendsofphp/php-cs-fixer`（`@PSR12` + 追加ルールは最小限）

データフロー: `SourceFinder → DependencyAnalyzer → ClassInfo[] → ComponentClassifier → Component[] → MetricsCalculator → ComponentMetrics[] → Reporter`

各段が純粋な変換になっていて、単体テストしやすい。これが TDD の土台。

### 依存関係として数えるもの（DependencyAnalyzer の仕様）

nikic/php-parser の `NameResolver` で FQCN 解決した上で:

- `extends` / `implements` / `use`（trait 使用）
- プロパティ・引数・戻り値の型宣言
- `new X`、`X::method()`、`X::CONST`、`X::class`
- `instanceof`、`catch (X $e)`
- アトリビュート `#[X]`

数えないもの（v1 のスコープ外、README に明記）:
- docblock 内の型（`@var X` 等）
- 文字列ベースの参照（`class_exists('X')` 等）
- PHP 組み込みクラス・解析対象パス外のクラスへの依存は **Ce に数えない**（コンポーネント間メトリクスなので、対象外への依存はノイズになるため。実装フェーズで再検討可）

---

## 3. 開発の進め方（重要）

- **TDD スタイル**: 各フェーズは「テストを先に書く → 失敗を確認 → 実装 → グリーン」の順で進める
- **オーケストレーション体制**: メインセッション（オーケストレーター）は実装しない。実装・テスト作成は下位モデルのサブエージェントに委譲し、オーケストレーターは指示・レビュー・最終チェックを行う
- **サブエージェントへの委譲時に必ず伝えること**:
  1. このプランファイル（`.claude/plans/bobsap-development-plan.md`）を読むこと
  2. ソースコード内のコメントは日本語
  3. テスト先行（先にテストを書き、red → green を確認する）
  4. シンプルさ優先。凝った抽象化をしない
- **各フェーズの完了条件**: `composer test`（phpunit）・`composer stan`（phpstan level max）・`composer cs`（php-cs-fixer dry-run）がすべて通ること + フェーズごとの受け入れ基準を満たすこと
- **フェーズ完了ごとに git commit**（オーケストレーターの検証が通れば、ユーザー確認なしでコミットしてよい。2026-07-11 ユーザー指示）

---

## 4. フェーズ計画

### Phase 0: プロジェクト基盤 ✅ 完了条件: `composer test` で空のテストスイートが緑になる

> **注意**: 開発マシンに PHP / Composer は入っていない（Docker のみ）。開発コマンドはすべて Docker 経由で実行する。Phase 0 で開発用 `compose.yaml` を先に作り、以降 `docker compose run --rm app composer test` の形で回す（Makefile 等の薄いラッパーがあると開発者フレンドリー）。

- [ ] 開発用 `compose.yaml` + `docker/Dockerfile`（php:8.4-cli ベース + composer。開発と配布を兼ねる構成は実装時に判断）
- [ ] `git init`、`.gitignore`（`tmp/`, `vendor/`, `.DS_Store`, `.claude/settings.local.json` 等。`.claude/plans/` はコミットする）
- [ ] `composer.json`（PSR-4: `Bobsap\` → `src/`、`Bobsap\Tests\` → `tests/`。scripts: `test`, `stan`, `cs`（dry-run チェック）, `cs:fix`（自動整形））
- [ ] 依存導入: php-parser / symfony-console / phpunit / phpstan / php-cs-fixer
- [ ] `phpunit.xml.dist`、`phpstan.neon.dist`（**level: max**）、`.php-cs-fixer.dist.php`（`@PSR12` ベース）
- [ ] `bin/bobsap` スタブ（`--version` が出るだけ）
- [ ] 動作確認用のダミーテスト 1 本

### Phase 1: 依存関係抽出（Analyzer） ✅ 完了条件: フィクスチャの PHP コードから ClassInfo[] が正しく取れる

TDD 対象。先に `tests/Fixtures/SimpleProject/` を作る（interface / abstract / concrete / enum / trait / extends / implements / new / 型宣言 / static 呼び出し / instanceof / catch / attribute を網羅する小さなサンプル群）。

- [ ] テスト: `SourceFinder` が exclude パターンを考慮して .php を列挙する
- [ ] テスト: `DependencyAnalyzer` が型の種別（interface/abstract/concrete/enum/trait）を判定できる
- [ ] テスト: 上記「数えるもの」リストの依存がすべて FQCN で抽出できる
- [ ] テスト: グローバル名前空間・パースエラーのファイル（警告してスキップ）の扱い
- [ ] 実装: `ClassInfo` / `SourceFinder` / `DependencyAnalyzer`

### Phase 2: コンポーネント分類とメトリクス ✅ 完了条件: ClassInfo[] から I/A/D/ゾーン/統計が正しく計算できる

純粋ロジックなので最も TDD 向き。フィクスチャ不要、インメモリの ClassInfo で書く。

- [ ] テスト: `ComponentClassifier` が depth 指定で名前空間を束ねる（depth より浅い名前空間、グローバル名前空間のエッジケース含む）
- [ ] テスト: Ca / Ce の計算（コンポーネント内部の依存は数えない、対象外クラスへの依存は Ce に数えない）
- [ ] テスト: I / A / D の計算（書籍の定義通り。Ca+Ce=0 のときの I、型数 0 のときの A のゼロ除算ガード）
- [ ] テスト: ゾーン判定・平均・分散
- [ ] 実装: `Component` / `ComponentClassifier` / `MetricsCalculator` / `ComponentMetrics` / `Zone`

### Phase 3: レポート出力（text / JSON）+ threshold ✅ 完了条件: analyze コマンドが end-to-end で動く

- [ ] テスト: `TextReporter`（表形式、ゾーン警告、統計行、クラス一覧）
- [ ] テスト: `JsonReporter`（全データを機械可読で。スキーマをテストで固定）
- [ ] テスト: `AnalyzeCommand`（Feature テスト: フィクスチャに対して実行し出力と exit code を検証）
- [ ] テスト: `--threshold` 超過で exit 1、未超過で exit 0
- [ ] 実装: `ReporterInterface` / `TextReporter` / `JsonReporter` / `AnalyzeCommand`

### Phase 4: 可視化（Mermaid / PlantUML） ✅ 完了条件: 出力をそのまま Mermaid Live / PlantUML サーバーに貼って図になる

- [ ] テスト: `MermaidReporter`（quadrantChart。点=コンポーネント名、象限ラベルにゾーン名）
- [ ] テスト: `PlantUmlReporter`（依存グラフ。ノードに I/A/D、ゾーン色分け）
- [ ] 実装後、実際にレンダリングして目視確認（オーケストレーターの最終チェック項目）
- [ ] コンポーネント名のエスケープ（`\` を含む名前空間が構文を壊さないこと）に注意

### Phase 5: Docker・README・ドッグフーディング ✅ 完了条件: `docker run` で他プロジェクトを計測できる & README だけで使い始められる

- [ ] `docker/Dockerfile`（マルチステージ: composer install → 実行イメージ。`/workdir` を作業ディレクトリに）
- [ ] `compose.yaml`（開発用: test / stan 実行）
- [ ] ドッグフーディング: bobsap 自身を bobsap で計測し、結果を README に載せる
- [ ] README（日本語。SAP の簡単な説明、インストール、使い方、メトリクス定義、v1 の制限事項）
- [ ] 最終チェック: 実在の OSS（例: 手頃な PHP ライブラリ）に対して実行してみて破綻しないこと

---

## 4.5 v1.1 フェーズ計画（php-class-diagram 調査から採用。2026-07-11 ユーザー選択: A/B/C/D 全部）

参考元: `~/shimabox/sandbox/php-class-diagram`（smeghead 作）。調査済み知見は進捗メモ参照。

### Phase 6: 循環依存検出（ADP） ✅ 完了条件: 循環が text/JSON で報告され、PlantUML で赤矢印表示される

書籍第14章の ADP（非循環依存関係の原則）に対応。php-class-diagram の相互依存検出（2ノード限定）を SCC に発展させる。

- [ ] コンポーネント間依存グラフの導出を PlantUmlReporter の private から共有クラスへ抽出（`Bobsap\Component\DependencyGraph`: ノード=コンポーネント名、エッジ=依存。導出ロジックは既存と同一）
- [ ] テスト: DependencyGraph のエッジ導出（既存 PlantUmlReporter テストの移設含む）
- [ ] テスト: SCC 検出（`CycleDetector`。Tarjan または Kahn ベース。2ノード相互依存 / 3ノード以上の循環 / 循環なし / 自己ループなし）
- [ ] テスト: TextReporter に `Cycles:` セクション（循環なしなら非表示）、JsonReporter に `cycles: [[名前,...]]`
- [ ] テスト: PlantUmlReporter で SCC 内のエッジを赤色 `-[#red,thickness=2]->` 表示
- [ ] テスト: `--fail-on-cycle` オプション（循環があれば exit 1。threshold と同じく stderr に通知）
- [ ] Mermaid（quadrantChart）はエッジを表現できないため対象外（README に明記）

### Phase 7: docblock 型の解析 ✅ 完了条件: @var/@param/@return と Product[] 形式から依存が拾える

- [ ] 依存追加: `phpstan/phpdoc-parser ^2`
- [ ] テスト: `@var X` / `@param X $p` / `@return X`（プロパティ・メソッド・プロモートされたコンストラクタ引数）
- [ ] テスト: `X[]` / `array<X>` / `array<int, X>` / `?X` / `X|Y` の分解、プリミティブ型・`array<int,string>` 等クラスでないものの除外
- [ ] テスト: docblock 内の短縮名が use 文・現在名前空間で FQCN 解決されること（php-parser の NameContext を活用。php-class-diagram は自前解決だが、bobsap は NameResolver の文脈を使う方が素直）
- [ ] デフォルト有効、`--no-docblock` でオフ（Feature テスト含む）
- [ ] README の制限事項から docblock の項を更新

### Phase 8: CI + セルフ計測 ✅ 完了条件: GitHub Actions が push/PR で走る構成ができている

- [ ] `.github/workflows/ci.yml`: composer validate --strict → test / stan / cs。PHP 8.3 / 8.4 のマトリクス（Docker ではなく shivammathur/setup-php を使う方が Actions では素直。判断は実装時）
- [ ] セルフ計測ジョブ: bobsap で src/ を計測し、text + mermaid + plantuml 出力を Artifact にアップロード
- [ ] ローカルでは actionlint 等での構文確認まで（実際の実行は GitHub push 後になる旨を報告）

### Phase 9: 配布強化 ✅ 完了条件: phar が生成でき、配布物がスリム化されている

- [ ] `.gitattributes`: tests/ .github/ 等を export-ignore（Packagist tarball スリム化）
- [ ] phar ビルド手段の整備（clue/phar-composer 等。composer script `build` として。Docker 経由で生成できること）
- [ ] PlantUML レンダラー同梱の Docker イメージ（`docker/Dockerfile` に別ステージ or 別 Dockerfile。CJK フォント `fonts-noto-cjk` 入り。「analyze して即 PNG まで出す」ユースケース用。工数が膨らむ場合は見送って報告してよい）
- [ ] README に配布形態の説明を追記

## 5. スコープ外（v1 ではやらない・将来候補）

- docblock 型の解析
- D 値の推移記録（図14-15 相当。JSON 出力を時系列に貯めれば手動では可能）
- 設定ファイル（`.bobsap.json` 等）。v1 は CLI オプションのみ
- ディレクトリベースのコンポーネント分類
- HTML レポート

## 6. 進捗メモ

（フェーズ完了時にここへ追記する。例: `2026-07-11 Phase 0 完了 (commit abc1234)`）

- 2026-07-11 プラン作成
- 2026-07-11 Phase 0 完了 (commit 0c4ee9d) — 残課題: php-cs-fixer 実行時の PHP 8.4 警告（無害、必要なら 8.3 に固定）
- 2026-07-11 Phase 1 完了 (commit 4362098) — 設計メモ: 組み込みクラスへの依存も Analyzer は記録する（フィルタは Phase 2 の責務）。無名クラスは宣言・依存とも収集しない。exclude パターンは探索ルートからの相対パスに fnmatch
- 2026-07-11 Phase 2 完了 (commit 3b0bed7) — 設計メモ: ComponentMetrics のプロパティ名は instability/abstractness/distance（説明的な名前を採用）。孤立コンポーネントは I=0。グローバル名前空間は `(global)` コンポーネント。Zone 境界は厳密に < 0.5
- 2026-07-11 Phase 3 完了 (commit 9bb75b6) — Reporter は文字列を返すだけ（出力先はコマンドの責務）。threshold 超過通知は stderr（JSON の stdout を汚さない）。exit code は Symfony の SUCCESS/FAILURE/INVALID。composer stan に --memory-limit=512M
- 2026-07-11 Phase 6 完了 (commit 248f2d7) — DependencyGraph は Component 配下。CycleDetector は Tarjan 再帰版。ReportData の cycles は末尾デフォルト引数で後方互換。PlantUML 赤エッジはレンダリング確認済み。CyclicProject フィクスチャは --depth 3 前提（depth 2 だと同一コンポーネントに束ねられ循環が消える）
- 2026-07-11 Phase 4 完了 (commit 4bf02e3) — Mermaid の軸ラベルは括弧を含むため要クォート。quadrantChart の座標は 0.01-0.99 にクランプ（ラベルの D は実値）。PlantUML の凡例は CJK フォント非搭載環境（plantuml/plantuml Docker 等）で豆腐になるため英語表記。両形式ともローカルレンダリングで確認済み
- 2026-07-11 Phase 3 完了（未コミット） — 設計メモ: Reporter は文字列を返すのみ（出力先書き込みは AnalyzeCommand の責務）。JSON の数値は丸め誤差対策で round(x, 4)。format→Reporter は配列マッピング（callable）で Phase 4 拡張しやすくした。exit code は symfony/console の Command::SUCCESS/FAILURE/INVALID(0/1/2) をそのまま利用。threshold 超過メッセージは stdout の JSON を汚染しないよう常に stderr へ出力。SimpleProject フィクスチャは namespace が `Fixture\App\...` なので depth=2（デフォルト）だと Domain/Infra/Generated が `Fixture\App` に統合される点に注意（depth=3 で分離）
- 2026-07-11 Phase 5 完了（未コミット） — `docker/Dockerfile` を dev/vendor/dist の3ステージに分割（`compose.yaml` は `target: dev` を明示指定して既存の開発フローを維持）。dist イメージは `ENTRYPOINT ["php", "/app/bin/bobsap"]` で `/workdir` を作業ディレクトリに、ツール本体は `/app` に配置。README・LICENSE・ドッグフーディング結果を追加。Composer 経由インストールは Packagist 未公開のため git リポジトリ指定の例のみ記載（判断: `composer require --dev shimabox/bobsap` は将来対応として明記）。実 OSS 動作確認は `thephpleague/csv`（123ファイル）に対して depth=2/3 双方でクラッシュなく完走（exit 0）。ghcr.io への実際の publish は行っていない（プランの記述はビルド例として README に残すか検討したが、混乱を避けローカルビルド手順のみ記載）

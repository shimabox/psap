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

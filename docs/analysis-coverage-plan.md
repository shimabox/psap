# 解析カバレッジと構造化診断の改善計画

## この計画の結論

次の2段階で実装する。

1. **解析カバレッジ**を追加し、PHPファイルの発見・除外・解析成功・スキップを数値で示す
2. **構造化診断**を追加し、警告を単なる文字列ではなく、コード、重要度、ファイル、行番号、対処方法を持つデータとして扱う

この2つは関連しているが、別PRに分ける。先に数え方を確定し、その後で警告の表現を置き換える方が、数値の不具合と表示の不具合を切り分けやすい。

## 判断用サマリー

| 判断項目 | 推奨 |
|---|---|
| この改善を実施するか | 実施を推奨する。レポートへの信頼をユーザー自身が判断できるようになる |
| 今やる理由 | 不正UTF-8対応によって「レポートを継続生成しつつ一部をスキップする」経路が実際に生まれたため |
| 最初に届ける価値 | 意図的な除外と予期しない解析失敗を数値で区別すること |
| 最大の設計リスク | 数え方が曖昧なままReporterごとに別々の値を出してしまうこと |
| リスクへの対策 | 数値の定義と不変条件を`AnalysisCoverage`へ集約し、全Reporterで同じ値を使う |
| 実装規模 | PR 1は中規模、PR 2は出力形式を横断するため大きめ |
| 互換性 | JSONの`warnings`を残しながら`diagnostics`を追加する段階移行を推奨 |
| CIへの影響 | 初期実装では終了コードを変えず、厳格モードは利用実績を見て別PRにする |

この改善は、新しい分析理論を追加するものではない。すでに出している分析結果に「どの範囲を処理できた結果なのか」という品質情報を添えるものである。そのため、SAP指標の意味を変えずにユーザーの判断精度を上げられる。

## 何が問題なのか

現在のレポートには、コンポーネント数、型数、依存、循環、I、A、Dが表示される。しかし、その結果が解析対象のPHPファイルをどの程度処理できた結果なのかは分からない。

たとえば、同じ「76コンポーネント」という結果でも、次の2つは信頼性が異なる。

```text
ケースA
  対象ファイル 8,205件
  解析成功     8,205件
  スキップ         0件

ケースB
  対象ファイル 8,205件
  解析成功     6,000件
  スキップ     2,205件
```

現在のサマリーだけを見ると、この違いを判断できない。警告を読めば一部は分かるが、警告件数と影響範囲をユーザー自身が数える必要がある。

### 問題1: レポートの網羅性を判断できない

1件だけスキップしたレポートと、数百件スキップしたレポートが同じ見た目で生成される。ユーザーは、そのレポートを設計判断にどこまで使ってよいか判断できない。

### 問題2: 意図的な除外と予期しない失敗が区別されない

`--exclude`でテストや生成データを外すことは、ユーザーが意図した解析条件である。一方、文字コード、読み込み、構文解析、名前解決によるスキップは、意図せず解析範囲が欠けた状態である。

この2つを単純に「解析しなかったファイル」としてまとめると、次の判断ができない。

- 除外が多いだけで、選択した対象はすべて解析できたのか
- 本来解析する予定だったファイルが失敗したのか
- 問題を直すべきか、`--exclude`へ追加すべきか

### 問題3: 警告が文字列なので、機械的に扱えない

現在の`AnalysisResult`と`ReportData`は、警告を`list<string>`として持っている。

```text
UTF-8として解釈できないためスキップしました: /target/src/.../ValueWrapper.php:19（UTF-8へ変換するか、--excludeで除外してください）
```

人間は読めるが、プログラムは次の情報を安全に取り出せない。

- 警告の種類
- スキップを伴う警告か
- ファイルパス
- 行番号
- 推奨する対処
- 表示すべき重要度

文字列の文言を変更すると、それを解析している外部処理も壊れる可能性がある。

### 問題4: 表示言語とデータが分離されていない

HTMLのUIは英語と日本語を切り替えられるが、Analyzerが生成する警告文字列は日本語である。そのため、英語UIの中に日本語警告が表示される。

警告を構造化しない限り、表示先に応じた翻訳や、ファイル位置だけを目立たせる表示が難しい。

### 問題5: CIで解析欠落を判定できない

現在のCIはDの閾値や循環依存を判定できるが、「予定したファイルの一部が解析できなかった」ことを数値や診断コードで判定できない。

初期実装では終了コードの仕様は増やさないが、構造化されたカバレッジと診断があれば、将来`--fail-on-skipped-file`のような厳格モードを安全に追加できる。

## ユーザーにどんな価値があるか

| 利用場面 | 現在の不安 | 改善後の価値 |
|---|---|---|
| 初めてOSSや既存システムを解析する | レポートが全体を見ているか分からない | 選択したPHPファイルの何件を解析できたか分かる |
| `--exclude`を多く指定する | 除外しすぎたのか、失敗したのか分からない | 意図的な除外と予期しないスキップを分けて確認できる |
| HTMLだけを他の人へ渡す | 実行時ログがなく、欠落に気づけない | HTML内にカバレッジと診断が残る |
| JSONをCIや別ツールで処理する | 警告文の文字列解析が必要 | 安定した診断コードと位置情報で分岐できる |
| 英語・日本語を切り替える | UIと言語が混在する | 同じ診断データを表示側で翻訳できる |
| 修正対象を探す | 長い警告文から場所と対処を読む必要がある | ファイル、行、理由、対処が分離される |

最も重要な価値は、**レポートの内容だけでなく、そのレポートをどの程度信用してよいかをユーザー自身が判断できること**である。

## 「完全性」という言葉の範囲

この機能を「アーキテクチャ解析の完全性」とは呼ばない。推奨する名称は**解析カバレッジ（Analysis coverage）**または**ファイルカバレッジ（File coverage）**である。

この数値が100%でも、次の依存は静的解析の対象外である。

- 文字列で指定されたクラス
- Reflectionによる動的参照
- DIコンテナや設定ファイルだけに書かれた結線
- 実行時に決まるクラス名
- 解析対象パス外のコード

したがって、100%が意味するのは「選択したPHPファイルをすべてpsapの解析パイプラインへ通せた」であり、「実行時の全依存を発見した」ではない。この注意はレポートとドキュメントに明記する。

## 数値の定義

### 基本指標

| 指標 | 定義 |
|---|---|
| Discovered files | 指定したソースルート配下で発見した、重複を除く`.php`ファイル数。`--exclude`適用前 |
| Excluded files | 発見したが、`--exclude`に一致して解析対象から意図的に外したファイル数 |
| Selected files | `Discovered - Excluded`。Analyzerへ渡す予定だったファイル数 |
| Analyzed files | 読み込み、UTF-8検証、PHPパース、名前解決を完了したファイル数。型宣言が0件でも成功なら含む |
| Skipped files | Selectedのうち、読み込み、文字コード、パース、名前解決の問題で完了できなかったファイル数 |

常に次の関係を満たす。

```text
Selected files   = Discovered files - Excluded files
Discovered files = Excluded files + Analyzed files + Skipped files
Selected files   = Analyzed files + Skipped files
```

### カバレッジ率

```text
Analysis coverage = Analyzed files / Selected files
```

`Excluded files`はユーザーが意図的に解析対象から外したため、分母へ含めない。除外を分母へ含めると、正しく対象を絞ったユーザーほどカバレッジが低く見えてしまう。

`Selected files`が0件の場合、カバレッジは100%ではなく`N/A`とする。何も解析していない状態を成功と誤認させないためである。

### 表示例

```text
Discovered files: 10,715
Selected files:    8,205
Analyzed files:    8,204
Excluded files:    2,510
Skipped files:         1
Analysis coverage: 99.99%
```

この例では、全発見ファイルに対する解析率は約76.6%だが、2,510件は意図的な除外である。ユーザーが注目すべきなのは、選択した8,205件のうち8,204件を処理できた99.99%という値と、スキップされた1件の理由である。

## 境界条件の扱い

### 型宣言がないPHPファイル

関数だけのファイル、設定を返すファイル、空のPHPファイルでも、パースと名前解決が完了すれば`Analyzed files`へ含める。型数とファイル解析の成功は別の概念である。

### 同じファイルを複数ルートから発見した場合

実パスで重複排除し、1ファイルとして数える。重なるソースルートが指定され、あるルートでは除外、別のルートでは選択される場合は、**選択を優先**する。解析されたファイルをExcludedにも数える二重計上を避ける。

### 重複FQCN

複数ファイルに同じFQCNがあっても、各ファイルの解析自体が成功していればAnalyzedへ含める。重複宣言の診断は出すが、Skippedには含めない。

### 読み取れないソースルート

ソースルート自体を探索できない場合は、現在と同じ入力エラーとする。レポートを生成できないため、カバレッジ集計の対象外である。

### シンボリックリンク

現在の`RecursiveDirectoryIterator`の探索方針を維持する。シンボリックリンク追跡の仕様変更は、この計画へ含めない。

## 提案するレポート表示

### HTML

既存のI/Aサマリーへ数値を詰め込まず、独立した`Analysis coverage`欄を警告パネルの近くに置く。

```text
┌ Analysis coverage ─────────────────────────────────────┐
│ 99.99%     8,204 analyzed / 8,205 selected             │
│ Discovered 10,715 · Excluded 2,510 · Skipped 1         │
└────────────────────────────────────────────────────────┘

┌ Analysis warnings · 1 ─────────────────────────────────┐
│ source.invalid_utf8                                    │
│ Component/Cache/Traits/ValueWrapper.php:19             │
│ Source file is not valid UTF-8 and was skipped.        │
│ Action: Convert to UTF-8 or exclude the file.           │
└────────────────────────────────────────────────────────┘
```

表示方針は次のとおり。

- `Skipped files > 0`ならカバレッジを警告色にする
- `Skipped files = 0`なら成功を強調しすぎず、通常色で表示する
- Excludedは異常ではないため警告色にしない
- 英語・日本語切替では、見出し、理由、対処を翻訳する
- ファイルパス、行番号、診断コードは翻訳しない
- 静的解析の対象外があることを短い注記で示す

### text

```text
Analysis coverage: 99.99% (8,204/8,205 selected files)
Files: discovered=10,715, excluded=2,510, skipped=1
```

### Markdown

`Analysis Summary`へ次の行を追加する。

```markdown
| Analysis coverage | 99.99% |
| Discovered PHP files | 10,715 |
| Selected PHP files | 8,205 |
| Analyzed PHP files | 8,204 |
| Excluded PHP files | 2,510 |
| Skipped PHP files | 1 |
```

生成AIがレポートを読む場合も、スキップがあることを設計評価の前提として扱える。

### JSON

`summary`とは分けて`fileCoverage`を追加する。コンポーネント指標と入力ファイルの会計は異なる責務だからである。

```json
{
  "fileCoverage": {
    "discovered": 10715,
    "selected": 8205,
    "analyzed": 8204,
    "excluded": 2510,
    "skipped": 1,
    "analysisCoverage": 0.9999
  }
}
```

`analysisCoverage`は表示用パーセントではなく、0から1の数値とする。`Selected files = 0`の場合は`null`にする。

### MermaidとPlantUML

グラフ本体へ6個の数値を追加すると視認性が落ちるため、初期実装では図の表示要素に追加しない。

- CLIの標準エラーにはスキップ診断を表示する
- Mermaidではコメント行、PlantUMLではコメントまたはlegendへの短いカバレッジ表記を後続改善として検討する
- 詳細確認はHTML、text、Markdown、JSONを正とする

## 構造化診断の設計

### データモデル

AnalyzerやAnalyzeCommandは、完成した日本語文を作らない。次のような値オブジェクトを生成する。

```php
new Diagnostic(
    code: DiagnosticCode::SourceInvalidUtf8,
    severity: DiagnosticSeverity::Warning,
    file: 'Component/Cache/Traits/ValueWrapper.php',
    line: 19,
    context: [],
    actions: [
        DiagnosticAction::ConvertToUtf8,
        DiagnosticAction::ExcludeFile,
    ],
);
```

推奨するフィールドは次のとおり。

| フィールド | 用途 |
|---|---|
| `code` | 外部ツールが分岐に使える安定した識別子 |
| `severity` | `info`、`warning`、将来用の`error` |
| `file` | ソースルートからの相対パス。対象がファイルでない場合は`null` |
| `line` | 最初に問題を特定できた行。特定不能なら`null` |
| `context` | パーサーの詳細やFQCNなど、診断ごとの追加情報 |
| `actions` | 修正、設定変更、除外などの推奨操作コード |

`message`はCoreのデータへ固定しない。ReporterまたはFormatterが`code`と`context`から表示文を生成する。

### 診断コード

初期移行対象は次のとおり。

| code | severity | ファイルをスキップ | action |
|---|---|---:|---|
| `source.read_failed` | warning | Yes | `check_permissions`, `exclude_file` |
| `source.invalid_utf8` | warning | Yes | `convert_to_utf8`, `exclude_file` |
| `source.parse_failed` | warning | Yes | `fix_source`, `exclude_file` |
| `source.name_resolution_failed` | warning | Yes | `fix_source`, `exclude_file` |
| `declaration.duplicate_fqcn` | warning | No | `review_duplicate` |
| `declaration.kind_conflict` | warning | No | `review_duplicate` |
| `analysis.no_types` | warning | No | `review_source_paths` |
| `analysis.single_component_depth` | info | No | `increase_depth` |
| `analysis.single_component_unevaluable` | info | No | `review_component_boundary` |

Skipped件数は診断文字列から逆算しない。Analyzerが成功・スキップを直接数え、診断コードは理由を説明する。これにより、文言や翻訳を変更してもカバレッジ値は変わらない。

### JSONの診断例

```json
{
  "diagnostics": [
    {
      "code": "source.invalid_utf8",
      "severity": "warning",
      "file": "Component/Cache/Traits/ValueWrapper.php",
      "line": 19,
      "message": "Source file is not valid UTF-8 and was skipped.",
      "actions": [
        { "code": "convert_to_utf8" },
        { "code": "exclude_file", "option": "--exclude" }
      ]
    }
  ]
}
```

JSON利用者は`message`ではなく`code`を判定に使う。`message`は人間がJSONを直接読んだときの補助であり、互換性を保証する識別子ではない。

### 言語の扱い

- Coreは診断コードとパラメーターだけを生成する
- HTMLは既存の言語セレクターに合わせ、理由とActionを英語・日本語で描画する
- JSONの`code`、`severity`、`file`、`line`、`actions`は言語非依存とする
- JSONの補助`message`は英語を既定とする
- textとMarkdownは現在のレポート本文に合わせて英語で表示する
- CLIの標準エラーは、将来の`--locale`追加までは現在の日本語表示を維持する

### ファイルパスの扱い

レポート内では、依存根拠と同様にソースルートからの相対パスを推奨する。Dockerの`/target`や各開発者のホームディレクトリがレポートへ混ざらず、別環境でも比較しやすくなる。

CLIの標準エラーでは、実行環境で直接探せる現在のパスを表示してよい。内部の診断モデルが絶対パスと表示用相対パスの両方を持つか、FormatterへSource rootsを渡して変換する。

## 実装設計

### SourceFinder

現在の`find()`は、除外後の`list<string>`だけを返すため、発見数と除外数が失われる。

新しく`discover()`を追加し、`SourceInventory`を返す。

```php
final readonly class SourceInventory
{
    public function __construct(
        public array $selectedFiles,
        public int $discoveredFileCount,
        public int $excludedFileCount,
    ) {}
}
```

既存の`find()`は`discover()->selectedFiles`を返す互換ラッパーとして残す。ライブラリとして`SourceFinder`を利用しているコードを壊さないためである。

実装上は、実パスをキーにして発見状態を集約する。複数ルートから同じファイルを発見した場合は1件とし、1回でも選択されたファイルはselectedへ分類する。

### DependencyAnalyzer / AnalysisResult

`AnalysisResult`へ次を追加する。

```php
public int $analyzedFileCount;
public int $skippedFileCount;
public array $diagnostics;
```

`Analyzed files`は次のすべてが完了した時点で1件増やす。

1. ファイル読み込み
2. UTF-8検証
3. PHPパース
4. 名前解決

ASTが`null`、または型宣言が0件でも、処理が正常終了していればAnalyzedへ含める。

### AnalysisCoverage

SourceFinderとDependencyAnalyzerの結果をAnalyzeCommandで統合する。

```php
final readonly class AnalysisCoverage
{
    public function __construct(
        public int $discovered,
        public int $selected,
        public int $analyzed,
        public int $excluded,
        public int $skipped,
    ) {
        // 不変条件を検証する
    }

    public function ratio(): ?float;
}
```

不変条件を値オブジェクト内で検証し、Reporterごとに異なる計算をしない。

### ReportData

`ReportData`へ`AnalysisCoverage`と`list<Diagnostic>`を渡す。ReporterはAnalyzerの内部状態へ触れず、同じ値から各形式を生成する。

## PRの分割

### PR 1: 解析カバレッジの基盤と表示

目的は「何件を対象にし、何件を処理できたか」を正確にすること。

実装内容:

- `SourceInventory`と`SourceFinder::discover()`
- `AnalysisResult`の成功・スキップ件数
- `AnalysisCoverage`の不変条件と比率
- AnalyzeCommandからReportDataへの受け渡し
- text、Markdown、JSON、HTMLへのカバレッジ表示
- 用語と限界のドキュメント
- 重複ルート、除外、空ファイル、スキップのテスト

このPRでは既存の`warnings`文字列を維持する。

### PR 2: 構造化診断と多言語表示

目的は「何が起き、どこを、どう直すか」をデータとして扱えるようにすること。

実装内容:

- `DiagnosticCode`、`DiagnosticSeverity`、`DiagnosticAction`
- `Diagnostic`値オブジェクト
- 既存warning生成箇所の移行
- 診断Formatterと英語・日本語カタログ
- HTML警告パネルの構造化表示
- JSONの`diagnostics`配列
- text、Markdown、CLI標準エラーのFormatter利用
- 既存`warnings`との互換処理
- 診断コードごとの回帰テスト

### 後続候補: CI厳格モード

利用実績を確認してから、次を別PRで検討する。

```text
--fail-on-skipped-file
--max-skipped-files 0
--min-analysis-coverage 1.0
```

初期実装で終了コードを変えない理由は、既存プロジェクトのCIを突然失敗させないためである。

## JSON互換性

既存の`warnings: list<string>`をすぐ削除すると、利用者の処理を壊す可能性がある。

推奨する移行:

1. `diagnostics`を追加する
2. `warnings`はDiagnosticsから生成した互換フィールドとして残す
3. ドキュメントでは新規利用者へ`diagnostics`を案内する
4. 次のメジャーバージョンで`warnings`削除を判断する

まだ公開バージョンの互換性を厳密に保証しない方針なら一度に置き換える選択肢もあるが、psapをCIへ組み込みやすいツールにするなら段階移行を推奨する。

## テスト計画

### ファイル会計

- 除外なしで`Discovered = Selected = Analyzed`
- exclude適用で`Discovered = Selected + Excluded`
- 不正UTF-8で`Selected = Analyzed + Skipped`
- パースエラーでSkippedが増える
- 名前解決エラーでSkippedが増える
- 型宣言0件の正常PHPはAnalyzedへ含まれる
- 重複ソースルートでもDiscoveredを二重計上しない
- あるルートでexcluded、別ルートでselectedならselectedを優先する
- Selectedが0件ならcoverageが`null`
- `AnalysisCoverage`の不変条件違反を生成できない

### 構造化診断

- 各診断コードのseverity、file、line、actions
- UTF-8診断が最初の不正行を保持する
- ファイルをスキップしない診断がSkipped件数へ影響しない
- JSONの診断コードが文言変更で変わらない
- HTMLの英語・日本語切替
- 未知の診断コードでも安全なfallbackを表示する
- HTML埋め込み時のエスケープを維持する
- 既存warnings互換フィールドがDiagnosticsと一致する

### 実プロジェクト

- psap自身
- nikic/FastRouteのような小規模OSS
- Symfony depth 3のような大規模OSS
- Symfonyの`ValueWrapper.php`を使ったSkipped 1件の実証

## 性能要件

ファイル数を数えるために、2回目のディレクトリ走査や2回目のファイル読み込みを行わない。

- SourceFinderの既存走査中にDiscoveredとExcludedを集計する
- DependencyAnalyzerの既存ループ中にAnalyzedとSkippedを集計する
- カバレッジ計算は整数演算だけにする
- 大量のexcludedパスをReportDataへ保持せず、初期実装では件数だけを保持する

Symfony depth 3を同じ条件で3回程度実行し、中央値で顕著な劣化がないことを確認する。目安として実行時間の増加を10%以内とするが、CI環境の揺らぎを考慮し、自動テストの厳密な時間制限にはしない。

## 受け入れ条件

### PR 1

- すべての成功レポートでファイル会計の不変条件が成立する
- HTML、text、Markdown、JSONで同じ件数が表示される
- ExcludedとSkippedが明確に区別される
- Selected 0件を100%と表示しない
- 100%が静的解析上の全依存検出を意味しないことを説明する
- 既存の解析結果、循環、I/A/Dの値が変わらない
- 全PHPUnit、PHPStan、CSチェックが成功する
- Symfony実解析で性能劣化が許容範囲に収まる

### PR 2

- すべての既存warning生成箇所が診断コードへ移行される
- スキップ理由を文字列解析せず集計できる
- HTMLの英語・日本語で理由と対処が同じ意味になる
- JSON利用者が`code`、`severity`、`file`、`line`、`actions`を利用できる
- 既存`warnings`の互換方針がテストとドキュメントで固定される
- 未知の診断でもレポート生成が失敗しない

## リスクと対策

| リスク | 対策 |
|---|---|
| Discoveredの定義が曖昧で数値を信用できない | 定義と不変条件を値オブジェクトとテストで固定する |
| 重複ソースルートで二重計上する | 実パスをキーに集約する |
| Excluded件数のために性能が落ちる | 現在の1回の走査中に数え、再走査しない |
| Reporterごとに比率がずれる | `AnalysisCoverage::ratio()`だけで計算する |
| 診断文の変更でJSON利用者が壊れる | 文ではなく安定した`code`を公開契約にする |
| HTMLの多言語表示とCLIの言語が不一致 | Coreから文言を外し、出力先ごとのFormatterを使う |
| 診断モデルが大きくなりすぎる | 初期フィールドをcode、severity、location、context、actionsへ限定する |
| Skippedを0にするため無理にファイルを解析する | 数値を良く見せるより、正確にスキップし理由を示す |
| 100%を「全依存を検出」と誤解する | Analysis coverageの限界をUIとドキュメントへ明記する |

## 実装前に確認する判断事項

### 1. 表示名

推奨: **Analysis coverage / 解析カバレッジ**

「Completeness」は動的依存まで完全に見ている印象を与えるため避ける。

### 2. JSONの`warnings`互換性

推奨: `diagnostics`追加後も、`warnings`を少なくとも1リリース残す。

### 3. Skipped発生時の終了コード

推奨: 初期実装では現在どおり成功`0`。警告とカバレッジを表示し、厳格モードは後続PRにする。

### 4. HTMLの表示位置

推奨: I/Aの4指標カードへ混ぜず、警告パネルの直前に独立したAnalysis coverage欄を置く。

### 5. Excludedファイルの個別一覧

推奨: 初期実装は件数と指定パターンだけ。数千件のパスをHTMLやJSONへ含めない。

### 6. 診断ファイルパス

推奨: レポートはソースルート相対、CLI標準エラーは実行環境のパス。移植性と現場での探しやすさを両立する。

## 推奨する着手順

1. PR 1のファイル会計モデルと不変条件テストを先に作る
2. JSONへ`fileCoverage`を出し、数値を機械的に検証する
3. text、Markdown、HTMLへ同じ数値を表示する
4. psap、FastRoute、Symfonyで件数と性能を確認する
5. PR 1をマージする
6. PR 2で構造化診断へ移行する
7. HTMLの英語・日本語表示とJSON互換性を確認する
8. 実利用後にCI厳格モードの必要性を判断する

この順序なら、まず「レポートをどこまで信用できるか」を可視化し、その土台の上で「問題をどう直すか」を分かりやすくできる。

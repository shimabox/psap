# CIでの利用

## 閾値と循環依存

Dの閾値と循環依存を同時に検査できます。

```bash
psap analyze src/ --threshold 0.6 --fail-on-cycle
```

閾値超過または循環依存がある場合は終了コード`1`を返します。

## 循環ベースライン

既存の循環を許容し、新しく発生した循環だけをCIで失敗させられます。

最初に現在の循環を保存します。

```bash
psap analyze src/ --generate-cycle-baseline psap-baseline.json
```

CIではベースラインと比較します。

```bash
psap analyze src/ --cycle-baseline psap-baseline.json --fail-on-cycle
```

ベースラインには循環のメンバー、名前空間深度、docblock設定、除外パターンを保存します。比較時の解析条件が一致しない場合は入力エラーになります。解消した循環もtext、JSON、Markdownの比較結果へ出力します。

## GitHub Actions

```yaml
name: psap
on: [pull_request]

jobs:
  metrics:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: git clone --depth 1 https://github.com/shimabox/psap.git /tmp/psap
      - run: docker build -t psap --target dist -f /tmp/psap/docker/Dockerfile /tmp/psap
      - run: docker run --rm -v "$PWD":/workdir psap analyze src/ --threshold 0.6 --fail-on-cycle
```

MarkdownレポートをArtifactとして保存すれば、失敗した解析結果を生成AIへ渡して調査できます。

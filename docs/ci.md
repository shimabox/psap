# CIでの利用

## 閾値と循環依存

Dの閾値と循環依存を同時に検査できます。

```bash
bobsap analyze src/ --threshold 0.6 --fail-on-cycle
```

閾値超過または循環依存がある場合は終了コード`1`を返します。

## 循環ベースライン

既存の循環を許容し、新しく発生した循環だけをCIで失敗させられます。

最初に現在の循環を保存します。

```bash
bobsap analyze src/ --generate-cycle-baseline bobsap-baseline.json
```

CIではベースラインと比較します。

```bash
bobsap analyze src/ --cycle-baseline bobsap-baseline.json --fail-on-cycle
```

ベースラインには循環のメンバー、名前空間深度、docblock設定、除外パターンを保存します。比較時の解析条件が一致しない場合は入力エラーになります。解消した循環もtext、JSON、Markdownの比較結果へ出力します。

## GitHub Actions

```yaml
name: bobsap
on: [pull_request]

jobs:
  metrics:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: git clone --depth 1 https://github.com/shimabox/bobsap.git /tmp/bobsap
      - run: docker build -t bobsap --target dist -f /tmp/bobsap/docker/Dockerfile /tmp/bobsap
      - run: docker run --rm -v "$PWD":/workdir bobsap analyze src/ --threshold 0.6 --fail-on-cycle
```

MarkdownレポートをArtifactとして保存すれば、失敗した解析結果を生成AIへ渡して調査できます。

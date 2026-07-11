#!/bin/sh
# bobsap-plantuml イメージ専用のショートカットコマンド。
# analyze の結果を PlantUML でそのまま PNG 化する（計測して即・絵にするユースケース用）。
#
# 使い方:
#   docker run --rm -v "$PWD":/workdir bobsap-plantuml analyze-png src/ --depth 2
#
# カレントディレクトリ（/workdir）に bobsap-report.png を出力する。
set -e

WORKDIR_TMP="$(mktemp -d /tmp/bobsap-XXXXXX)"
PUML="$WORKDIR_TMP/report.puml"

php /app/bin/bobsap analyze "$@" --format plantuml --output "$PUML"
java -jar /opt/plantuml.jar -tpng -o /workdir "$PUML"

# plantuml は入力ファイル名（拡張子違い）で出力するため、分かりやすい名前にリネームする
mv /workdir/report.png /workdir/bobsap-report.png
rm -rf "$WORKDIR_TMP"

echo "Generated: bobsap-report.png"

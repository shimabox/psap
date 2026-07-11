#!/bin/sh
# dist-plantuml イメージの ENTRYPOINT。
# 第1引数が `analyze-png` ならショートカットコマンドへ、それ以外は dist と同じく
# `php /app/bin/bobsap` へそのまま委譲する（既存 dist の使い勝手を変えない）。
set -e

if [ "$1" = "analyze-png" ]; then
    shift
    exec analyze-png "$@"
fi

exec php /app/bin/bobsap "$@"

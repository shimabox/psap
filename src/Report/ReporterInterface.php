<?php

declare(strict_types=1);

namespace Bobsap\Report;

/**
 * レポートを文字列として描画するレンダラーのインターフェイス。
 *
 * 出力先（標準出力・ファイル）への書き込みは呼び出し側（AnalyzeCommand）の責務とし、
 * Reporter は文字列を返すだけにする（テストしやすさ優先）。
 */
interface ReporterInterface
{
    public function render(ReportData $data): string;
}

<?php

declare(strict_types=1);

namespace Fixture\OutOfScope\Http;

use DateTimeImmutable;
use Fixture\OutOfScope\Domain\Report;
use Fixture\Vendor\Log\Logger;
use Fixture\Vendor\Support\Facades\Cache;
use Fixture\Vendor\Support\Facades\DB;

// 解析対象外依存（架空のfacade風staticコール・グローバルエイリアス）と
// PHP組み込み型が混在する確認用フィクスチャ。
// Fixture\Vendor\* と GlobalFacade は実在しないため解析対象外依存になる。
final class ReportController
{
    public function index(): Report
    {
        DB::table('reports');
        Cache::get('reports');
        Logger::info('reports');

        return new Report(new DateTimeImmutable());
    }

    public function legacy(): void
    {
        \GlobalFacade::boot();
    }

    public function fail(): void
    {
        throw new \RuntimeException('built-in types must not be reported');
    }
}

<?php

// Phase 0 の動作確認用の煙テスト。オートロードや PHPUnit の実行環境が
// 正しくセットアップされていることを確認する。後続フェーズで実クラスの
// テストが揃ったら削除してよい。

declare(strict_types=1);

namespace Bobsap\Tests\Unit;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testAutoloadingWorks(): void
    {
        // composer のオートロード（PSR-4 / vendor 依存）が効いていることを確認する
        self::assertTrue(class_exists(ParserFactory::class));
    }
}

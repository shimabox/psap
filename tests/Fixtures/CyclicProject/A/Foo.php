<?php

declare(strict_types=1);

namespace Fixture\Cyclic\A;

use Fixture\Cyclic\B\Bar;

// A ⇔ B の2ノード相互依存（循環依存 = ADP違反）を作るためのフィクスチャ
final class Foo
{
    public function useBar(Bar $bar): void
    {
        $bar->ping();
    }
}

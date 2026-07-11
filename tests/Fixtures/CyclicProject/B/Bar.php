<?php

declare(strict_types=1);

namespace Fixture\Cyclic\B;

use Fixture\Cyclic\A\Foo;

// A ⇔ B の2ノード相互依存（循環依存 = ADP違反）を作るためのフィクスチャ
final class Bar
{
    public function useFoo(Foo $foo): void
    {
    }

    public function ping(): void
    {
    }
}

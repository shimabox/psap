<?php

declare(strict_types=1);

namespace Fixture\Cyclic\E;

use Fixture\Cyclic\C\Baz;

// C→D→E→C の3コンポーネント循環（循環依存 = ADP違反）を作るためのフィクスチャ
final class Quux
{
    public function useBaz(Baz $baz): void
    {
    }
}

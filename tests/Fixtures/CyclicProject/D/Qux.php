<?php

declare(strict_types=1);

namespace Fixture\Cyclic\D;

use Fixture\Cyclic\E\Quux;

// C→D→E→C の3コンポーネント循環（循環依存 = ADP違反）を作るためのフィクスチャ
final class Qux
{
    public function useQuux(Quux $quux): void
    {
    }
}

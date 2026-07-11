<?php

declare(strict_types=1);

namespace Fixture\Cyclic\C;

use Fixture\Cyclic\D\Qux;

// C→D→E→C の3コンポーネント循環（循環依存 = ADP違反）を作るためのフィクスチャ
final class Baz
{
    public function useQux(Qux $qux): void
    {
    }
}

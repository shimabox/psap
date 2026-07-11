<?php

declare(strict_types=1);

namespace Fixture\App\Infra;

// 組み込みクラス（\Exception）への extends 依存が除外されずそのまま抽出されることの確認用フィクスチャ
final class RepositoryException extends \Exception
{
}

<?php

declare(strict_types=1);

namespace Fixture\App\Domain;

use Fixture\App\Domain\Attribute\Since;

// extends / implements / trait use / union型 / nullable型 / new / static呼び出し /
// ::class / アトリビュートをまとめて含む依存関係抽出の確認用フィクスチャ
#[Since('1.0.0')]
final class User extends AbstractEntity implements Nameable
{
    use HasTimestamps;

    private EmailAddress|Address $contact;

    public function __construct(
        private readonly string $id,
        ?EmailAddress $email = null,
    ) {
        $this->contact = $email ?? EmailAddress::fromString('user@example.com');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->id;
    }

    public function statusClass(): string
    {
        return Status::class;
    }
}

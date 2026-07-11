<?php

declare(strict_types=1);

namespace Fixture\App\Infra;

use Fixture\App\Domain\Address;
use Fixture\App\Domain\EmailAddress;
use Fixture\App\Domain\User;

// instanceof / catch（union含む） / intersection型 / ::class の依存抽出の確認用フィクスチャ
final class UserRepository
{
    /** @var array<string, User> */
    private array $storage = [];

    public function find(string $id): ?User
    {
        $user = $this->storage[$id] ?? null;

        return $user instanceof User ? $user : null;
    }

    public function save(User $user): void
    {
        try {
            $this->storage[$user->id()] = $user;
        } catch (RepositoryException|\RuntimeException $e) {
            throw $e;
        }
    }

    public function accepts(\Countable&\ArrayAccess $collection): bool
    {
        return $collection->count() >= 0;
    }

    public function makeAddress(): Address
    {
        return new Address('Tokyo');
    }

    public function emailClass(): string
    {
        return EmailAddress::class;
    }
}

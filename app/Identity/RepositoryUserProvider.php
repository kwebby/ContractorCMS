<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Identity;

use App\Contracts\RecordRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Hash;

class RepositoryUserProvider implements UserProvider
{
    public function __construct(private RecordRepository $records) {}

    public function retrieveById($identifier): ?Authenticatable
    {
        $record = $this->records->find('users', (string) $identifier);

        return $record && ($record['active'] ?? true) ? new CmsUser($record) : null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $record = $this->records->all('users', ['email' => strtolower(trim($credentials['email'] ?? ''))])[0] ?? null;

        return $record && ($record['active'] ?? true) ? new CmsUser($record) : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return Hash::check($credentials['password'] ?? '', $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        if ($force || Hash::needsRehash($user->getAuthPassword())) {
            $this->records->update('users', (string) $user->getAuthIdentifier(), ['password' => Hash::make($credentials['password'])]);
        }
    }
}

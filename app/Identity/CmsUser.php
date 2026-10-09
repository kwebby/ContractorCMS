<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Identity;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Arr;

class CmsUser extends GenericUser
{
    public function __construct(array $attributes)
    {
        parent::__construct($attributes + ['remember_token' => null, 'mfa_enabled' => false, 'customer_id' => null, 'email_verified_at' => null, 'session_version' => 0, 'active' => true, 'custom_permissions' => []]);
    }

    public function publicData(): array
    {
        return Arr::only($this->attributes, ['id', 'name', 'email', 'role', 'customer_id', 'email_verified_at', 'mfa_enabled']);
    }
}

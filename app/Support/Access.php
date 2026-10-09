<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Support;

use Illuminate\Support\Arr;

class Access
{
    public const ROLES = [
        'owner' => ['*'],
        'office' => ['crm.manage', 'operations.manage', 'estimates.manage', 'finance.manage', 'chat.use', 'reports.view'],
        'dispatcher' => ['crm.view', 'operations.manage', 'chat.use', 'reports.view'],
        'estimator' => ['crm.manage', 'operations.view', 'estimates.manage', 'chat.use'],
        'technician' => ['operations.assigned', 'chat.use'],
        'finance' => ['crm.view', 'finance.manage', 'estimates.manage', 'reports.view', 'chat.use'],
        'payroll' => ['payroll.manage', 'chat.use'],
        'marketing' => ['publishing.manage', 'crm.view', 'chat.use'],
        'client' => ['portal.use', 'chat.use'],
    ];

    public static function can(?object $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }
        $permissions = self::ROLES[$user->role ?? 'client'] ?? [];
        if (! empty($user->custom_permissions)) {
            $permissions = array_merge($permissions, $user->custom_permissions);
        }

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function authorize(string $permission): void
    {
        abort_unless(self::can(auth()->user(), $permission), 403, 'You do not have permission for this action.');
    }

    public static function authorizeRecord(?object $user, array $record, string $permission): void
    {
        abort_unless($user, 401);
        if (($user->role ?? '') === 'client') {
            abort_unless($user->email_verified_at && $user->customer_id && ($record['customer_id'] ?? null) === $user->customer_id, 403);

            return;
        }
        if (($user->role ?? '') === 'technician') {
            abort_unless(in_array($user->id, $record['assigned_to'] ?? [], true) || ($record['employee_user_id'] ?? null) === $user->id, 403);

            return;
        }
        abort_unless(self::can($user, $permission), 403);
    }

    public static function privileged(?object $user): bool
    {
        return self::can($user, 'finance.manage') || self::can($user, 'payroll.manage') || self::can($user, 'settings.manage') || self::can($user, 'team.manage');
    }

    public static function publicRecord(?object $user, string $collection, array $record): array
    {
        if (($user->role ?? '') === 'client') {
            $fields = ['id', 'version', 'created_at', 'updated_at', 'name', 'title', 'status', 'description', 'customer_id', 'customer_name'];
            if ($collection === 'jobs') {
                $fields = array_merge($fields, ['service', 'scope', 'address', 'scheduled_at', 'duration_minutes', 'completion_requested', 'property_id', 'approved_changes', 'approved_change_total_minor', 'approved_total_minor']);
            }

            return Arr::only($record, $fields);
        }
        if (($user->role ?? '') === 'technician') {
            $record = Arr::except($record, ['actual_cost_minor', 'estimated_total_minor', 'margin_minor', 'profit_minor']);
        }

        return $record;
    }

    public static function authorizeRoleGrant(object $actor, string $role, array $permissions = []): void
    {
        foreach (array_merge(self::ROLES[$role] ?? [], $permissions) as $permission) {
            abort_unless(self::can($actor, $permission), 403, 'You cannot grant permissions you do not hold.');
        }
    }
}

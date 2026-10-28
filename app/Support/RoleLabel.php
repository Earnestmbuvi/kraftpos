<?php

namespace App\Support;

use App\Models\StaffRole;
use App\Models\User;

class RoleLabel
{
    public const BUILT_IN = ['owner', 'manager', 'cashier'];

    public static function for(?User $u): string
    {
        $name = $u?->getRoleNames()->first();

        return $name ? self::label($name) : '';
    }

    public static function label(string $name): string
    {
        return in_array($name, self::BUILT_IN, true)
            ? ucfirst($name)
            : (StaffRole::where('role_name', $name)->value('label') ?? ucfirst($name));
    }

    /** [role_name => label] this shop can assign. */
    public static function options(int $businessId): array
    {
        $out = array_combine(self::BUILT_IN, array_map('ucfirst', self::BUILT_IN));

        foreach (StaffRole::where('business_id', $businessId)->orderBy('label')->get() as $r) {
            $out[$r->role_name] = $r->label;
        }

        return $out;
    }
}

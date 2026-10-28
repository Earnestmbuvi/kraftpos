<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminService
{
    private static function admin(): User
    {
        $user = auth()->user();
        abort_unless($user && $user->is_super_admin, 403);

        return $user;
    }

    // The target must really belong to this merchant and must not be a super admin
    private static function target(Business $business, int $userId): User
    {
        return User::where('business_id', $business->id)
            ->where('is_super_admin', false)->findOrFail($userId);
    }

    public static function resetPassword(Business $business, int $userId, string $password): User
    {
        $admin = self::admin();
        $target = self::target($business, $userId);

        if (mb_strlen($password) < 8 || mb_strlen($password) > 100) {
            throw ValidationException::withMessages(['password' => 'Use between 8 and 100 characters.']);
        }

        DB::transaction(function () use ($admin, $business, $target, $password) {
            $target->password = $password; // hashed by the model cast
            $target->save();

            // Sign them out everywhere
            DB::table('sessions')->where('user_id', $target->id)->delete();

            AdminAuditLog::create([
                'admin_id' => $admin->id,
                'business_id' => $business->id,
                'target_user_id' => $target->id,
                'action' => 'password_reset',
                'detail' => 'Password reset for ' . $target->email,
            ]);
        });

        return $target;
    }

    public static function toggleUser(Business $business, int $userId): User
    {
        $admin = self::admin();
        $target = self::target($business, $userId);

        DB::transaction(function () use ($admin, $business, $target) {
            $target->is_active = ! $target->is_active;
            $target->save();

            if (! $target->is_active) {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }

            AdminAuditLog::create([
                'admin_id' => $admin->id,
                'business_id' => $business->id,
                'target_user_id' => $target->id,
                'action' => $target->is_active ? 'user_enabled' : 'user_disabled',
                'detail' => ($target->is_active ? 'Enabled ' : 'Disabled ') . $target->email,
            ]);
        });

        return $target;
    }
}

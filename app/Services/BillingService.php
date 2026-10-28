<?php

namespace App\Services;

use App\Models\Business;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public const METHODS = ['M-Pesa', 'Cash', 'Bank transfer', 'Complimentary'];

    public static function recordPayment(
        Business $business, int $months, float $amount, string $method, ?string $reference, ?string $note
    ): SubscriptionPayment {
        $user = auth()->user();
        abort_unless($user && $user->is_super_admin, 403);

        $amount = round($amount, 2);
        $reference = trim((string) $reference);
        $note = trim((string) $note);

        if ($months < 1 || $months > 24) {
            self::fail('months', 'Choose between 1 and 24 months.');
        }
        if ($amount < 0 || $amount > 10000000) {
            self::fail('amount', 'Enter a valid amount.');
        }
        if (! in_array($method, self::METHODS, true)) {
            self::fail('method', 'Choose a payment method.');
        }
        if ($amount > 0 && in_array($method, ['M-Pesa', 'Bank transfer'], true) && $reference === '') {
            self::fail('reference', 'Enter the transaction reference.');
        }
        if (mb_strlen($reference) > 50) {
            self::fail('reference', 'The reference is too long (50 characters at most).');
        }
        if (mb_strlen($note) > 255) {
            self::fail('note', 'The note is too long (255 characters at most).');
        }

        return DB::transaction(function () use ($business, $months, $amount, $method, $reference, $note, $user) {
            $b = Business::lockForUpdate()->findOrFail($business->id);

            // Extend from the current end date, or from today if it already lapsed
            $start = $b->subscription_ends_at && $b->subscription_ends_at->gte(today())
                ? $b->subscription_ends_at->copy()
                : today();
            $end = $start->copy()->addMonthsNoOverflow($months);

            $payment = $b->subscriptionPayments()->create([
                'recorded_by' => $user->id,
                'amount' => $amount,
                'months' => $months,
                'method' => $method,
                'reference' => $reference ?: null,
                'period_start' => $start,
                'period_end' => $end,
                'note' => $note ?: null,
            ]);

            $b->subscription_ends_at = $end;

            // Only a suspension caused by billing is lifted automatically
            if ($b->status === 'suspended' && $b->billing_suspended) {
                $b->status = 'active';
                $b->status_reason = null;
                $b->billing_suspended = false;
                $b->reviewed_by = $user->id;
                $b->reviewed_at = now();
            }

            $b->save();

            return $payment;
        });
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

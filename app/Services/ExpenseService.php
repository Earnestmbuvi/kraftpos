<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public static function record(
    int $categoryId, float $amount, string $date, ?int $methodId, ?string $reference, string $description, float $inputTax = 0
): Expense {
        $user = auth()->user();
        abort_unless($user && $user->business_id && $user->can('record-expenses'), 403);

        $amount = round($amount, 2);
        $inputTax = round($inputTax, 2);
        if ($inputTax < 0 || $inputTax > $amount) {
    self::fail('inputTax', 'The tax cannot be negative or more than the amount.');
        }
        $description = trim($description);
        $reference = trim((string) $reference);

        if ($amount <= 0 || $amount > 10000000) {
            self::fail('amount', 'Enter an amount above zero.');
        }
        if ($description === '') {
            self::fail('description', 'Say what the money was spent on.');
        }
        if (mb_strlen($description) > 255) {
            self::fail('description', 'The description is too long (255 characters at most).');
        }
        if (mb_strlen($reference) > 50) {
            self::fail('reference', 'The reference is too long (50 characters at most).');
        }

        $category = ExpenseCategory::active()->find($categoryId); // tenant scope applies
        if (! $category) {
            self::fail('category', 'Choose a category.');
        }

        try {
            $day = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable $e) {
            self::fail('date', 'Enter a valid date.');
        }
        if ($day->gt(today())) {
            self::fail('date', 'The date cannot be in the future.');
        }
        // Staff who cannot see reports may only post for today or yesterday
        if (! $user->can('view-reports') && $day->lt(today()->subDay())) {
            self::fail('date', 'You can only record expenses for today or yesterday.');
        }

        $method = null;
        if ($methodId) {
            $method = PaymentMethod::active()->find($methodId);
            if (! $method) {
                self::fail('method', 'Choose a valid payment method.');
            }
        }

        return Expense::create([
            'user_id' => $user->id,
            'expense_category_id' => $category->id,
            'category_name' => $category->name,
            'amount' => $amount,
            'input_tax' => $inputTax,
            'expense_date' => $day,
            'payment_method_id' => $method?->id,
            'method_name' => $method?->name,
            'reference' => $reference ?: null,
            'description' => $description,
        ])->refresh();
    }

    public static function void(Expense $expense, string $reason): Expense
    {
        $user = auth()->user();
        abort_unless($user && $user->can('void-sales'), 403);

        $reason = trim($reason);
        if ($reason === '') {
            self::fail('reason', 'Please give a reason for voiding this expense.');
        }
        if (mb_strlen($reason) > 255) {
            self::fail('reason', 'The reason is too long (255 characters at most).');
        }

        return DB::transaction(function () use ($expense, $reason, $user) {
            $locked = Expense::lockForUpdate()->findOrFail($expense->id); // tenant scope applies

            if ($locked->status !== 'recorded') {
                self::fail('reason', 'This expense has already been voided.');
            }

            $locked->status = 'voided';
            $locked->voided_at = now();
            $locked->voided_by = $user->id;
            $locked->void_reason = $reason;
            $locked->save();

            return $locked;
        });
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }


}

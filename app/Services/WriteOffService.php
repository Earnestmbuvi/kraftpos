<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WriteOffService
{
    public static function run(Invoice $invoice, float $amount, string $reason): Invoice
    {
        $user = auth()->user();
        abort_unless($user && $user->business_id && $user->can('void-sales'), 403);

        $amount = round($amount, 2);
        $reason = trim($reason);

        if ($reason === '') {
            self::fail('writeOffReason', 'Give a reason for writing this off.');
        }
        if (mb_strlen($reason) > 200) {
            self::fail('writeOffReason', 'The reason is too long (200 characters at most).');
        }

        return DB::transaction(function () use ($invoice, $amount, $reason, $user) {
            $locked = Invoice::lockForUpdate()->findOrFail($invoice->id); // tenant scope applies

            if ($locked->status !== 'issued') {
                self::fail('writeOffAmount', 'Only an issued invoice can be written off.');
            }

            $balance = round((float) $locked->total - (float) $locked->amount_paid, 2);
            if ($balance <= 0) {
                self::fail('writeOffAmount', 'Nothing is owed on this invoice.');
            }
            if ($amount <= 0) {
                self::fail('writeOffAmount', 'Enter an amount above zero.');
            }
            if ($amount > $balance) {
                self::fail('writeOffAmount', 'That is more than the balance of ' . number_format($balance, 2) . '.');
            }

            // Settled, but not received: kept apart from real payments
            $locked->payments()->create([
                'user_id' => $user->id,
                'payment_method_id' => null,
                'method_name' => 'Written off',
                'type' => 'writeoff',
                'amount' => $amount,
                'reference' => null,
                'receipt_number' => null,
            ]);

            $locked->amount_paid = round((float) $locked->amount_paid + $amount, 2);
            $locked->written_off = round((float) $locked->written_off + $amount, 2);
            $locked->save();

            $category = ExpenseCategory::firstOrCreate(['name' => 'Bad debts']);

            Expense::create([
                'user_id' => $user->id,
                'expense_category_id' => $category->id,
                'category_name' => $category->name,
                'amount' => $amount,
                'expense_date' => today(),
                'description' => 'Bad debt: ' . $locked->number_label . ' - ' . $reason,
            ]);

            return $locked;
        });
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

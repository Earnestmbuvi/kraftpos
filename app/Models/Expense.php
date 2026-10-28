<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use \App\Models\Concerns\BranchBound;

class Expense extends Model
{
    use BelongsToBusiness;

    // status and void fields are not fillable: only ExpenseService changes them
    protected $fillable = [
        'user_id', 'expense_category_id', 'category_name', 'amount', 'expense_date',
        'payment_method_id', 'method_name', 'reference', 'description', 'input_tax'
    ];

   protected $casts = [
    'expense_date' => 'date:Y-m-d',
    'voided_at' => 'datetime',
    'amount' => 'decimal:2',
];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Expenses cannot be deleted. Void them instead.'));
    }

    public function user() { return $this->belongsTo(User::class); }
}

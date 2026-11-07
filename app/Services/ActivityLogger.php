<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class ActivityLogger
{
    private const SKIP = [
        'password', 'remember_token', 'updated_at', 'created_at', 'email_verified_at',
        'client_secret', 'webhook_secret', 'two_factor_secret',
    ];

    private static function watched(): array
    {
        $names = [
            'Product', 'ProductVariant', 'ProductOption', 'Category', 'Unit', 'Customer', 'Sale', 'Payment', 'Invoice', 'InvoicePayment',
            'Quotation', 'Expense', 'ExpenseCategory', 'PaymentMethod', 'Branch', 'User', 'Business', 'SaleReturn',
            'StockTransfer', 'StockBatch', 'StockMovement', 'DeliveryZone', 'StaffRole', 'SubscriptionPayment',
            'PlatformDocument', 'PlatformDocumentPayment', 'PlatformItem', 'Lead','DocumentText',
        ];

        return array_values(array_filter(array_map(fn ($n) => 'App\\Models\\' . $n, $names), 'class_exists'));
    }

    public static function register(): void
    {
        foreach (self::watched() as $class) {
            Event::listen("eloquent.created: {$class}", fn ($m) => self::fromModel('created', $m));
            Event::listen("eloquent.updated: {$class}", fn ($m) => self::fromModel('updated', $m));
        }

        Event::listen(Login::class, fn ($e) => self::write('login', 'Logged in', null, [], $e->user));
        Event::listen(Logout::class, fn ($e) => self::write('logout', 'Logged out', null, [], $e->user));
        Event::listen(Failed::class, fn ($e) => self::write(
            'failed_login', 'Failed login attempt for ' . Str::limit((string) ($e->credentials['email'] ?? 'unknown'), 100, ''), null, [], $e->user
        ));
    }

    private static function clip($v)
    {
        if (is_array($v)) {
            $v = json_encode($v);
        }

        return is_string($v) ? Str::limit($v, 200, '...') : $v;
    }

    private static function label(Model $m): string
    {
        foreach (['receipt_label', 'number_label', 'name', 'label'] as $k) {
            try {
                $v = $m->{$k};
            } catch (\Throwable $e) {
                $v = null;
            }
            if (is_string($v) && $v !== '') {
                return Str::limit($v, 100, '');
            }
        }

        return '#' . $m->getKey();
    }

    private static function movement(StockMovement $m): string
    {
        $v = ProductVariant::withoutGlobalScopes()->with('product')->find($m->product_variant_id);
        $name = $v ? $v->product->name . ($v->name !== 'Default' ? ' - ' . $v->name : '') : 'an item';
        $change = ($m->quantity_change > 0 ? '+' : '') . $m->quantity_change;

        return 'Stock ' . Str::headline($m->type) . ": {$change} of {$name}" . ($m->reason ? " ({$m->reason})" : '');
    }

    private static function fromModel(string $action, Model $m): void
    {
        $skipStock = $m instanceof ProductVariant ? ['stock_quantity'] : [];
        $type = Str::lower(Str::headline(class_basename($m)));
        $label = self::label($m);
        $changes = [];

        if ($action === 'updated') {
            $passwordChanged = $m->wasChanged('password');

            foreach ($m->getChanges() as $k => $new) {
                if (in_array($k, self::SKIP, true) || in_array($k, $skipStock, true) || str_starts_with($k, 'last_')) {
                    continue;
                }
                $changes[$k] = [self::clip($m->getOriginal($k)), self::clip($new)];
            }

            if ($passwordChanged) {
                $changes['password'] = ['(hidden)', '(changed)']; // the password itself is never recorded
            }

            if (! $changes) {
                return; // nothing worth recording, such as a stock counter
            }

            $description = isset($changes['status'])
                ? ucfirst($type) . " {$label} marked {$changes['status'][1]}"
                : 'Updated ' . $type . " {$label} (" . implode(', ', array_keys($changes)) . ')';
        } elseif ($m instanceof StockMovement) {
            $description = self::movement($m);
        } else {
            $money = $m->getAttribute('total') ?? $m->getAttribute('amount');
            $description = 'Created ' . $type . " {$label}" . ($money !== null ? ' for Kshs ' . number_format((float) $money, 2) : '');
        }

        self::write($action, $description, $m, $changes);
    }

    public static function write(string $action, string $description, ?Model $subject = null, array $changes = [], $user = null): void
    {
        try {
            $user ??= auth()->user();

            $businessId = $subject instanceof Business
                ? $subject->getKey()
                : ($subject?->getAttribute('business_id') ?: $user?->business_id);

            ActivityLog::create([
                'business_id' => $businessId,
                'user_id' => $user?->getAuthIdentifier(),
                'user_name' => $user?->name,
                'branch_id' => $subject?->getAttribute('branch_id'),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subject ? self::label($subject) : null,
                'description' => Str::limit($description, 480, '...'),
                'changes' => $changes ?: null,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e); // logging must never break a sale
        }
    }
}

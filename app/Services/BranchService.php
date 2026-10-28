<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchService
{
    private static function bag(): \ArrayObject
    {
        if (! app()->bound('branch.bag')) {
            app()->instance('branch.bag', new \ArrayObject());
        }

        return app('branch.bag');
    }

    public static function forget(): void
    {
        app()->instance('branch.bag', new \ArrayObject());
    }

    public static function mainId(?int $businessId = null): ?int
    {
        $bid = $businessId ?? auth()->user()?->business_id;
        if (! $bid) {
            return null;
        }

        $bag = self::bag();
        if (isset($bag["main.$bid"])) {
            return $bag["main.$bid"];
        }

        $b = Branch::withoutGlobalScopes()->where('business_id', $bid)->where('is_main', true)->first()
            ?? Branch::withoutGlobalScopes()->forceCreate(['business_id' => $bid, 'name' => 'Main', 'is_main' => true, 'is_active' => true]);

        return $bag["main.$bid"] = $b->id;
    }

    private static function activeIds(int $bid): array
    {
        $bag = self::bag();

        if (! isset($bag["ids.$bid"])) {
            $ids = Branch::withoutGlobalScopes()->where('business_id', $bid)->where('is_active', true)
                ->pluck('id')->map(fn ($i) => (int) $i)->all();
            $main = self::mainId($bid);
            if (! in_array($main, $ids, true)) {
                $ids[] = $main; // the main branch is always usable
            }
            $bag["ids.$bid"] = $ids;
        }

        return $bag["ids.$bid"];
    }

    /** The branches this person is mapped to (empty when not mapped). */
    private static function mapped(User $u, array $active): array
    {
        $ids = DB::table('branch_user')->where('user_id', $u->id)->pluck('branch_id')->map(fn ($i) => (int) $i)->all();

        if (! $ids && $u->branch_id) {
            $ids = [(int) $u->branch_id];
        }

        return array_values(array_intersect($ids, $active));
    }

    /** True when this person may also choose "All branches". */
    public static function unrestricted(?User $u = null): bool
    {
        $u ??= auth()->user();
        if (! $u || ! $u->business_id) {
            return false;
        }

        if ($u->hasRole('owner')) {
            return true;
        }

        return ! self::mapped($u, self::activeIds($u->business_id)) && $u->can('view-reports');
    }

    /** Branch ids this person may open. */
    public static function allowed(?User $u = null): array
    {
        $u ??= auth()->user();
        if (! $u || ! $u->business_id) {
            return [];
        }

        $bag = self::bag();
        $key = "allowed.{$u->id}";
        if (isset($bag[$key])) {
            return $bag[$key];
        }

        $active = self::activeIds($u->business_id);
        $mapped = $u->hasRole('owner') ? [] : self::mapped($u, $active);

        $result = $u->hasRole('owner') ? $active
            : ($mapped ?: ($u->can('view-reports') ? $active : [self::mainId($u->business_id)]));

        return $bag[$key] = $result;
    }

    /** The branch this person is working in. */
    public static function currentId(?User $user = null): ?int
    {
        $u = $user ?? auth()->user();
        if (! $u || ! $u->business_id) {
            return null;
        }

        $sel = session('branch_id');
        $key = "cur.{$u->id}." . json_encode($sel);
        $bag = self::bag();

        if (isset($bag[$key])) {
            return $bag[$key];
        }

        $allowed = self::allowed($u);
        $main = self::mainId($u->business_id);

        if (is_numeric($sel) && in_array((int) $sel, $allowed, true)) {
            $id = (int) $sel;
        } elseif ($sel === 'all' && self::unrestricted($u)) {
            $id = $main; // combined view: selling and stock still happen at the main branch
        } elseif ($u->branch_id && in_array((int) $u->branch_id, $allowed, true)) {
            $id = (int) $u->branch_id;
        } elseif (in_array($main, $allowed, true)) {
            $id = $main;
        } else {
            $id = $allowed[0] ?? $main;
        }

        return $bag[$key] = $id;
    }

    /** What reports and lists show: one branch, or null for all branches combined. */
    public static function viewing(): ?int
    {
        $u = auth()->user();
        if (! $u || ! $u->business_id) {
            return null;
        }

        if (session('branch_id') === 'all' && self::unrestricted($u) && self::multi()) {
            return null;
        }

        return self::currentId($u);
    }

    public static function multi(): bool
    {
        $bid = auth()->user()?->business_id;
        if (! $bid) {
            return false;
        }

        $bag = self::bag();

        return $bag["multi.$bid"] ??= Branch::withoutGlobalScopes()
            ->where('business_id', $bid)->where('is_active', true)->count() > 1;
    }

    /** Restrict a query to one branch. Records with no branch belong to the main branch. */
    public static function limit($query, int $branch, string $col)
    {
        return $branch === self::mainId()
            ? $query->where(fn ($q) => $q->where($col, $branch)->orWhereNull($col))
            : $query->where($col, $branch);
    }

    /** SQL for the quantity of a variant in what you are viewing (for report filters). */
    public static function qtySql(): string
    {
        $b = self::viewing();
        if ($b === null || ! self::multi()) {
            return 'product_variants.stock_quantity';
        }

        $main = (int) self::mainId();
        $b = (int) $b;

        if ($b === $main) {
            return "(product_variants.stock_quantity - COALESCE((SELECT SUM(bs.quantity) FROM branch_stocks bs "
                . "WHERE bs.product_variant_id = product_variants.id AND bs.branch_id <> {$main}), 0))";
        }

        return "COALESCE((SELECT bs.quantity FROM branch_stocks bs "
            . "WHERE bs.product_variant_id = product_variants.id AND bs.branch_id = {$b}), 0)";
    }

    private static function others(int $bid): array
    {
        $bag = self::bag();

        return $bag["others.$bid"] ??= BranchStock::withoutGlobalScopes()
            ->where('business_id', $bid)->where('branch_id', '!=', self::mainId($bid))
            ->selectRaw('product_variant_id, SUM(quantity) as q')->groupBy('product_variant_id')
            ->pluck('q', 'product_variant_id')->all();
    }

    private static function rows(int $bid, int $branch): array
    {
        $bag = self::bag();

        return $bag["rows.$branch"] ??= BranchStock::withoutGlobalScopes()
            ->where('business_id', $bid)->where('branch_id', $branch)
            ->pluck('quantity', 'product_variant_id')->all();
    }

    /** Stock of one variant at one branch. The main branch is the total minus the others. */
    public static function quantity(ProductVariant $v, ?int $branch = null): int
    {
        $bid = (int) $v->business_id;
        $branch ??= self::currentId();

        if ($branch === self::mainId($bid)) {
            return (int) $v->stock_quantity - (int) (self::others($bid)[$v->id] ?? 0);
        }

        return (int) (self::rows($bid, (int) $branch)[$v->id] ?? 0);
    }

    private static function validName(string $name, ?int $ignoreId = null): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            self::fail('name', 'Enter a branch name (80 characters at most).');
        }
        if (Branch::where('name', $name)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            self::fail('name', 'That name is already used.');
        }

        return $name;
    }

    public static function create(string $name): Branch
    {
        $u = auth()->user();
        abort_unless($u && $u->business_id && $u->can('manage-settings'), 403);

        self::mainId();
        $name = self::validName($name);

        $cap = max(1, (int) $u->business->expected_branches);
        if (Branch::count() >= $cap) {
            self::fail('name', "This shop is set up for {$cap} branch(es). Ask KraftPOS support to raise the limit.");
        }

        $b = Branch::create(['name' => $name]);
        self::forget();

        return $b;
    }

    public static function rename(int $id, string $name): Branch
    {
        $u = auth()->user();
        abort_unless($u && $u->business_id && $u->can('manage-settings'), 403);

        $b = Branch::findOrFail($id);
        $b->name = self::validName($name, $b->id);
        $b->save();
        self::forget();

        return $b;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

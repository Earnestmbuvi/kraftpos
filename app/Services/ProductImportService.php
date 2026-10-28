<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

class ProductImportService
{
    public const MAX_ROWS = 500;

    public const HEADERS = [
        'name', 'category', 'unit', 'sku', 'barcode',
        'cost_price', 'selling_price', 'stock', 'low_stock_threshold', 'type', 'tax', 'branches',
    ];

    /**
     * @param  array  $defaultBranchIds  branches for rows whose "branches" cell is empty (empty = every branch)
     * @return array{created:int, errors:array}
     */
    public static function run(string $path, array $defaultBranchIds = []): array
    {
        $user = auth()->user();
        abort_unless($user && $user->business_id && $user->can('manage-products'), 403);

        [$rows, $errors] = self::read($path);
        if ($errors) {
            return ['created' => 0, 'errors' => $errors];
        }

        // Only branches of this shop are accepted (the Branch model is tenant-scoped)
        $default = Branch::whereIn('id', array_map('intval', $defaultBranchIds))->pluck('id')->map(fn ($i) => (int) $i)->all();

        [$clean, $errors] = self::validate($rows);
        if ($errors) {
            return ['created' => 0, 'errors' => $errors];
        }

        DB::transaction(function () use ($clean, $default) {
            foreach ($clean as $r) {
                $product = Product::create([
                    'name' => $r['name'],
                    'category_id' => $r['category_id'],
                    'type' => 'single',
                    'is_service' => $r['service'],
                    'unit' => $r['unit'],
                    'tax_class' => $r['tax_class'],
                ]);

                $variant = $product->variants()->create([
                    'name' => 'Default',
                    'sku' => $r['sku'],
                    'barcode' => $r['barcode'],
                    'cost_price' => $r['cost'],
                    'selling_price' => $r['price'],
                    'stock_quantity' => $r['stock'],
                    'low_stock_threshold' => $r['low'],
                ]);

                StockService::recordOpening($variant);

                // A branch named on the row wins; otherwise the ticked default; none means every branch
                $branches = $r['branch_ids'] ?: $default;
                if ($branches) {
                    $product->branches()->sync($branches);
                }
            }
        });

        return ['created' => count($clean), 'errors' => []];
    }

    private static function read(string $path): array
    {
        $fh = @fopen($path, 'r');
        if (! $fh) {
            return [[], ['The file could not be read.']];
        }

        $first = fgets($fh);
        if ($first === false) {
            return [[], ['The file is empty.']];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($fh);

        $headers = null;
        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
            $line++;
            $cells = array_map(function ($c) {
                $c = (string) $c;
                if (! mb_check_encoding($c, 'UTF-8')) {
                    $c = mb_convert_encoding($c, 'UTF-8', 'Windows-1252');
                }
                return trim($c);
            }, $cells);

            if ($line === 1) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
                $headers = array_map(fn ($h) => str_replace([' ', '-'], '_', mb_strtolower($h)), $cells);

                foreach (['name', 'selling_price'] as $need) {
                    if (! in_array($need, $headers, true)) {
                        return [[], ["The header row is missing the \"{$need}\" column. Download the template to see the layout."]];
                    }
                }
                continue;
            }

            if (count(array_filter($cells, fn ($c) => $c !== '')) === 0) {
                continue; // blank line
            }

            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $cells[$i] ?? '';
            }
            $row['_line'] = $line;
            $rows[] = $row;

            if (count($rows) > self::MAX_ROWS) {
                return [[], ['The file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.']];
            }
        }
        fclose($fh);

        return $rows ? [$rows, []] : [[], ['There are no product rows in the file.']];
    }

    private static function validate(array $rows): array
    {
        $errors = [];
        $clean = [];

        $categories = Category::active()->get()->mapWithKeys(fn ($c) => [mb_strtolower($c->name) => $c->id]);
        $units = Unit::active()->pluck('name')->mapWithKeys(fn ($n) => [mb_strtolower($n) => $n]);
        $branchIds = Branch::active()->get()->mapWithKeys(fn ($b) => [mb_strtolower($b->name) => (int) $b->id]);

        $skus = collect($rows)->pluck('sku')->filter()->unique()->values()->all();
        $codes = collect($rows)->pluck('barcode')->filter()->unique()->values()->all();
        $db = [
            'sku' => ProductVariant::whereIn('sku', $skus)->pluck('sku')->map(fn ($s) => mb_strtolower($s))->all(),
            'barcode' => ProductVariant::whereIn('barcode', $codes)->pluck('barcode')->map(fn ($s) => mb_strtolower($s))->all(),
        ];
        $seen = ['sku' => [], 'barcode' => []];

        foreach ($rows as $r) {
            $n = $r['_line'];
            $problems = [];

            $name = $r['name'] ?? '';
            if ($name === '' || mb_strlen($name) > 150) {
                $problems[] = 'Name is required (150 characters at most).';
            }

            $type = mb_strtolower($r['type'] ?? '');
            if (! in_array($type, ['', 'product', 'service'], true)) {
                $problems[] = 'Type must be "product" or "service".';
            }
            $service = $type === 'service';

            $taxRaw = mb_strtolower(trim($r['tax'] ?? ''));
            $taxMap = ['' => 'standard', 'standard' => 'standard', 'taxable' => 'standard', 'vat' => 'standard',
                       'zero' => 'zero', 'zero-rated' => 'zero', 'zero rated' => 'zero', '0' => 'zero', 'exempt' => 'exempt'];
            $taxClass = $taxMap[$taxRaw] ?? null;
            if ($taxClass === null) {
                $problems[] = 'Tax must be "standard", "zero" or "exempt".';
            }

            $catId = null;
            $cat = $r['category'] ?? '';
            if ($cat !== '') {
                $catId = $categories[mb_strtolower($cat)] ?? null;
                if (! $catId) {
                    $problems[] = "Category \"{$cat}\" does not exist. Add it under Setup first.";
                }
            }

            $unitRaw = mb_strtolower($r['unit'] ?? '');
            $unit = $unitRaw === '' ? ($units['pcs'] ?? null) : ($units[$unitRaw] ?? null);
            if (! $unit) {
                $problems[] = $unitRaw === '' ? 'Unit is required.' : "Unit \"{$r['unit']}\" does not exist. Add it under Setup first.";
            }

            // Branches: names separated by |, empty means "use the default"
            $rowBranches = [];
            foreach (array_filter(array_map('trim', explode('|', (string) ($r['branches'] ?? '')))) as $bn) {
                $bid = $branchIds[mb_strtolower($bn)] ?? null;
                $bid ? $rowBranches[] = $bid : $problems[] = "Branch \"{$bn}\" does not exist or is disabled.";
            }

            $sku = ($r['sku'] ?? '') ?: null;
            $code = ($r['barcode'] ?? '') ?: null;
            foreach (['sku' => ['SKU', $sku], 'barcode' => ['Barcode', $code]] as $key => [$label, $val]) {
                if ($val === null) {
                    continue;
                }
                $k = mb_strtolower($val);
                if (mb_strlen($val) > 50) {
                    $problems[] = "{$label} is too long (50 characters at most).";
                } elseif (in_array($k, $seen[$key], true)) {
                    $problems[] = "{$label} \"{$val}\" appears twice in the file.";
                } elseif (in_array($k, $db[$key], true)) {
                    $problems[] = "{$label} \"{$val}\" is already used by another product.";
                }
                $seen[$key][] = $k;
            }

            $num = fn ($v) => str_replace(',', '', (string) $v);
            $price = $num($r['selling_price'] ?? '');
            if ($price === '' || ! is_numeric($price) || (float) $price < 0) {
                $problems[] = 'Selling price must be a number of 0 or more.';
            }
            $cost = $num($r['cost_price'] ?? '');
            if ($cost !== '' && (! is_numeric($cost) || (float) $cost < 0)) {
                $problems[] = 'Cost price must be a number of 0 or more.';
            }
            $stock = $num($r['stock'] ?? '');
            if (! $service && $stock !== '' && ! preg_match('/^\d+$/', $stock)) {
                $problems[] = 'Stock must be a whole number of 0 or more.';
            }
            $low = $num($r['low_stock_threshold'] ?? '');
            if (! $service && $low !== '' && ! preg_match('/^\d+$/', $low)) {
                $problems[] = 'Low stock alert must be a whole number of 0 or more.';
            }

            foreach ($problems as $m) {
                $errors[] = "Row {$n}: {$m}";
            }

            if (! $problems) {
                $clean[] = [
                    'name' => $name, 'category_id' => $catId, 'unit' => $unit, 'service' => $service,
                    'sku' => $sku, 'barcode' => $code,
                    'cost' => $cost === '' ? 0 : (float) $cost, 'price' => (float) $price,
                    'stock' => $service || $stock === '' ? 0 : (int) $stock,
                    'low' => $service || $low === '' ? 0 : (int) $low,
                    'tax_class' => $taxClass,
                    'branch_ids' => array_values(array_unique($rowBranches)),
                ];
            }
        }

        if (count($errors) > 50) {
            $extra = count($errors) - 50;
            $errors = array_slice($errors, 0, 50);
            $errors[] = "...and {$extra} more problems.";
        }

        return [$clean, $errors];
    }
}

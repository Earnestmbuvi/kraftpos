<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PaymentReference
{
    /** Required for mobile money: the last 4 characters are enough, or the full code. */
    public static function required(?string $ref, string $field = 'reference'): string
    {
        $ref = strtoupper(preg_replace('/\s+/', '', trim((string) $ref)));

        if ($ref === '') {
            throw ValidationException::withMessages([
                $field => 'Enter the transaction code. The last 4 characters are enough, or enter the full code.',
            ]);
        }

        if (! preg_match('/^[A-Z0-9]{4,12}$/', $ref)) {
            throw ValidationException::withMessages([
                $field => 'The code must be 4 to 12 letters or digits.',
            ]);
        }

        return $ref;
    }
}

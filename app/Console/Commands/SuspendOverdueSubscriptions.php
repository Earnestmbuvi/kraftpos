<?php

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;

class SuspendOverdueSubscriptions extends Command
{
    protected $signature = 'billing:suspend-overdue';

    protected $description = 'Suspend active merchants whose subscription ended more than the grace period ago';

    public function handle(): int
    {
        $cutoff = today()->subDays((int) config('billing.grace_days'));
        $count = 0;

        Business::where('status', 'active')
            ->whereNotNull('subscription_ends_at')
            ->whereDate('subscription_ends_at', '<', $cutoff)
            ->each(function (Business $b) use (&$count) {
                $b->status = 'suspended';
                $b->billing_suspended = true;
                $b->status_reason = 'Subscription expired on ' . $b->subscription_ends_at->format('d M Y');
                $b->save();
                $count++;
            });

        $this->info("Suspended {$count} merchant(s).");

        return self::SUCCESS;
    }
}

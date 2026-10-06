<?php

namespace App\View\Components;

use App\Models\Organization;
use App\Support\QuotaUsage;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Banner on workspace and broadcast pages while a volume quota is exhausted.
 */
class QuotaExhaustedBanner extends Component
{
    public bool $exhausted;

    public function __construct(
        public Organization $organization,
        QuotaUsage $usage,
    ) {
        $this->exhausted = $usage->volumeExhausted($organization);
    }

    public function shouldRender(): bool
    {
        return $this->exhausted;
    }

    public function render(): View
    {
        return view('components.quota-exhausted-banner');
    }
}

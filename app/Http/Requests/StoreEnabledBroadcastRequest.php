<?php

namespace App\Http\Requests;

use App\Enums\QuotaMetric;
use App\Models\Broadcast;
use App\Support\ResourceQuotas;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreEnabledBroadcastRequest extends FormRequest
{
    /**
     * Checked before validation so a viewer is refused outright.
     */
    public function authorize(): bool
    {
        $broadcast = $this->route('broadcast');

        if (! $broadcast instanceof Broadcast) {
            return false;
        }

        return $this->user()?->can('changeStatus', $broadcast) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<int, callable>
     */
    public function after(ResourceQuotas $quotas): array
    {
        return [
            function (Validator $validator) use ($quotas): void {
                $broadcast = $this->route('broadcast');

                if (! $broadcast instanceof Broadcast) {
                    return;
                }

                // Already enabled: nothing to consume. Archived transitions stay
                // with InvalidBroadcastTransitionException in the controller.
                if ($broadcast->enabled || $broadcast->isArchived()) {
                    return;
                }

                $organization = $broadcast->project->organization;

                if ($quotas->wouldExceed($organization, QuotaMetric::ActiveBroadcasts)) {
                    $validator->errors()->add(
                        'enabled',
                        $quotas->message($organization, QuotaMetric::ActiveBroadcasts),
                    );
                }
            },
        ];
    }
}

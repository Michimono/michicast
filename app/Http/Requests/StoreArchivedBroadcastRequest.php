<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresConfirmation;
use App\Models\Broadcast;
use Illuminate\Foundation\Http\FormRequest;

class StoreArchivedBroadcastRequest extends FormRequest
{
    use RequiresConfirmation;

    public function authorize(): bool
    {
        $broadcast = $this->route('broadcast');

        if (! $broadcast instanceof Broadcast) {
            return false;
        }

        return $this->user()?->can('changeStatus', $broadcast) ?? false;
    }

    protected function confirmationSubject(): string
    {
        return 'archive this flow';
    }
}

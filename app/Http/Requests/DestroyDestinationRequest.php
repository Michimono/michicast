<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresConfirmation;
use App\Models\Destination;
use Illuminate\Foundation\Http\FormRequest;

class DestroyDestinationRequest extends FormRequest
{
    use RequiresConfirmation;

    public function authorize(): bool
    {
        $destination = $this->route('destination');

        if (! $destination instanceof Destination) {
            return false;
        }

        return $this->user()?->can('delete', $destination) ?? false;
    }

    protected function confirmationSubject(): string
    {
        return 'delete this destination';
    }
}

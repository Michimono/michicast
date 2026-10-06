<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresConfirmation;
use App\Models\Source;
use Illuminate\Foundation\Http\FormRequest;

class DestroySourceRequest extends FormRequest
{
    use RequiresConfirmation;

    public function authorize(): bool
    {
        $source = $this->route('source');

        if (! $source instanceof Source) {
            return false;
        }

        return $this->user()?->can('delete', $source) ?? false;
    }

    protected function confirmationSubject(): string
    {
        return 'delete this source';
    }
}

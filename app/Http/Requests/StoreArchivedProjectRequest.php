<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresConfirmation;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreArchivedProjectRequest extends FormRequest
{
    use RequiresConfirmation;

    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        return $this->user()?->can('changeStatus', $project) ?? false;
    }

    protected function confirmationSubject(): string
    {
        return 'archive this broadcast';
    }
}

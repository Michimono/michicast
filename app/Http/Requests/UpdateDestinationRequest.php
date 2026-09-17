<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDestinationSettings;
use App\Models\Destination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDestinationRequest extends FormRequest
{
    use ValidatesDestinationSettings;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->destination()) ?? false;
    }

    /**
     * The type is fixed once a destination exists. Credential fields are
     * optional so an empty value keeps the encrypted secret already stored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->destination()->type;

        return [
            'name' => ['required', 'string', 'max:255'],
            'config' => ['nullable', 'array'],
            'credentials' => ['nullable', 'array'],
            ...$this->configurationRules($type),
            ...$this->optionalCredentialRules($type),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->settingAttributes($this->destination()->type);
    }

    public function name(): string
    {
        return $this->string('name')->trim()->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->configurationFor($this->destination()->type);
    }

    /**
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        return $this->credentialsFor(
            $this->destination()->type,
            $this->destination()->credentials ?? [],
        );
    }

    private function destination(): Destination
    {
        $destination = $this->route('destination');

        abort_unless($destination instanceof Destination, 404);

        return $destination;
    }
}

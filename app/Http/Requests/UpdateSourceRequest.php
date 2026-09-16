<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesSourceConfiguration;
use App\Models\Source;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSourceRequest extends FormRequest
{
    use ValidatesSourceConfiguration;

    /**
     * Checked before validation so a viewer is refused outright.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->source()) ?? false;
    }

    /**
     * The type is fixed once a source exists, because its configuration only
     * means anything to the provider that declared it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'config' => ['nullable', 'array'],
            ...$this->configurationRules($this->source()->type),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->configurationAttributes($this->source()->type);
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
        return $this->configurationFor($this->source()->type);
    }

    private function source(): Source
    {
        $source = $this->route('source');

        abort_unless($source instanceof Source, 404);

        return $source;
    }
}

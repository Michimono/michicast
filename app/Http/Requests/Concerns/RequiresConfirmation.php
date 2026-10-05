<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * A destructive action only runs when the form explicitly says the user
 * confirmed it. A bare request without the flag is refused and changes nothing.
 */
trait RequiresConfirmation
{
    /**
     * What the user is confirming, phrased to finish "Confirm that you want to ...".
     */
    abstract protected function confirmationSubject(): string;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.accepted' => 'Confirm that you want to '.$this->confirmationSubject().'.',
        ];
    }

    /**
     * The confirmation flag has no field of its own, so the refusal is also
     * flashed where the layout shows errors.
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->session()->flash('error', $validator->errors()->first('confirmation'));

        parent::failedValidation($validator);
    }
}

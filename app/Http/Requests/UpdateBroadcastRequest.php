<?php

namespace App\Http\Requests;

use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Source;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBroadcastRequest extends FormRequest
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

        return $this->user()?->can('update', $broadcast) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $broadcast = $this->route('broadcast');
        $projectId = $broadcast instanceof Broadcast ? $broadcast->project_id : 0;

        return [
            'name' => ['required', 'string', 'max:255'],
            'source_id' => [
                'required',
                'integer',
                Rule::exists(Source::class, 'id')->where(function ($query) use ($broadcast, $projectId): void {
                    $query->where('project_id', $projectId)
                        ->where(function ($query) use ($broadcast): void {
                            $query->whereNull('archived_at');

                            if ($broadcast instanceof Broadcast) {
                                $query->orWhere('id', $broadcast->source_id);
                            }
                        });
                }),
            ],
            'destination_id' => [
                'required',
                'integer',
                Rule::exists(Destination::class, 'id')->where(function ($query) use ($broadcast, $projectId): void {
                    $query->where('project_id', $projectId)
                        ->where(function ($query) use ($broadcast): void {
                            $query->whereNull('archived_at');

                            if ($broadcast instanceof Broadcast) {
                                $query->orWhere('id', $broadcast->destination_id);
                            }
                        });
                }),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source_id' => 'source',
            'destination_id' => 'destination',
        ];
    }

    public function name(): string
    {
        return $this->string('name')->trim()->toString();
    }

    public function sourceId(): int
    {
        return $this->integer('source_id');
    }

    public function destinationId(): int
    {
        return $this->integer('destination_id');
    }
}

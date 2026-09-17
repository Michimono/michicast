<x-layout :title="'Transform · '.$broadcast->name">
    <x-card heading="Transform" :kicker="$broadcast->name" eared class="max-w-lg">
        <p class="text-sm text-ink">
            Build the outgoing message with placeholders, then optional formatting rules in order.
            Destinations handle their own escaping.
        </p>

        <p class="text-sm text-muted">
            Placeholders:
            @foreach ($placeholders as $name => $description)
                <code class="font-mono text-xs text-ink">{{ '{'.'{'.$name.'}'.'}' }}</code>@if (! $loop->last), @endif
            @endforeach
        </p>

        <form
            class="flex flex-col gap-4"
            method="POST"
            action="{{ route('broadcast-transforms.update', $broadcast) }}"
            data-transform-form
        >
            @csrf
            @method('PATCH')

            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium" for="template">Template</label>
                <textarea
                    id="template"
                    name="template"
                    rows="6"
                    @error('template') aria-invalid="true" @enderror
                    @class([
                        'w-full rounded-md border bg-paper-raised px-3 py-2 font-mono text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent',
                        'border-line' => ! $errors->has('template'),
                        'border-state-fail' => $errors->has('template'),
                    ])
                >{{ old('template', $template) }}</textarea>
                <p class="text-sm text-muted">Leave blank to use the default: <code class="font-mono text-xs">{{ $defaultTemplate }}</code></p>
                @error('template')
                    <p class="text-sm text-state-fail">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-3" data-transform-rules>
                @forelse ($rules as $index => $rule)
                    <x-broadcast-transform-rule
                        :index="$index"
                        :rule="$rule"
                        :types="$types"
                    />
                @empty
                @endforelse
            </div>

            <template data-transform-rule-template>
                <x-broadcast-transform-rule
                    :index="'__INDEX__'"
                    :rule="[]"
                    :types="$types"
                />
            </template>

            <div class="flex flex-wrap items-center gap-2">
                <x-button type="button" variant="ghost" :paw="false" data-transform-add>Add rule</x-button>
                <x-button
                    type="submit"
                    variant="ghost"
                    :paw="false"
                    formaction="{{ route('broadcast-transform-previews.store', $broadcast) }}"
                >
                    Preview
                </x-button>
                <x-button>Save transform</x-button>
            </div>
        </form>

        @if ($previewBody !== null)
            <div class="flex flex-col gap-1.5 rounded-md border border-line bg-paper-raised p-3">
                <p class="text-sm font-medium text-ink">Preview</p>
                <pre class="whitespace-pre-wrap font-mono text-sm text-ink">{{ $previewBody }}</pre>
            </div>
        @endif

        <p>
            <x-button href="{{ route('broadcasts.edit', $broadcast) }}" variant="ghost" :paw="false">Back to broadcast</x-button>
        </p>
    </x-card>
</x-layout>

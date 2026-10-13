<x-layout title="New source">
    @php
        $formName = $formName ?? old('name', '');
        $formConfig = old('config', $formConfig ?? []);
        $supportsPreview = $supportsPreview ?? ($type !== null);
        $previewEntry = $previewEntry ?? null;
        $previewError = $previewError ?? null;
    @endphp

    <div class="mx-auto grid w-full max-w-5xl gap-6 lg:grid-cols-2" data-source-create>
        <x-card heading="New source" :kicker="$project->name" eared>
            <p class="text-sm text-ink">
                Choose a source type, fill in its settings, and test the feed before you add it.
            </p>

            <form class="flex flex-col gap-4" method="GET" action="{{ route('broadcast-sources.create', $project) }}">
                <x-select
                    name="type"
                    label="Source type"
                    :options="$types"
                    :value="$type"
                    hint="Settings for the chosen type appear below."
                    data-auto-submit
                />
            </form>

            <x-whisker-divider />

            <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcast-sources.store', $project) }}" data-source-create-form>
                @csrf

                <input type="hidden" name="type" value="{{ $type }}">

                <x-input name="name" label="Name" :value="$formName" autofocus required />

                <x-provider-fields :fields="$fields" :values="$formConfig" />

                <div class="flex flex-wrap items-center gap-2">
                    @if ($supportsPreview)
                        <x-button
                            type="submit"
                            formaction="{{ route('broadcast-source-previews.store', $project) }}"
                            formmethod="POST"
                            variant="ghost"
                            :paw="false"
                            data-source-test
                            disabled
                        >
                            Test
                        </x-button>
                    @endif

                    <x-button>Add source</x-button>
                </div>
            </form>
        </x-card>

        <x-card heading="Preview" kicker="Latest entry" class="min-h-64">
            @if ($previewError)
                <p class="rounded-md border border-line bg-paper px-3 py-2 text-sm text-state-fail">
                    {{ $previewError }}
                </p>
            @elseif ($previewEntry)
                <div class="max-h-[32rem] overflow-y-auto">
                    <dl class="flex flex-col gap-4 text-sm">
                        @if ($previewEntry->title)
                            <div class="flex flex-col gap-1">
                                <dt class="font-mono text-xs tracking-wide text-muted uppercase">Title</dt>
                                <dd class="text-ink">{{ $previewEntry->title }}</dd>
                            </div>
                        @endif

                        @if ($previewEntry->author)
                            <div class="flex flex-col gap-1">
                                <dt class="font-mono text-xs tracking-wide text-muted uppercase">Author</dt>
                                <dd class="text-ink">{{ $previewEntry->author }}</dd>
                            </div>
                        @endif

                        @if ($previewEntry->published_at)
                            <div class="flex flex-col gap-1">
                                <dt class="font-mono text-xs tracking-wide text-muted uppercase">Published</dt>
                                <dd class="font-mono text-ink">
                                    <x-timestamp :value="$previewEntry->published_at" />
                                </dd>
                            </div>
                        @endif

                        @if ($previewEntry->canonical_url)
                            <div class="flex flex-col gap-1">
                                <dt class="font-mono text-xs tracking-wide text-muted uppercase">URL</dt>
                                <dd>
                                    <a class="break-all font-mono text-xs text-accent hover:underline" href="{{ $previewEntry->canonical_url }}" rel="noopener noreferrer" target="_blank">
                                        {{ $previewEntry->canonical_url }}
                                    </a>
                                </dd>
                            </div>
                        @endif

                        @if ($previewEntry->external_id)
                            <div class="flex flex-col gap-1">
                                <dt class="font-mono text-xs tracking-wide text-muted uppercase">External id</dt>
                                <dd class="break-all font-mono text-xs text-ink">{{ $previewEntry->external_id }}</dd>
                            </div>
                        @endif

                        @if ($previewEntry->body)
                            <div class="flex flex-col gap-1">
                                <dt class="font-mono text-xs tracking-wide text-muted uppercase">Body</dt>
                                <dd class="whitespace-pre-wrap text-ink">{{ $previewEntry->body }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @else
                <p class="text-sm text-muted">
                    Enter a feed URL and press Test to see the latest entry here.
                </p>
            @endif
        </x-card>
    </div>
</x-layout>
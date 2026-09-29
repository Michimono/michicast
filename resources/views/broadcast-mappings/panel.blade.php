{{-- Recipe-style mapping editor. Driven by data-mapping-editor JSON. --}}
@php
    /** @var array<string, mixed> $mappingEditor */
    $ready = $mappingEditor['ready'] ?? false;
@endphp

<section class="flex flex-col gap-4" data-mapping-root @if ($ready) data-mapping-editor='@json($mappingEditor)' @endif>
    <div>
        <h2 id="mapping-heading" class="font-display text-xl tracking-tight text-ink">Mapping</h2>
        <p class="mt-0.5 text-sm text-muted">Build the destination data step by step.</p>
    </div>

    @unless ($ready)
        <div class="flex flex-col items-start gap-2 py-4" data-pipeline-empty="mapping">
            <h3 class="font-display text-lg tracking-tight text-ink">No mapping yet</h3>
            <p class="max-w-sm text-sm text-muted">Connect the source fields to the destination fields. Choose both ends of the pipeline first.</p>
        </div>
    @else
        <div hidden data-mapping-auto class="rounded-md border border-line bg-paper px-3 py-2 text-sm text-ink">
            <span data-mapping-auto-text></span>
            <span class="ml-2 inline-flex gap-2">
                <button type="button" class="cursor-pointer text-accent underline hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-mapping-review>Review</button>
                <button type="button" class="cursor-pointer text-muted underline hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-mapping-reset-defaults>Reset</button>
            </span>
        </div>

        <div class="flex flex-col" data-mapping-steps role="list" aria-label="Mapping steps"></div>

        <div @if (($mappingEditor['proposed_count'] ?? 0) > 0) hidden @endif class="flex flex-col items-start gap-2 py-4" data-mapping-empty data-pipeline-empty="mapping">
            <h3 class="font-display text-lg tracking-tight text-ink">No steps yet</h3>
            <p class="max-w-sm text-sm text-muted">Connect the source fields to the destination fields. Add a Set field step to start building the destination data.</p>
        </div>

        <div class="relative">
            <button
                type="button"
                class="cursor-pointer inline-flex items-center gap-1 rounded-md border border-line bg-paper-raised px-3 py-2 text-sm font-medium text-ink hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
                data-mapping-add
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="mapping-add-menu"
            >
                + Add step
            </button>
            <div
                hidden
                id="mapping-add-menu"
                data-mapping-add-menu
                class="absolute z-20 mt-2 min-w-[14rem] rounded-md border border-line bg-paper-raised p-1 shadow-sm"
                role="menu"
            ></div>
        </div>

        <div hidden data-mapping-step-editor class="border-t border-b border-line py-4">
            <div class="flex flex-col gap-3" data-mapping-step-editor-fields></div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" class="cursor-pointer rounded-md border border-line px-3 py-1.5 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-mapping-step-cancel>Cancel</button>
                <button type="button" class="cursor-pointer rounded-md bg-ink px-3 py-1.5 text-sm text-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-mapping-step-save>Save step</button>
            </div>
        </div>

        <div class="flex flex-col gap-2 border-t border-line pt-6">
            <div class="flex flex-col gap-0.5">
                <h3 class="font-display text-lg tracking-tight text-ink">Preview</h3>
                <p class="text-sm text-muted">Nothing will be sent.</p>
            </div>
            <div class="py-2" data-mapping-final-preview>
                <p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">Message</p>
                <pre class="mt-1 whitespace-pre-wrap text-sm text-ink" data-mapping-preview-text></pre>
                <div class="mt-3" data-mapping-preview-media-wrap hidden>
                    <p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">Media</p>
                    <ul class="mt-1 list-disc pl-5 text-sm text-ink" data-mapping-preview-media></ul>
                </div>
                <div class="mt-3" data-mapping-preview-errors></div>
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent">View destination data</summary>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-paper-raised p-3 font-mono text-xs text-ink" data-mapping-preview-raw></pre>
                </details>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 pt-2">
            <button
                type="button"
                class="cursor-pointer inline-flex items-center rounded-md bg-ink px-3 py-2 text-sm font-medium text-paper hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent disabled:opacity-50"
                data-mapping-save
            >
                Save changes
            </button>
            <p class="text-sm text-muted" data-mapping-save-status aria-live="polite"></p>
        </div>
    @endunless
</section>

<dialog data-mapping-drawer="source" class="m-auto w-[min(28rem,calc(100%-2rem))] max-h-[80vh] overflow-hidden rounded-lg border border-line bg-paper p-0 text-ink shadow-lg backdrop:bg-ink/40">
    <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h3 class="font-display text-lg">Source fields</h3>
        <button type="button" class="cursor-pointer text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-close-dialog>Close</button>
    </div>
    <div class="overflow-y-auto p-4" data-mapping-drawer-source-body></div>
    <div class="border-t border-line px-4 py-3">
        <details>
            <summary class="cursor-pointer text-sm text-muted">View raw data</summary>
            <pre class="mt-2 overflow-x-auto font-mono text-xs" data-mapping-drawer-source-raw></pre>
        </details>
    </div>
</dialog>

<dialog data-mapping-drawer="destination" class="m-auto w-[min(28rem,calc(100%-2rem))] max-h-[80vh] overflow-hidden rounded-lg border border-line bg-paper p-0 text-ink shadow-lg backdrop:bg-ink/40">
    <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h3 class="font-display text-lg">Destination fields</h3>
        <button type="button" class="cursor-pointer text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-close-dialog>Close</button>
    </div>
    <div class="overflow-y-auto p-4" data-mapping-drawer-destination-body></div>
</dialog>

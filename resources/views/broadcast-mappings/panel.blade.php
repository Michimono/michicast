{{-- Recipe-style mapping editor. Driven by data-mapping-editor JSON. --}}
@php
    /** @var array<string, mixed> $mappingEditor */
    $ready = $mappingEditor['ready'] ?? false;
@endphp

<div class="flex flex-col" data-mapping-root @if ($ready) data-mapping-editor='@json($mappingEditor)' @endif>
    {{-- MAPPING centerpiece (Level 1) --}}
    <section class="relative flex flex-col gap-4 rounded-card border border-line bg-paper-raised p-5 sm:p-6" aria-labelledby="mapping-heading">
        <p class="flex items-center gap-2.5 font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
            Mapping
            <span class="house-fill h-0.5 flex-1 rounded-full"></span>
        </p>

        <div>
            <h2 id="mapping-heading" class="font-display text-xl tracking-tight text-ink">Mapping</h2>
            <p class="mt-0.5 text-sm text-muted">Build the destination data step by step.</p>
        </div>

        @unless ($ready)
            <div class="flex flex-col items-start gap-2 py-2" data-pipeline-empty="mapping">
                <h3 class="font-display text-lg tracking-tight text-ink">No mapping yet</h3>
                <p class="max-w-sm text-sm text-muted">Connect the source fields to the destination fields. Choose both ends of the pipeline first.</p>
            </div>
        @else
            <div hidden data-mapping-auto class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                <span data-mapping-auto-text></span>
                <button type="button" class="cursor-pointer text-accent underline hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-mapping-review>Review</button>
                <button type="button" class="cursor-pointer text-muted underline hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-mapping-reset-defaults>Reset</button>
            </div>

            <div class="flex flex-col gap-2" data-mapping-steps role="list" aria-label="Mapping steps"></div>

            <div @if (($mappingEditor['proposed_count'] ?? 0) > 0) hidden @endif class="flex flex-col items-start gap-2 py-2" data-mapping-empty data-pipeline-empty="mapping">
                <h3 class="font-display text-lg tracking-tight text-ink">No steps yet</h3>
                <p class="max-w-sm text-sm text-muted">Connect the source fields to the destination fields. Add a Set field step to start building the destination data.</p>
            </div>

            <div class="relative">
                <button
                    type="button"
                    class="cursor-pointer inline-flex items-center gap-1 rounded-md border border-line bg-paper px-3 py-2 text-sm font-medium text-ink hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
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
                    class="absolute z-20 mt-2 min-w-[15rem] rounded-md border border-line bg-paper-raised p-1 shadow-sm"
                    role="menu"
                ></div>
            </div>

            {{-- Step editor mounts inline inside the edited step card (see mapping-editor.js). --}}

            <div class="flex flex-wrap items-center gap-2 border-t border-line pt-4">
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

    @if ($ready)
        <div class="flex justify-center py-2" aria-hidden="true">
            <span class="select-none text-base leading-none text-line-strong">↓</span>
        </div>

        {{-- PREVIEW (Level 1) — message-like, not a grey form --}}
        <section class="relative flex flex-col gap-3 rounded-card border border-line bg-paper-raised p-5 sm:p-6" aria-labelledby="mapping-preview-heading">
            <p class="flex items-center gap-2.5 font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
                Preview
                <span class="house-fill h-0.5 flex-1 rounded-full"></span>
            </p>
            <div class="flex flex-col gap-0.5">
                <h3 id="mapping-preview-heading" class="font-display text-lg tracking-tight text-ink">Preview</h3>
                <p class="text-sm text-muted">Nothing will be sent.</p>
            </div>

            <div class="py-1" data-mapping-final-preview>
                {{-- Telegram-like message bubble on cool paper --}}
                <div class="rounded-2xl rounded-bl-md bg-paper px-4 py-3 shadow-sm ring-1 ring-line/60">
                    <pre class="whitespace-pre-wrap font-sans text-sm leading-relaxed text-ink" data-mapping-preview-text></pre>
                    <div class="mt-2 border-t border-line/50 pt-2" data-mapping-preview-media-wrap hidden>
                        <p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">Media</p>
                        <ul class="mt-1 list-disc pl-5 text-sm text-ink" data-mapping-preview-media></ul>
                    </div>
                </div>
                <div class="mt-3" data-mapping-preview-errors></div>
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent">View destination data</summary>
                    <pre class="mt-2 overflow-x-auto rounded-md border border-line bg-paper p-3 font-mono text-xs text-ink" data-mapping-preview-raw></pre>
                </details>
            </div>
        </section>
    @endif
</div>

<dialog data-mapping-drawer="source" class="m-auto w-[min(28rem,calc(100%-2rem))] max-h-[80vh] overflow-hidden rounded-lg border border-line bg-paper-raised p-0 text-ink shadow-lg backdrop:bg-ink/40">
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

<dialog data-mapping-drawer="destination" class="m-auto w-[min(28rem,calc(100%-2rem))] max-h-[80vh] overflow-hidden rounded-lg border border-line bg-paper-raised p-0 text-ink shadow-lg backdrop:bg-ink/40">
    <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h3 class="font-display text-lg">Destination fields</h3>
        <button type="button" class="cursor-pointer text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-close-dialog>Close</button>
    </div>
    <div class="overflow-y-auto p-4" data-mapping-drawer-destination-body></div>
</dialog>

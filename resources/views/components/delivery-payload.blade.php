@props(['payload'])

{{--
    The internal object an Activity log (testing) delivery received (#202), for
    analysis. Native <details>, so it expands on click without JS; Blade escapes
    the JSON. Credentials are never part of it.
--}}
@if (is_array($payload) && $payload !== [])
    <details {{ $attributes->class(['group rounded-md border border-line bg-paper']) }} data-delivery-payload>
        <summary class="cursor-pointer px-3 py-2 font-mono text-xs text-muted select-none hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent">
            Sent payload <span class="text-muted/70">(JSON)</span>
        </summary>
        <pre class="max-h-96 overflow-auto border-t border-line px-3 py-2 font-mono text-xs leading-relaxed text-ink">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
    </details>
@endif
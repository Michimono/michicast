@php
    // Node colours name a kind of node, as on the real canvas. Literal class names so Tailwind finds them.
    $tints = [
        'source' => ['tile' => 'fill-node-source/10', 'stroke' => 'stroke-node-source', 'fill' => 'fill-node-source'],
        'transform' => ['tile' => 'fill-node-transform/10', 'stroke' => 'stroke-node-transform', 'fill' => 'fill-node-transform'],
        'destination' => ['tile' => 'fill-node-destination/10', 'stroke' => 'stroke-node-destination', 'fill' => 'fill-node-destination'],
    ];

    $layouts = [
        ['layout' => $wide, 'class' => 'hidden md:block', 'title' => 'text-[13px]', 'meta' => 'text-[11px]', 'kicker' => 'text-[8.5px]'],
        ['layout' => $tall, 'class' => 'mx-auto max-w-sm md:hidden', 'title' => 'text-[12px]', 'meta' => 'text-[10px]', 'kicker' => 'text-[8px]'],
    ];
@endphp

{{--
    One picture, described once: assistive technology gets the summary and
    skips the drawing. Everything inside is illustration, not interface.
--}}
<div {{ $attributes->class('relative') }} role="img" aria-label="{{ $summary }}">
    {{-- The cat watches the pipeline from the top edge of the canvas. --}}
    <x-brand.mark variant="cat" class="absolute right-[9%] bottom-full -mb-1.5 hidden h-24 sm:block lg:h-28" aria-hidden="true" />

    <div class="overflow-hidden rounded-2xl border border-line bg-paper shadow-canvas" aria-hidden="true">
        {{-- The editor's bar: what this broadcast is, its state, and the actions that run it. --}}
        <div class="flex items-center gap-3 border-b border-line bg-paper-raised px-4 py-2.5 sm:px-5">
            <div class="min-w-0 leading-tight">
                <p class="font-mono text-[0.5625rem] tracking-[0.14em] text-node-transform uppercase">{{ App\Enums\ProjectKind::Visual->label() }}</p>
                <p class="truncate font-display text-lg tracking-tight">Security watch</p>
            </div>

            <span class="hidden rounded-full border border-line px-2 py-0.5 font-mono text-[0.625rem] tracking-wide text-muted uppercase sm:inline">
                {{ App\Enums\ProjectStatus::Active->label() }}
            </span>

            <span class="ml-auto hidden items-center gap-1.5 font-mono text-xs text-muted sm:inline-flex">
                <span class="size-1.5 rounded-full bg-state-ok"></span>
                Saved
            </span>

            <span class="ml-auto inline-flex h-7 items-center rounded-md border border-line-strong px-2.5 text-xs font-medium sm:ml-0">Test</span>
            <span class="hidden h-7 items-center rounded-md border border-line-strong px-2.5 text-xs font-medium sm:inline-flex">Run now</span>
        </div>

        <div class="canvas-dots px-3 py-6 sm:px-6 sm:py-9 lg:px-10 lg:py-12">
            @foreach ($layouts as $variant)
                @php
                    $layout = $variant['layout'];
                @endphp

                <svg
                    class="h-auto w-full {{ $variant['class'] }}"
                    viewBox="0 0 {{ $layout['width'] }} {{ $layout['height'] }}"
                    fill="none"
                    aria-hidden="true"
                >
                    @foreach ($layout['edges'] as $edge)
                        <path d="{{ $edge['path'] }}" class="stroke-line-strong" stroke-width="1.75" stroke-linecap="round" />
                        <path d="{{ $edge['arrow'] }}" class="fill-line-strong" />

                        {{-- An item on its way. Hidden for people who ask for less motion. --}}
                        <circle r="3.5" class="{{ $tints[$edge['category']]['fill'] }} motion-reduce:hidden" opacity="0">
                            <animateMotion
                                path="{{ $edge['path'] }}"
                                dur="{{ $edge['duration'] }}s"
                                begin="{{ $edge['delay'] }}s"
                                repeatCount="indefinite"
                                calcMode="spline"
                                keyPoints="0;1"
                                keyTimes="0;1"
                                keySplines="0.4 0 0.6 1"
                            />
                            <animate
                                attributeName="opacity"
                                values="0;1;1;0"
                                keyTimes="0;0.12;0.85;1"
                                dur="{{ $edge['duration'] }}s"
                                begin="{{ $edge['delay'] }}s"
                                repeatCount="indefinite"
                            />
                        </circle>
                    @endforeach

                    @foreach ($layout['nodes'] as $node)
                        @php
                            $tint = $tints[$node['category']];
                        @endphp

                        <g>
                            @if ($node['selected'])
                                <rect
                                    x="{{ $node['x'] - 3 }}"
                                    y="{{ $node['y'] - 3 }}"
                                    width="{{ $layout['node']['width'] + 6 }}"
                                    height="{{ $layout['node']['height'] + 6 }}"
                                    rx="11"
                                    class="fill-accent/20"
                                />
                            @else
                                <rect
                                    x="{{ $node['x'] }}"
                                    y="{{ $node['y'] + 2 }}"
                                    width="{{ $layout['node']['width'] }}"
                                    height="{{ $layout['node']['height'] }}"
                                    rx="8"
                                    class="fill-ink/5"
                                />
                            @endif

                            <rect
                                x="{{ $node['x'] }}"
                                y="{{ $node['y'] }}"
                                width="{{ $layout['node']['width'] }}"
                                height="{{ $layout['node']['height'] }}"
                                rx="8"
                                @class(['fill-paper-raised', 'stroke-accent' => $node['selected'], 'stroke-line' => ! $node['selected']])
                            />

                            <rect
                                x="{{ $node['x'] + 10 }}"
                                y="{{ $node['y'] + ($layout['node']['height'] - 32) / 2 }}"
                                width="32"
                                height="32"
                                rx="6"
                                class="{{ $tint['tile'] }}"
                            />

                            <svg
                                x="{{ $node['x'] + 18 }}"
                                y="{{ $node['y'] + ($layout['node']['height'] - 16) / 2 }}"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                class="{{ $tint['stroke'] }}"
                                stroke-width="1.6"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                @switch($node['kind'])
                                    @case('source')
                                        <path d="M12 3.5v10" /><path d="m8 10 4 4 4-4" /><path d="M5 17.5h14" /><path d="M7 20.5h10" />
                                        @break
                                    @case('filter')
                                        <path d="M4 5h16l-6.5 7.5V19l-3 1.5V12.5L4 5Z" />
                                        @break
                                    @case('transform')
                                        <path d="M3.5 7h10" /><circle cx="17" cy="7" r="2.5" /><path d="M20.5 17h-10" /><circle cx="7" cy="17" r="2.5" />
                                        @break
                                    @case('mapping')
                                        <path d="M4 7h5M4 12h5M4 17h5M15 7h5M15 12h5M15 17h5M9.5 12h5" /><path d="M12.5 10l2 2-2 2" />
                                        @break
                                    @default
                                        <path d="M12 20.5V10.5" /><path d="m8 14 4-4 4 4" /><path d="M5 6.5h14" /><path d="M7 3.5h10" />
                                @endswitch
                            </svg>

                            <text
                                x="{{ $node['x'] + 52 }}"
                                y="{{ $node['y'] + $layout['node']['height'] / 2 - 11 }}"
                                class="font-mono tracking-[0.14em] uppercase {{ $variant['kicker'] }} {{ $tint['fill'] }}"
                            >{{ $node['label'] }}</text>

                            <text
                                x="{{ $node['x'] + 52 }}"
                                y="{{ $node['y'] + $layout['node']['height'] / 2 + 5 }}"
                                class="fill-ink font-medium {{ $variant['title'] }}"
                            >{{ $node['title'] }}</text>

                            <text
                                x="{{ $node['x'] + 52 }}"
                                y="{{ $node['y'] + $layout['node']['height'] / 2 + 19 }}"
                                class="fill-muted {{ $variant['meta'] }}"
                            >{{ $node['meta'] }}</text>
                        </g>
                    @endforeach

                    {{-- Handles sit on the border where a connection leaves, over the node. --}}
                    @foreach ($layout['edges'] as $edge)
                        <circle
                            cx="{{ $edge['handle']['x'] }}"
                            cy="{{ $edge['handle']['y'] }}"
                            r="4.5"
                            class="fill-paper-raised {{ $tints[$edge['category']]['stroke'] }}"
                            stroke-width="2"
                        />
                    @endforeach
                </svg>
            @endforeach
        </div>
    </div>

    {{-- What comes out the other end: one message, delivered once. --}}
    <div class="relative z-10 -mt-6 mr-3 ml-auto w-[min(18rem,88%)] rounded-xl border border-line bg-paper-raised p-4 shadow-float sm:absolute sm:right-6 sm:-bottom-14 sm:m-0 sm:w-72 md:-bottom-28 lg:-right-5 lg:-bottom-10" aria-hidden="true">
        <p class="flex items-center justify-between gap-3 font-mono text-[0.625rem] tracking-[0.14em] uppercase">
            <span class="text-node-destination">{{ $channel }}</span>

            <span class="inline-flex items-center gap-1.5 text-state-ok">
                <span class="size-1.5 rounded-full bg-state-ok"></span>
                Delivered
            </span>
        </p>

        <p class="mt-2.5 text-sm leading-5 font-semibold text-ink">Critical: gateway update fixes remote access flaw</p>
        <p class="mt-1 text-xs leading-5 text-muted">Versions 4.2 to 4.6 are affected. Update before the next maintenance window.</p>
        <p class="mt-2 truncate font-mono text-[0.6875rem] text-accent">advisories.example/2026/0418</p>
        <p class="mt-3 font-mono text-[0.625rem] text-muted">{{ $recipient }} / 09:02</p>
    </div>
</div>

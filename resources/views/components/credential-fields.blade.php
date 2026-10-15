@props(['fields', 'masks' => [], 'preserving' => false, 'size' => 'md', 'idPrefix' => null, 'grid' => false])

{{--
    Secrets are typed in, never shown. A stored one appears only as a short masked suffix.
    An empty edit keeps the stored value, so required applies to new destinations only.
    idPrefix keeps ids unique when several types' groups share one page.
--}}
@foreach ($fields as $field)
    @php
        $mask = $masks[$field->name] ?? null;
        $id = $idPrefix ? $idPrefix.'-credentials-'.$field->name : null;
    @endphp

    @if ($grid)
        <div class="min-w-0 sm:col-span-2">
    @endif

    <x-input
        :name="'credentials['.$field->name.']'"
        :id="$id"
        :label="$field->label"
        :hint="$preserving ? ($mask ? 'Stored value '.$mask.'. ' : '').'Leave blank to keep the stored value.' : $field->hint"
        type="password"
        autocomplete="new-password"
        value=""
        :size="$size"
        :placeholder="$preserving ? null : $field->placeholder"
        :required="! $preserving && $field->required"
    />

    @if ($grid)
        </div>
    @endif
@endforeach

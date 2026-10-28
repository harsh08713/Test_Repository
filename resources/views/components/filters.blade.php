@props(['active' => 0, 'id' => 'filters'])

{{--
    The filters of a list. From 640 px up they are laid out exactly as before. On a phone, only the part passed as `primary` (the search box)
    stays on the screen, and the rest sits behind one "Filters" button that says how many are set, so the first rows are not pushed a whole
    screen down. `active` is how many filters differ from "any"; the screen works it out, this only shows it.

        <x-filters :active="$n" id="lead-filters">
            <x-slot:primary> …search… </x-slot:primary>
            …the other fields, each `w-full sm:w-48`; a row that needs both columns on a phone takes `col-span-2`…
        </x-filters>

    Alpine keeps "open"; the panel is hidden before Alpine starts, so there is no flash of the full list of fields.
--}}
<div x-data="{ open: false }" {{ $attributes->class('flex flex-1 flex-wrap items-center gap-3') }}>
    {{ $primary ?? '' }}

    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="{{ $id }}" class="btn-secondary sm:hidden">
        Filters
        @if ($active > 0)
            <span class="badge bg-brand-600 text-white">{{ $active }}</span>
        @endif
    </button>

    <div id="{{ $id }}" x-bind:class="{ 'max-sm:grid': open }" class="hidden w-full grid-cols-2 gap-2 sm:contents">
        {{ $slot }}
    </div>
</div>

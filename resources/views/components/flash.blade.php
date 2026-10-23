@props(['page' => null])

@php
    $message = session('status');

    // Most pages say the message themselves (<x-status />, so that it also shows when only part of the page is
    // refreshed). When this page has, it is not said a second time here.
    $saidByPage = $message !== null && $page !== null && str_contains((string) $page, 'role="status">'.e($message).'</div>');
@endphp

@if ($message && ! $saidByPage)
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" x-transition
         class="mb-4 flex items-start justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
        <span>{{ $message }}</span>
        <button type="button" x-on:click="show = false" class="text-emerald-600 hover:text-emerald-800" aria-label="Dismiss"><x-icon name="x" class="size-4" /></button>
    </div>
@endif

{{--
    What an action has just done ("Saved.", "Branch deleted."), said inside the page itself — so that it also appears
    when only this part of the page was refreshed, which the notice in the layout cannot do. The layout's notice
    (components/flash) recognises this markup and stays quiet when a page has already said it.
--}}
@if (session('status'))
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
@endif

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Stop receiving messages · {{ $company }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-900 font-sans text-slate-800 antialiased">
    <main class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl space-y-4">
            @if ($done)
                <h1 class="text-lg font-semibold text-slate-900">You have been unsubscribed</h1>
                <p class="text-sm text-slate-600">{{ $company }} will no longer send marketing messages to {{ $masked }} on {{ $channel->label() }}. This can take a few minutes to reach every campaign. If this was a mistake, tell them and they can record your consent again.</p>
            @else
                <h1 class="text-lg font-semibold text-slate-900">Stop receiving messages from {{ $company }}?</h1>
                <p class="text-sm text-slate-600">We will stop sending marketing messages to <strong>{{ $masked }}</strong> on {{ $channel->label() }}. Messages about something you asked for, such as an order, are not affected.</p>
                <form method="POST" action="{{ $url }}">
                    @csrf
                    <button type="submit" class="btn-primary w-full">Yes, unsubscribe me</button>
                </form>
                <p class="text-xs text-slate-500">If you did not mean to open this page, just close it: nothing has changed.</p>
            @endif
        </div>
    </main>
</body>
</html>

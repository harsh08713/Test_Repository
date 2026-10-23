<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-navy-900 font-sans text-slate-800 antialiased">
    <div class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-md bg-brand-600 text-lg font-bold text-white">F</div>
                <h1 class="text-2xl font-bold text-white">{{ config('app.name') }}</h1>
                <p class="mt-1 text-sm text-slate-400">Masters &amp; CRM-Marketing</p>
            </div>
            <div class="rounded-md border-t-4 border-brand-600 bg-white p-8 shadow-xl">
                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>

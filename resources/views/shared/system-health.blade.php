@php
    $badge = ['ok' => ['OK', 'bg-emerald-100 text-emerald-800'], 'warn' => ['Check', 'bg-amber-100 text-amber-800'], 'fail' => ['Problem', 'bg-rose-100 text-rose-800']];
    $worst = collect($checks)->contains(fn ($c) => $c['status'] === 'fail') ? 'fail' : (collect($checks)->contains(fn ($c) => $c['status'] === 'warn') ? 'warn' : 'ok');
@endphp
<div class="space-y-5" wire:poll.30s>
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">System health</h1>
        <p class="mt-1 text-sm text-slate-500">Is everything that must run on its own running? The scheduler and its jobs leave a note each time they run; the queue, e-mail, file storage, cache and database are looked at directly. Nothing here changes anything, and no secret is shown.</p>
    </div>

    <p @class(['rounded-lg border px-3 py-2 text-sm', 'border-emerald-200 bg-emerald-50 text-emerald-800' => $worst === 'ok', 'border-amber-200 bg-amber-50 text-amber-900' => $worst === 'warn', 'border-rose-200 bg-rose-50 text-rose-800' => $worst === 'fail']) role="status">
        {{ ['ok' => 'Everything that can be checked from here is in order.', 'warn' => 'Nothing is broken, but something needs a look.', 'fail' => 'Something is not running as it should.'][$worst] }}
    </p>

    <div class="card">
        <ul class="divide-y divide-slate-100">
            @foreach ($checks as $i => $check)
                <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3" wire:key="chk-{{ $i }}">
                    <div class="min-w-0 max-w-3xl"><p class="font-medium text-slate-900">{{ $check['name'] }}</p><p class="mt-0.5 text-sm text-slate-600">{{ $check['detail'] }}</p></div>
                    <span class="badge {{ $badge[$check['status']][1] }}">{{ $badge[$check['status']][0] }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <section class="card card-body text-sm text-slate-600" aria-labelledby="sec-setup">
        <h2 id="sec-setup" class="text-sm font-semibold text-slate-900">Setting it up</h2>
        <ul class="mt-1 list-disc space-y-1 pl-5">
            <li><strong>Scheduler</strong>: call <code class="rounded bg-slate-100 px-1">php artisan schedule:run</code> every minute — on Windows a Task Scheduler task, on a server <code class="rounded bg-slate-100 px-1">* * * * * php /path/artisan schedule:run</code>.</li>
            <li><strong>Queue</strong>: set <code class="rounded bg-slate-100 px-1">QUEUE_CONNECTION=database</code> (or redis) and keep <code class="rounded bg-slate-100 px-1">php artisan queue:work --tries=3</code> running under a supervisor.</li>
            <li><strong>Cache</strong>: <code class="rounded bg-slate-100 px-1">CACHE_STORE=database</code> or redis, so the scheduler, the queue worker and the web server share locks and heartbeats.</li>
            <li><strong>E-mail</strong>: set <code class="rounded bg-slate-100 px-1">MAIL_MAILER</code> and its host, port and credentials in <code class="rounded bg-slate-100 px-1">.env</code>.</li>
        </ul>
    </section>
</div>

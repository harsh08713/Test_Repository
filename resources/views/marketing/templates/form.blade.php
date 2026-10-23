@php use App\Modules\Shared\Support\Format; @endphp
<div class="mx-auto max-w-6xl space-y-5">
    <div>
        <p class="text-sm"><a href="{{ route('marketing.templates.index') }}" wire:navigate class="text-brand-700 hover:underline">Message templates</a></p>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $template ? $template->name : 'New template' }}</h1>
        @if ($template)
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm"><span class="badge {{ $template->approval_status->badge() }}">{{ $template->approval_status->label() }}</span>
                <span class="text-slate-500">Changed {{ Format::dateTime($template->updated_at) }}</span></p>
        @endif
    </div>

    <x-status />
    @error('form')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

    @if ($locked)
        <p class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900" role="note">This template {{ $template->isApproved() ? 'is approved' : 'is waiting for approval' }}, so its words are fixed — what the approver read is what goes out.
            @if ($canReopen) To change it, <button type="button" wire:click="openReopen" class="font-medium underline">re-open it for editing</button> (it will need approving again).@endif</p>
    @endif

    <div class="grid gap-5 lg:grid-cols-5">
        <form wire:submit="save" class="card lg:col-span-3" novalidate>
            <div class="card-body space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-3">
                        <label for="t-name" class="label">Name</label>
                        <input id="t-name" type="text" wire:model.live.debounce.400ms="t.name" class="input" maxlength="150" @disabled($locked)>
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="t-channel" class="label">Channel</label>
                        <select id="t-channel" wire:model.live="t.channel" class="input" @disabled($locked || $template)>@foreach ($channels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                        @error('channel') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="t-purpose" class="label">What kind of message</label>
                        <select id="t-purpose" wire:model.live="t.purpose" class="input" @disabled($locked)>
                            <option value="marketing">Marketing — needs the person’s consent</option>
                            <option value="transactional">About something they asked for (order, reminder) — only needs that they have not opted out</option>
                        </select>
                        @error('purpose') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($channel->value === 'email')
                    <div>
                        <label for="t-subject" class="label">Subject</label>
                        <input id="t-subject" type="text" wire:model.live.debounce.400ms="t.subject" class="input" maxlength="200" @disabled($locked)>
                        @error('subject') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="t-body" class="label">Message</label>
                    <textarea id="t-body" rows="10" wire:model.live.debounce.400ms="t.body" class="input font-mono text-sm" @disabled($locked)></textarea>
                    @error('body') <p class="error">{{ $message }}</p> @enderror
                </div>
                @if ($channel->value === 'whatsapp')
                    <div>
                        <label for="t-pid" class="label">Provider’s template id <span class="font-normal text-slate-400">(when a WhatsApp provider is connected)</span></label>
                        <input id="t-pid" type="text" wire:model="t.provider_template_id" class="input" maxlength="100" @disabled($locked)>
                    </div>
                @endif
            </div>
            @unless ($locked)
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <a href="{{ route('marketing.templates.index') }}" wire:navigate class="btn-secondary">Cancel</a>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            @endunless
        </form>

        <div class="space-y-5 lg:col-span-2">
            <section class="card" aria-labelledby="sec-preview">
                <div class="card-header"><h2 id="sec-preview" class="text-sm font-semibold text-slate-900">Preview <span class="font-normal text-slate-500">(made-up details)</span></h2></div>
                <div class="card-body space-y-2 text-sm">
                    @if ($preview['subject'] !== null)<p class="font-medium text-slate-900">{{ $preview['subject'] ?: '(no subject yet)' }}</p>@endif
                    <p class="whitespace-pre-line text-slate-700">{{ $preview['body'] ?: '(nothing written yet)' }}</p>
                    @if ($problems !== [])
                        <ul class="mt-2 space-y-1 text-xs text-rose-700" role="alert">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                    @else
                        <p class="mt-2 text-xs text-emerald-700">Nothing to fix.</p>
                    @endif
                </div>
            </section>
            <section class="card" aria-labelledby="sec-vars">
                <div class="card-header"><h2 id="sec-vars" class="text-sm font-semibold text-slate-900">Variables you can use</h2></div>
                <dl class="card-body space-y-1 text-xs">
                    @foreach ($variables as $name => $what)<div class="flex gap-2"><dt class="font-mono text-slate-700">&#123;&#123;{{ $name }}&#125;&#125;</dt><dd class="text-slate-500">{{ $what }}</dd></div>@endforeach
                </dl>
            </section>
        </div>
    </div>

    @if ($template)
        <livewire:shared.approval-panel type="message_template" :id="$template->id" :key="'ap-'.$template->id" />
        <livewire:shared.comments type="message_template" :id="$template->id" :key="'cm-'.$template->id" />
    @endif

    @if ($reopening)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="reopen-title">
            <form wire:submit="reopen" class="card w-full max-w-lg" novalidate>
                <div class="card-header"><h2 id="reopen-title" class="text-sm font-semibold text-slate-900">Re-open the template for editing</h2>
                    <button type="button" wire:click="closeReopen" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button></div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">It goes back to a draft and cannot be sent until it is approved again. Campaigns that use it keep their own approval, but cannot go for approval while it is a draft.</p>
                    <div><label for="r-reason" class="label">Why?</label><textarea id="r-reason" rows="2" wire:model="reason" class="input" maxlength="500"></textarea>@error('reason') <p class="error">{{ $message }}</p> @enderror</div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="closeReopen" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">Re-open</button></div>
            </form>
        </div>
    @endif
</div>

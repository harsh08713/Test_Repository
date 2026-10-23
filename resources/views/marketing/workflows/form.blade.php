@php
    $listFor = fn (string $field) => match ($field) { 'stage' => $stages, 'source' => $sources, 'branch' => $branches, 'owner' => $people, default => [] };
@endphp
<div class="mx-auto max-w-4xl space-y-5">
    <div>
        <p class="text-sm"><a href="{{ route('marketing.workflows.index') }}" wire:navigate class="text-brand-700 hover:underline">Workflows</a></p>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Change the workflow' : 'New workflow' }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-500">Saving writes a new version; the old ones are kept. The workflow runs as you: it can do only what you could do by hand, and it stops if you lose the right or leave.</p>
    </div>

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-when">
            <div class="card-header"><h2 id="sec-when" class="text-sm font-semibold text-slate-900">1. When</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3"><label for="w-name" class="label">Name</label><input id="w-name" type="text" wire:model="w.name" class="input" maxlength="150">@error('name') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-3"><label for="w-trigger" class="label">Starts when</label>
                    <select id="w-trigger" wire:model.live="w.trigger" class="input" @disabled($editing)>@foreach ($triggers as $key => $t)<option value="{{ $key }}">{{ $t['label'] }}</option>@endforeach</select>
                    @if ($editing)<p class="help">A different trigger is a different workflow.</p>@endif @error('trigger') <p class="error">{{ $message }}</p> @enderror</div>
                @foreach ($trigger['params'] as $key => $rule)
                    <div class="sm:col-span-2" wire:key="param-{{ $key }}"><label for="p-{{ $key }}" class="label">{{ $rule['label'] }}</label><input id="p-{{ $key }}" type="number" min="{{ $rule['min'] }}" max="{{ $rule['max'] }}" wire:model="params.{{ $key }}" placeholder="{{ $rule['default'] }}" class="input">@error("params.$key") <p class="error">{{ $message }}</p> @enderror</div>
                @endforeach
                <div class="sm:col-span-6"><label for="w-desc" class="label">What it is for <span class="font-normal text-slate-400">(optional)</span></label><textarea id="w-desc" rows="2" wire:model="w.description" class="input" maxlength="2000"></textarea>@error('description') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-if">
            <div class="card-header"><h2 id="sec-if" class="text-sm font-semibold text-slate-900">2. Only if <span class="font-normal text-slate-500">(all of these; none = always)</span></h2></div>
            <div class="card-body space-y-3">
                @error('conditions') <p class="error">{{ $message }}</p> @enderror
                @foreach ($conditions as $i => $c)
                    <div class="grid items-start gap-3 sm:grid-cols-6" wire:key="cond-{{ $i }}">
                        <div class="sm:col-span-2"><label for="c-f-{{ $i }}" class="sr-only">Check</label><select id="c-f-{{ $i }}" wire:model.live="conditions.{{ $i }}.field" class="input">@foreach ($fields as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                        @if ($c['field'] === 'value')
                            <div class="sm:col-span-1"><select wire:model="conditions.{{ $i }}.op" class="input" aria-label="At least or at most"><option value="gte">at least</option><option value="lte">at most</option></select></div>
                            <div class="sm:col-span-2"><input type="text" inputmode="decimal" wire:model="conditions.{{ $i }}.value" class="input" placeholder="Amount in ₹" aria-label="Amount"></div>
                        @else
                            <div class="sm:col-span-3"><select multiple wire:model="conditions.{{ $i }}.value" class="input h-28" aria-label="Is one of">@foreach ($listFor($c['field']) as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select><p class="help">Hold Ctrl (or Cmd) to pick more than one.</p></div>
                        @endif
                        <div class="text-right sm:col-span-1"><button type="button" wire:click="removeCondition({{ $i }})" class="text-xs text-rose-700 hover:underline">Remove</button></div>
                        @error("conditions.$i") <p class="error sm:col-span-6">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <button type="button" wire:click="addCondition" class="btn-secondary">Add a condition</button>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-do">
            <div class="card-header"><h2 id="sec-do" class="text-sm font-semibold text-slate-900">3. Then <span class="font-normal text-slate-500">(in this order)</span></h2></div>
            <div class="card-body space-y-4">
                @error('actions') <p class="error">{{ $message }}</p> @enderror
                @foreach ($actions as $i => $a)
                    <div class="space-y-3 rounded-lg border border-slate-200 p-4" wire:key="act-{{ $i }}">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div class="min-w-56"><label for="a-t-{{ $i }}" class="label">Action {{ $i + 1 }}</label><select id="a-t-{{ $i }}" wire:model.live="actions.{{ $i }}.type" class="input">@foreach ($actionTypes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                            <button type="button" wire:click="removeAction({{ $i }})" class="text-xs text-rose-700 hover:underline">Remove</button>
                        </div>
                        @if (in_array($a['type'], ['create_task', 'schedule_reminder'], true))
                            <div class="grid gap-3 sm:grid-cols-6">
                                <div class="sm:col-span-6"><label for="a-ti-{{ $i }}" class="label">Task <span class="font-normal text-slate-400">(you can use &#123;&#123;name&#125;&#125; and &#123;&#123;reference&#125;&#125;)</span></label><input id="a-ti-{{ $i }}" type="text" wire:model="actions.{{ $i }}.title" class="input" maxlength="200"></div>
                                <div class="sm:col-span-2"><label for="a-as-{{ $i }}" class="label">Give it to</label><select id="a-as-{{ $i }}" wire:model="actions.{{ $i }}.assignee" class="input"><option value="owner">The record’s owner</option><option value="manager">The owner’s manager</option>@foreach ($people as $id => $name)<option value="user:{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                                <div class="sm:col-span-2"><label for="a-h-{{ $i }}" class="label">{{ $a['type'] === 'create_task' ? 'Due in (hours)' : 'Remind in (hours)' }}</label><input id="a-h-{{ $i }}" type="number" min="1" max="8760" wire:model="actions.{{ $i }}.{{ $a['type'] === 'create_task' ? 'due_hours' : 'hours' }}" class="input"></div>
                                <div class="sm:col-span-2"><label for="a-pr-{{ $i }}" class="label">Priority</label><select id="a-pr-{{ $i }}" wire:model="actions.{{ $i }}.priority" class="input">@foreach ($priorities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                            </div>
                        @elseif ($a['type'] === 'notify_manager')
                            <div><label for="a-m-{{ $i }}" class="label">Message</label><input id="a-m-{{ $i }}" type="text" wire:model="actions.{{ $i }}.message" class="input" maxlength="300"></div>
                        @elseif ($a['type'] === 'assign_lead')
                            <div class="grid gap-3 sm:grid-cols-6">
                                <div class="sm:col-span-3"><label for="a-u-{{ $i }}" class="label">Hand the lead to</label><select id="a-u-{{ $i }}" wire:model="actions.{{ $i }}.user_id" class="input"><option value="">Choose…</option>@foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                                <div class="sm:col-span-3"><label for="a-r-{{ $i }}" class="label">Reason on record</label><input id="a-r-{{ $i }}" type="text" wire:model="actions.{{ $i }}.reason" class="input" maxlength="200" placeholder="Assigned by a workflow"></div>
                            </div>
                        @else
                            <div><label for="a-tp-{{ $i }}" class="label">Template</label><select id="a-tp-{{ $i }}" wire:model="actions.{{ $i }}.template_id" class="input"><option value="">Choose…</option>@foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }} · {{ $t->channel->label() }}{{ $t->isApproved() && $t->status === 'active' ? '' : ' (not approved yet)' }}</option>@endforeach</select>
                                <p class="help">Sent only when the template is approved and on, and the person’s consent allows it. Otherwise the message is kept as not sent, with the reason.</p></div>
                        @endif
                        @error("actions.$i") <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <button type="button" wire:click="addAction" class="btn-secondary">Add an action</button>
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('marketing.workflows.show', $workflowId) : route('marketing.workflows.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary">Save</button>
        </div>
    </form>
</div>

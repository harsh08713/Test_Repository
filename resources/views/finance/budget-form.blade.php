<div class="mx-auto max-w-2xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('finance.budgets.index') }}" wire:navigate class="hover:text-brand-700">Budgets</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} budget</h1>
        <p class="mt-1 text-sm text-slate-500">One budget for each cost centre, month and loss category. Leave the category empty to budget the cost centre’s losses of every category together.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="bf-cc" class="label">Cost centre</label>
                <select id="bf-cc" wire:model="costCenterId" class="input"><option value="">Select…</option>@foreach ($costCenters as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @error('cost_center_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2">
                <label for="bf-cat" class="label">Loss category (optional)</label>
                <select id="bf-cat" wire:model="categoryId" class="input"><option value="">All categories</option>@foreach ($categories as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @error('loss_category_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="bf-month" class="label">Month</label>
                <input id="bf-month" type="month" wire:model="month" class="input">
                @error('month')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="bf-amount" class="label">Budget (₹)</label>
                <input id="bf-amount" type="text" inputmode="decimal" wire:model="amount" class="input tabular-nums" autocomplete="off">
                @error('amount')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2">
                <label for="bf-rem" class="label">Remarks</label>
                <textarea id="bf-rem" rows="2" wire:model="remarks" class="input" maxlength="1000"></textarea>
                @error('remarks')<p class="error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
            <a href="{{ route('finance.budgets.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save as draft</button>
        </div>
    </form>
</div>

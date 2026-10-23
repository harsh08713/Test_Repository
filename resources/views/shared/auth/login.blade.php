<div>
    <h2 class="text-lg font-semibold text-slate-900">Sign in</h2>
    <p class="mt-1 text-sm text-slate-500">Use your work account to continue.</p>

    <form wire:submit="login" class="mt-6 space-y-4" novalidate>
        <div>
            <label for="email" class="label">Email</label>
            <input id="email" type="email" wire:model="email" class="input" autocomplete="username" autofocus required>
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="label">Password</label>
            <input id="password" type="password" wire:model="password" class="input" autocomplete="current-password" required>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" wire:model="remember" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            Keep me signed in on this device
        </label>

        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>

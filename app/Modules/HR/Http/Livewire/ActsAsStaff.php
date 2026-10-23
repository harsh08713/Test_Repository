<?php

namespace App\Modules\HR\Http\Livewire;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeAccess;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** What the H2 screens share: who is signed in, which employee file is theirs, and running a service call so that its refusal shows in the form. */
trait ActsAsStaff
{
    protected function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function ownEmployee(): ?Employee
    {
        return app(EmployeeAccess::class)->own($this->actor());
    }

    /** Run an action; show a validation refusal in the form, a permission refusal as a notice; say `$done` when it worked. Returns whether it worked. */
    protected function attempt(Closure $action, string $done): bool
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return false;
        } catch (AuthorizationException $e) {
            $this->addError('action', $e->getMessage());

            return false;
        }

        session()->flash('status', $done);

        return true;
    }
}

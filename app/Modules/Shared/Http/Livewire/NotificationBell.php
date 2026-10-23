<?php

namespace App\Modules\Shared\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** Header bell: unread in-app notifications (approval requests, decisions, ...). */
class NotificationBell extends Component
{
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /** @return Collection<int, DatabaseNotification> */
    #[Computed]
    public function latest(): Collection
    {
        return Auth::user()->notifications()->limit(8)->get();
    }

    public function openNotification(string $id): void
    {
        $notification = Auth::user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        // Only follow relative links so a crafted notification payload cannot redirect off-site.
        if (is_string($url) && str_starts_with($url, '/')) {
            $this->redirect($url, navigate: false);

            return;
        }

        unset($this->unreadCount, $this->latest);
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
        unset($this->unreadCount, $this->latest);
    }

    public function render(): View
    {
        return view('shared.notification-bell');
    }
}

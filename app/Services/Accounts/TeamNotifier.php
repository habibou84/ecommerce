<?php

namespace App\Services\Accounts;

use App\Enums\UserStatus;
use App\Models\Store;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Prévient les membres actifs d'une boutique qui ont une permission donnée (sauf l'auteur de l'action).
 */
class TeamNotifier
{
    public function __construct(private readonly StoreRoles $roles) {}

    public function notify(Store $store, string $permission, Notification $notification, ?User $except = null): void
    {
        $recipients = User::query()
            ->whereHas('memberships', fn ($q) => $q->where('store_id', $store->id)->where('status', UserStatus::Active->value))
            ->where('status', UserStatus::Active->value)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->get()
            ->filter(fn (User $user) => in_array($permission, $this->roles->permissionsOf($user, $store), true));

        if ($recipients->isNotEmpty()) {
            Notifier::send($recipients, $notification);
        }
    }
}

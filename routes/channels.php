<?php

use App\Models\AdminUser;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('admin-users.{adminUserId}', function (AdminUser $user, int $adminUserId): bool {
    return $user->id === $adminUserId;
});

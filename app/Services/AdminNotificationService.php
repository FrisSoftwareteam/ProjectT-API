<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Notifications\InternalAdminNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AdminNotificationService
{
    public function sendToRoles(
        array $roles,
        string $event,
        string $title,
        string $message,
        string $entityType,
        int|string $entityId,
        string $reference,
        string $actionUrl,
        ?int $actorId = null,
        array $additionalUserIds = [],
        bool $includeActor = false
    ): void {
        $this->sendSafely(function () use ($roles, $additionalUserIds) {
            $roleUsers = empty($roles)
                ? collect()
                : AdminUser::query()->where('is_active', true)->role($roles)->get();

            $additionalUsers = AdminUser::query()
                ->where('is_active', true)
                ->whereIn('id', array_filter($additionalUserIds))
                ->get();

            return $roleUsers->concat($additionalUsers)->unique('id')->values();
        }, compact(
            'event',
            'title',
            'message',
            'entityType',
            'entityId',
            'reference',
            'actionUrl'
        ), $actorId, $includeActor);
    }

    private function sendSafely(
        callable $resolveRecipients,
        array $details,
        ?int $actorId,
        bool $includeActor
    ): void {
        try {
            /** @var Collection<int, AdminUser> $recipients */
            $recipients = $resolveRecipients();
            if (! $includeActor && $actorId) {
                $recipients = $recipients->reject(fn (AdminUser $user) => $user->id === $actorId);
            }

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients->values(), new InternalAdminNotification([
                'event' => $details['event'],
                'title' => $details['title'],
                'message' => $details['message'],
                'entity_type' => $details['entityType'],
                'entity_id' => $details['entityId'],
                'reference' => $details['reference'],
                'action_url' => $details['actionUrl'],
            ]));
        } catch (\Throwable $exception) {
            Log::error('Unable to dispatch internal admin notification', [
                'event' => $details['event'],
                'entity_type' => $details['entityType'],
                'entity_id' => $details['entityId'],
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

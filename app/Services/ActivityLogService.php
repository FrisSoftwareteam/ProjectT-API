<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Log;

class ActivityLogService
{
    public const REQUEST_LOGGED_ATTRIBUTE = 'activity_log_written';

    public function log(?int $userId, string $action, array $metadata = []): ?UserActivityLog
    {
        if (! $userId) {
            return null;
        }

        try {
            $actor = AdminUser::find($userId);
            if ($actor) {
                $metadata['actor'] = [
                    'id' => $actor->id,
                    'name' => trim("{$actor->first_name} {$actor->last_name}"),
                    'email' => $actor->email,
                    'roles' => $actor->roles->pluck('name')->values()->all(),
                ];
            }
            $log = UserActivityLog::create([
                'user_id' => $userId,
                'action' => $action,
                'metadata' => $metadata,
            ]);

            if (app()->bound('request')) {
                request()->attributes->set(self::REQUEST_LOGGED_ATTRIBUTE, $log->id);
            }

            return $log;
        } catch (\Throwable $e) {
            Log::warning('Unable to write activity log', [
                'action' => $action,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

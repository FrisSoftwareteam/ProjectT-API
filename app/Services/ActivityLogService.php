<?php

namespace App\Services;

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
            $log = UserActivityLog::create([
                'user_id' => $userId,
                'action' => $action,
                'metadata' => $metadata,
            ]);

            if (app()->bound('request')) {
                request()->attributes->set(self::REQUEST_LOGGED_ATTRIBUTE, true);
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

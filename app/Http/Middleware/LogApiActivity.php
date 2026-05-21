<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogApiActivity
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldLog($request, $response)) {
            return $response;
        }

        $this->activityLogService->log(
            $request->user()?->id,
            $this->actionFor($request),
            $this->metadataFor($request, $response)
        );

        return $response;
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if (! in_array($request->method(), self::MUTATING_METHODS, true)) {
            return false;
        }

        if (! $request->user()) {
            return false;
        }

        if ($request->attributes->get(ActivityLogService::REQUEST_LOGGED_ATTRIBUTE)) {
            return false;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            return false;
        }

        $path = trim($request->path(), '/');

        return ! Str::startsWith($path, [
            'api/user-activity-logs',
            'user-activity-logs',
        ]);
    }

    private function actionFor(Request $request): string
    {
        $routeUri = $request->route()?->uri() ?? $request->path();
        $segments = collect(explode('/', trim($routeUri, '/')))
            ->reject(fn (string $segment) => $segment === '' || Str::startsWith($segment, '{'))
            ->values();

        $resource = $segments->take(2)
            ->map(fn (string $segment) => Str::snake(str_replace('-', '_', $segment)))
            ->implode('_');

        if ($resource === '') {
            $resource = 'api_request';
        }

        $verb = match ($request->method()) {
            'POST' => 'created',
            'PUT', 'PATCH' => 'updated',
            'DELETE' => 'deleted',
            default => 'changed',
        };

        return "{$resource}_{$verb}";
    }

    private function metadataFor(Request $request, Response $response): array
    {
        return [
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => $request->route()?->uri(),
            'status' => $response->getStatusCode(),
            'route_parameters' => $this->safeRouteParameters($request),
            'ip' => $request->ip(),
        ];
    }

    private function safeRouteParameters(Request $request): array
    {
        return collect($request->route()?->parameters() ?? [])
            ->map(function ($value) {
                if (is_object($value) && method_exists($value, 'getKey')) {
                    return $value->getKey();
                }

                if (is_scalar($value) || $value === null) {
                    return $value;
                }

                return null;
            })
            ->all();
    }
}

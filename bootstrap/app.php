<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*', headers: SymfonyRequest::HEADER_X_FORWARDED_FOR
            | SymfonyRequest::HEADER_X_FORWARDED_HOST
            | SymfonyRequest::HEADER_X_FORWARDED_PORT
            | SymfonyRequest::HEADER_X_FORWARDED_PROTO
            | SymfonyRequest::HEADER_X_FORWARDED_PREFIX
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'activity.log' => LogApiActivity::class,
        ]);

        // Force JSON responses for all API routes
        $middleware->group('api', [
            ForceJsonResponse::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($response, $exception, $request) {
            if (! $request->is('api/cscs', 'api/cscs/*') || ! $response instanceof JsonResponse) {
                return $response;
            }
            $payload = $response->getData(true);
            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode() : $response->getStatusCode();
            $details = $exception instanceof ValidationException ? $exception->errors() : [];
            $code = match (true) {
                isset($details['pre_posting_checks']) => 'PRE_POSTING_CHECK_FAILED',
                isset($details['snapshot_hash']) => 'SNAPSHOT_HASH_MISMATCH',
                isset($details['status']) => 'BATCH_STATE_CONFLICT',
                $status === 401 => 'UNAUTHORIZED',
                $status === 403 && str_contains($exception->getMessage(), 'Maker-checker') => 'SELF_APPROVAL_FORBIDDEN',
                $status === 403 => 'FORBIDDEN',
                $status === 404 => 'RESOURCE_NOT_FOUND',
                $status === 422 => 'VALIDATION_ERROR',
                $status === 429 => 'RATE_LIMITED',
                default => 'INTERNAL_ERROR',
            };
            // Preserve existing top-level message/errors and add a machine-readable envelope.
            $payload['error'] = ['code' => $code, 'message' => $payload['message'] ?? 'Request failed.', 'details' => $details];
            $response->setData($payload)->setStatusCode($status);

            return $response;
        });
        // Handle unauthorized access for API routes
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // Handle forbidden access (lack of permissions)
        $exceptions->render(function (AuthorizationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden. You do not have permission to access this resource.',
                    'required_permission' => $e->getMessage(),
                ], 403);
            }
        });

        // Handle Spatie permission exceptions
        $exceptions->render(function (UnauthorizedException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden. You do not have the required role or permission.',
                    'error' => $e->getMessage(),
                ], 403);
            }
        });

        // Handle model not found
        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        // Handle validation exceptions (already handled by Laravel, but ensure JSON)
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Handle general exceptions for API
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*') && ! config('app.debug')) {
                return response()->json([
                    'success' => false,
                    'message' => 'An error occurred processing your request.',
                ], 500);
            }
        });
    })->create();

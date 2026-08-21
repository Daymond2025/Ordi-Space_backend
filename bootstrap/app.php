<?php

use App\Http\Middleware\LogApiActivity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            LogApiActivity::class,
        ]);

        $middleware->throttleApi();

        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Enveloppe JSON unique pour toutes les erreurs API — cf. étude
        // d'architecture technique, section "Gestion des erreurs".
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // laisse Laravel gérer les pages web classiques.
            }

            [$status, $code, $message] = match (true) {
                $e instanceof ValidationException => [
                    422, 'VALIDATION_ERROR', 'Les données envoyées ne sont pas valides.',
                ],
                $e instanceof AuthenticationException => [
                    401, 'UNAUTHENTICATED', 'Authentification requise.',
                ],
                $e instanceof AuthorizationException => [
                    403, 'FORBIDDEN', "Vous n'avez pas la permission d'effectuer cette action.",
                ],
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [
                    404, 'NOT_FOUND', 'Ressource introuvable.',
                ],
                $e instanceof TooManyRequestsHttpException => [
                    429, 'TOO_MANY_REQUESTS', 'Trop de tentatives, réessayez plus tard.',
                ],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(), 'HTTP_ERROR', $e->getMessage() ?: 'Erreur de requête.',
                ],
                default => [500, 'SERVER_ERROR', 'Une erreur interne est survenue.'],
            };

            $payload = [
                'success' => false,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ];

            if ($e instanceof ValidationException) {
                $payload['error']['fields'] = $e->errors();
            }

            // Jamais de stack trace exposée hors environnement local.
            if (config('app.debug') && $status === 500) {
                $payload['error']['debug'] = [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ];
            }

            return response()->json($payload, $status);
        });
    })->create();

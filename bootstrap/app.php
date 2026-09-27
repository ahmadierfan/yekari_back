<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureApp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** قالب خطای یکسان همهٔ اپ‌ها — همان قالب ResponseTrait پروژهٔ مرجع */
$error = fn (string $message, int $code, $issues = null) => response()->json(array_filter([
    'status' => 'error', 'message' => $message, 'code' => $code, 'issues' => $issues ?: null,
], fn ($v) => $v !== null), $code);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'app' => EnsureApp::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
        $middleware->throttleApi('api');
        // API صفحهٔ ورود ندارد؛ بدون این، مهمانِ بدون هدر Accept به‌جای ۴۰۱ خطای ۵۰۰ (route login) می‌گیرد
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(fn (ApiException $e) => $error($e->getMessage(), $e->status, $e->issues));
        $exceptions->render(fn (ValidationException $e) => $error('اطلاعات ورودی نامعتبر است', 422, $e->errors()));
        $exceptions->render(fn (AuthenticationException $e) => $error('ابتدا وارد شوید', 401));
        $exceptions->render(fn (AccessDeniedHttpException|AuthorizationException $e) => $error('دسترسی ندارید', 403));
        $exceptions->render(fn (NotFoundHttpException|ModelNotFoundException $e, Request $r) => $r->is('api/*') ? $error('پیدا نشد', 404) : null);
        $exceptions->render(fn (ThrottleRequestsException $e) => $error('تعداد درخواست‌ها زیاد است؛ کمی صبر کن', 429));
        $exceptions->render(fn (UnauthorizedException $e) => $error('دسترسی ندارید', 403));
    })->create();

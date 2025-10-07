<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            if ($e instanceof AuthenticationException) {
                return response()->json(['message' => 'يرجى تسجيل الدخول'], 401);
            }
            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'خطأ في التحقق من البيانات',
                    'errors' => $e->errors(),
                ], 422);
            }
            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'حدث خطأ في الطلب',
                ], $e->getStatusCode());
            }
            return response()->json([
                'message' => 'حدث خطأ غير متوقع',
                'error' => $e->getMessage(),
            ], 500);
        }
        return parent::render($request, $e);
    }
    
}

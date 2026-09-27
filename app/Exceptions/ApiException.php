<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * خطای قاعدهٔ کسب‌وکار («موجودی کافی نیست»، «مدارک تأیید نشده»…).
 * با همان قالب پاسخ پروژهٔ مرجع رندر می‌شود: {status, message, code, issues}.
 */
class ApiException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422, public array $issues = [])
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message = 'دسترسی ندارید'): self
    {
        return new self($message, 403);
    }
}

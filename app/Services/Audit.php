<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * لاگ فعالیت پنل. قاعدهٔ `stores/admin.ts` در سرور هم برقرار است: هر اکشن مدیریتی
 * که چیزی را عوض می‌کند، خودش (در سرویس/کنترلر) لاگ می‌نویسد.
 */
class Audit
{
    public static function log(string $kind, string $what, ?Model $subject = null, array $meta = []): ActivityLog
    {
        return ActivityLog::create([
            'actor_id' => auth()->id(),
            'kind' => $kind,
            'what' => $what,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}

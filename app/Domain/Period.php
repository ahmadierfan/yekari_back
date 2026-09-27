<?php

namespace App\Domain;

use Carbon\CarbonInterface as Carbon;
use Morilog\Jalali\Jalalian;

/** «ماه» در یکاری ماه شمسی است (سقف اعتبار، فاکتور سازمانی)، نه ماه میلادی. */
final class Period
{
    /** @return array{0: Carbon, 1: Carbon} ابتدا و انتهای ماه شمسیِ شامل $at */
    public static function jalaliMonth(?Carbon $at = null): array
    {
        $j = Jalalian::fromCarbon(($at ?? \Illuminate\Support\Carbon::now())->copy());
        $start = (new Jalalian($j->getYear(), $j->getMonth(), 1))->toCarbon()->startOfDay();
        $end = (new Jalalian($j->getYear(), $j->getMonth(), $j->getMonthDays()))->toCarbon()->endOfDay();

        return [$start, $end];
    }

    public static function label(Carbon $at): string
    {
        return Jalalian::fromCarbon($at)->format('%B %Y');
    }
}

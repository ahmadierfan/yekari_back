<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /** بازگشت از درگاه (GET یا POST، بسته به درگاه) → تأیید → هدایت به اپ */
    public function callback(Request $r, PaymentService $payments)
    {
        return redirect()->away($payments->callback($r->all()));
    }
}

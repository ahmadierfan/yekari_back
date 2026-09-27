<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function app(Request $r): string
    {
        foreach (array_keys(config('yekari.apps')) as $app) {
            if ($r->user()->tokenCan("app:$app")) {
                return $app;
            }
        }

        return 'customer';
    }

    private function mine(Request $r)
    {
        return AppNotification::where('user_id', $r->user()->id)->where('app', $this->app($r));
    }

    public function index(Request $r)
    {
        return NotificationResource::collection($this->mine($r)->latest('id')->limit(100)->get())
            ->additional(['unread' => $this->mine($r)->whereNull('read_at')->count()]);
    }

    public function read(Request $r, int $id)
    {
        $this->mine($r)->whereKey($id)->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function readAll(Request $r)
    {
        $this->mine($r)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function destroy(Request $r, int $id)
    {
        $this->mine($r)->whereKey($id)->delete();

        return response()->noContent();
    }
}

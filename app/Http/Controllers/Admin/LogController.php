<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $r)
    {
        $q = ActivityLog::with('actor:id,name')->latest('id')
            ->when($r->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($r->integer('actorId'), fn ($q, $a) => $q->where('actor_id', $a))
            ->paginate(min(100, $r->integer('perPage', 50)));
        $q->getCollection()->transform(fn (ActivityLog $l) => [
            'id' => $l->id, 'actor' => $l->actor?->name ?? 'سیستم', 'kind' => $l->kind, 'what' => $l->what,
            'at' => $l->created_at->toIso8601String(), 'ip' => $l->ip,
        ]);

        return $q;
    }
}

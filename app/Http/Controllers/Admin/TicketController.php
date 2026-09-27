<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\Audit;
use App\Services\Notifier;
use Illuminate\Http\Request;

/** صف پشتیبانی کل سامانه */
class TicketController extends Controller
{
    public function index(Request $r)
    {
        return TicketResource::collection(Ticket::with(['user', 'order:id,code', 'assignee'])->latest('updated_at')
            ->when($r->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($r->query('topic'), fn ($q, $t) => $q->where('topic', $t))
            ->when($r->boolean('mine'), fn ($q) => $q->where('assigned_to', $r->user()->id))
            ->paginate(min(100, $r->integer('perPage', 25))));
    }

    public function show(Ticket $ticket)
    {
        return new TicketResource($ticket->load(['user', 'order:id,code', 'assignee', 'messages']));
    }

    public function reply(Request $r, Ticket $ticket)
    {
        $d = $r->validate(['text' => 'required|string|max:3000', 'status' => 'nullable|in:answered,resolved,closed']);
        $ticket->messages()->create(['sender_id' => $r->user()->id, 'from' => 'agent', 'text' => trim($d['text'])]);
        $ticket->update(['status' => $d['status'] ?? 'answered', 'assigned_to' => $ticket->assigned_to ?? $r->user()->id]);
        $to = $ticket->app === 'courier' ? null : "/app/support/{$ticket->id}";
        Notifier::send($ticket->user_id, $ticket->app, "پاسخ درخواست {$ticket->code}", mb_substr($d['text'], 0, 120), 'message-circle', 'info', $to);
        Audit::log('update', "درخواست پشتیبانی {$ticket->code} پاسخ داده شد", $ticket);

        return $this->show($ticket);
    }

    public function update(Request $r, Ticket $ticket)
    {
        $d = $r->validate(['status' => 'sometimes|in:open,answered,resolved,closed', 'assignedTo' => 'sometimes|nullable|exists:users,id']);
        $ticket->update(array_filter(['status' => $d['status'] ?? null, 'assigned_to' => $d['assignedTo'] ?? null], fn ($v) => $v !== null));
        Audit::log('update', "درخواست پشتیبانی {$ticket->code} به‌روز شد", $ticket, $d);

        return $this->show($ticket);
    }
}

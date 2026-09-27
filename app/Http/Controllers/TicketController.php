<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Http\Resources\TicketResource;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** درخواست پشتیبانی از دید کاربر (مشتری یا پیک) */
class TicketController extends Controller
{
    public function index(Request $r)
    {
        return TicketResource::collection(Ticket::where('user_id', $r->user()->id)->with(['order:id,code', 'messages'])->latest('updated_at')->get());
    }

    public function show(Request $r, Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        return new TicketResource($ticket->load(['order:id,code', 'messages']));
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'topic' => ['required', Rule::in(Domain::TICKET_TOPICS)],
            'subject' => 'required|string|min:6|max:2000',
            'orderCode' => 'nullable|string',
            'files' => 'array|max:5', 'files.*' => 'image|max:8192',
        ]);
        $order = null;
        if (! empty($d['orderCode'])) {
            $order = Order::where('code', $d['orderCode'])->where(fn ($q) => $q->where('customer_id', $r->user()->id)->orWhere('courier_id', $r->user()->id))->first()
                ?? throw new ApiException('مأموریت پیدا نشد', 422);
        }
        do {
            $code = 'SP-'.random_int(1000, 99999);
        } while (Ticket::where('code', $code)->exists());

        $ticket = Ticket::create([
            'code' => $code, 'user_id' => $r->user()->id, 'topic' => $d['topic'], 'subject' => mb_substr(trim($d['subject']), 0, 250),
            'order_id' => $order?->id, 'app' => $r->user()->tokenCan('app:courier') ? 'courier' : 'customer',
        ]);
        $ticket->messages()->create(['sender_id' => $r->user()->id, 'from' => 'me', 'text' => trim($d['subject']), 'attachments' => $this->store_files($r, $ticket)]);

        return new TicketResource($ticket->load(['order:id,code', 'messages']));
    }

    public function reply(Request $r, Ticket $ticket)
    {
        $this->authorize('reply', $ticket);
        if ($ticket->status === 'closed') {
            throw new ApiException('این درخواست بسته شده است');
        }
        $d = $r->validate(['text' => 'required|string|max:2000', 'files' => 'array|max:5', 'files.*' => 'image|max:8192']);
        $ticket->messages()->create(['sender_id' => $r->user()->id, 'from' => 'me', 'text' => trim($d['text']), 'attachments' => $this->store_files($r, $ticket)]);
        $ticket->update(['status' => 'open']);

        return new TicketResource($ticket->load(['order:id,code', 'messages']));
    }

    public function close(Request $r, Ticket $ticket)
    {
        abort_unless($ticket->user_id === $r->user()->id, 404);
        $ticket->update(['status' => 'closed']);

        return new TicketResource($ticket->load(['order:id,code', 'messages']));
    }

    private function store_files(Request $r, Ticket $t): ?array
    {
        if (! $r->hasFile('files')) {
            return null;
        }

        return array_map(fn ($f) => \Storage::disk('public')->url($f->store("tickets/{$t->id}", 'public')), $r->file('files'));
    }
}

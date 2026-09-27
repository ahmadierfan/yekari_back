<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\ChatMessageResource;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * گفت‌وگوی یک مأموریت — مشترک بین اپ مشتری و پیک. طرف پیام از خود کاربر تعیین
 * می‌شود، نه از ورودی (کسی نمی‌تواند از طرف دیگری پیام بفرستد).
 * تا سوکت بیاید، اپ‌ها با `?after=<id>` پیام‌های تازه را poll می‌کنند.
 */
class ChatController extends Controller
{
    public function index(Request $r, Order $order)
    {
        $this->authorize('view', $order);

        return ChatMessageResource::collection(
            $order->messages()->when($r->integer('after'), fn ($q, $after) => $q->where('id', '>', $after))->get()
        );
    }

    public function store(Request $r, Order $order)
    {
        $this->authorize('chat', $order);
        if (in_array($order->status, ['cancelled', 'expired'], true) || ($order->finished_at && $order->finished_at->lt(now()->subDay()))) {
            throw new ApiException('گفت‌وگوی این مأموریت بسته شده است');
        }
        $data = $r->validate([
            'kind' => 'required|in:text,voice,image',
            'text' => 'required_if:kind,text|nullable|string|max:2000',
            'file' => 'required_unless:kind,text|file|max:10240',
            'duration' => 'required_if:kind,voice|nullable|integer|min:1|max:600',
        ]);
        if ($data['kind'] === 'image') {
            $r->validate(['file' => 'image']);
        }
        if ($data['kind'] === 'voice') {
            $r->validate(['file' => 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,video/webm,application/octet-stream']);
        }
        $msg = $order->messages()->create([
            'sender_id' => $r->user()->id,
            'sender_role' => $order->customer_id === $r->user()->id ? 'customer' : 'courier',
            'kind' => $data['kind'],
            'text' => $data['kind'] === 'text' ? trim($data['text']) : null,
            'path' => $r->hasFile('file') ? $r->file('file')->store("chat/{$order->id}", 'public') : null,
            'duration' => $data['duration'] ?? null,
        ]);

        return new ChatMessageResource($msg);
    }
}

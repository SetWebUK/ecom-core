<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pine\Commerce\Commerce;

/**
 * One live-chat message. Roles: customer, assistant (meta.tool_calls when it looked something up), staff, notice
 * (a status line the customer sees, e.g. "Sam joined the chat") and tool (the assistant's hidden lookup results).
 */
class ChatMessage extends Model
{
    public const UPDATED_AT = null;

    /** Roles the customer sees in the widget. */
    public const VISIBLE = ['customer', 'assistant', 'staff', 'notice'];

    protected $guarded = ['id'];

    protected $casts = ['meta' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel(), 'user_id');
    }

    public function isVisible(): bool
    {
        return in_array($this->role, self::VISIBLE, true) && trim((string) $this->body) !== '';
    }

    /** Widget / admin poll payload. */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'body' => (string) $this->body,
            'author' => $this->role === 'staff' ? ($this->meta['author'] ?? null) : null,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}

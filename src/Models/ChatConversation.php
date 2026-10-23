<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Pine\Commerce\Commerce;

/**
 * A storefront live-chat conversation (feature "live_chat", Services\LiveChat). Status: ai (the assistant answers),
 * waiting (a person was asked for), human (staff took over – the assistant stays quiet), closed.
 */
class ChatConversation extends Model
{
    public const STATUSES = ['ai' => 'Assistant', 'waiting' => 'Waiting for staff', 'human' => 'With staff', 'closed' => 'Closed'];

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected $casts = [
        'handoff_at' => 'datetime',
        'staff_notified_at' => 'datetime',
        'email_requested_at' => 'datetime',
        'last_message_at' => 'datetime',
        'staff_read_at' => 'datetime',
        'ai_replies' => 'integer',
    ];

    public static function start(array $attributes = []): self
    {
        return static::create($attributes + ['token' => Str::random(48), 'status' => 'ai', 'last_message_at' => now()]);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel(), 'user_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel(), 'staff_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /** Name for staff screens: the customer's name, email, account name, or "Visitor #id". */
    public function displayName(): string
    {
        return $this->name ?: ($this->email ?: ($this->user?->name ?: 'Visitor #'.$this->id));
    }

    public function hasUnread(): bool
    {
        return $this->last_message_at && (! $this->staff_read_at || $this->staff_read_at->lt($this->last_message_at));
    }
}

<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pine\Commerce\Commerce;

/**
 * One row of the Admin › Updates audit log (table platform_updates, since 1.3):
 *
 *  - type "check":    an update check (admin "Check now", `commerce:update:check`, the daily scheduled task);
 *                     result = the check (versions, changelog, skeleton).
 *  - type "core":     an approved pine/commerce update; approved → running → succeeded | failed. The log holds every
 *                     step's output; meta the backup file, the maintenance bypass secret (while it runs) and the pid.
 *  - type "skeleton": a skeleton comparison (planned) and the files applied from it (applied).
 *
 * @property array|null $result
 * @property array|null $files
 * @property array|null $meta
 */
class PlatformUpdate extends Model
{
    protected $table = 'platform_updates';

    protected $guarded = ['id'];

    protected $casts = [
        'result' => 'array',
        'files' => 'array',
        'meta' => 'array',
        'approved_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const TYPE_CHECK = 'check';

    public const TYPE_CORE = 'core';

    public const TYPE_SKELETON = 'skeleton';

    /** Core update states that still need the runner. */
    public const OPEN = ['approved', 'running'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel(), 'user_id');
    }

    /** The newest update check (or null). */
    public static function latestCheck(): ?self
    {
        return static::query()->where('type', self::TYPE_CHECK)->latest('id')->first();
    }

    /** A core update that is approved or running right now. */
    public static function openRun(): ?self
    {
        return static::query()->where('type', self::TYPE_CORE)->whereIn('status', self::OPEN)->latest('id')->first();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'cancelled', 'checked', 'applied'], true);
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->meta ?? [], $key, $default);
    }

    public function putMeta(array $values): static
    {
        $this->meta = array_replace($this->meta ?? [], $values);

        return $this;
    }

    /** Lines of the log (for the status endpoint). @return list<string> */
    public function logLines(): array
    {
        $log = rtrim((string) $this->log, "\n");

        return $log === '' ? [] : explode("\n", $log);
    }

    /** Who did it, for the history table. */
    public function actor(): string
    {
        return (string) ($this->user_email ?: ($this->via === 'schedule' ? 'Scheduled check' : 'System'));
    }
}

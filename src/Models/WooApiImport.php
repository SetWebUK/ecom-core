<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pine\Commerce\Commerce;
use Pine\Commerce\Import\WooApi\Importer;

/**
 * One WooCommerce REST API import run (table woo_api_imports, since 1.5): its options (entities, images, dry run …),
 * live progress per entity, the resume checkpoint, who started it and the result. The run log is a file
 * (Import\WooApi\RunLog). Status: pending → running → completed | failed | cancelled | interrupted
 * (cancelling = a cancel was requested and the run stops after the current page).
 *
 * @property array|null $options
 * @property array|null $progress
 * @property array|null $checkpoint
 * @property array|null $summary
 */
class WooApiImport extends Model
{
    protected $table = 'woo_api_imports';

    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
        'progress' => 'array',
        'checkpoint' => 'array',
        'summary' => 'array',
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const OPEN = ['pending', 'running', 'cancelling'];

    public const FINISHED = ['completed', 'failed', 'cancelled', 'interrupted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel(), 'user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    /** Can it be continued from its checkpoint? (interrupted, failed or cancelled real runs) */
    public function isResumable(): bool
    {
        return ! $this->dry_run && in_array($this->status, ['interrupted', 'failed', 'cancelled'], true);
    }

    /** The run that is pending or running right now, if any. */
    public static function openRun(): ?self
    {
        return static::query()->whereIn('status', self::OPEN)->latest('id')->first();
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options ?? [], $key, $default);
    }

    /** @return list<string> the selected entities, in import order */
    public function entities(): array
    {
        $selected = (array) $this->option('entities', []);

        return array_values(array_filter(Importer::ORDER, fn ($e) => in_array(Importer::GROUP[$e] ?? $e, $selected, true)));
    }

    /** Progress of one entity (status, total, fetched, created, updated, skipped, failed). */
    public function entityProgress(string $entity): array
    {
        return ($this->progress['entities'][$entity] ?? []) + ['status' => 'waiting', 'total' => null, 'fetched' => 0, 'created' => 0,
            'updated' => 0, 'skipped' => 0, 'failed' => 0];
    }

    public function actor(): string
    {
        return (string) ($this->user_email ?: ($this->via === 'cli' ? 'Command line' : 'System'));
    }
}

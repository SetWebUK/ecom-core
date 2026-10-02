<?php

namespace Pine\Commerce\Updater;

/**
 * "Only one update at a time": an exclusive flock() on {workPath}/update.lock held by the running update process
 * for its whole run. The operating system releases it when the process ends – also when it is killed – so a crashed
 * run never blocks the next one. (Not the cache: the update clears the application cache half-way.)
 */
class UpdateLock
{
    /** @var resource|null */
    protected $handle = null;

    public function __construct(protected ?string $file = null) {}

    public function file(): string
    {
        return $this->file ?? Updater::ensureDirectory(Updater::workPath()).'/update.lock';
    }

    /** Take the lock; waits up to $wait seconds (a status page may be peeking at it for a moment). */
    public function acquire(int $wait = 5): bool
    {
        if ($this->handle) {
            return true;
        }
        $handle = @fopen($this->file(), 'c+');
        if (! $handle) {
            return false;
        }
        $until = microtime(true) + $wait;
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $until) {
                fclose($handle);

                return false;
            }
            usleep(200000);
        }
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /** Is another process holding the lock right now? */
    public function held(): bool
    {
        if ($this->handle) {
            return true;
        }
        $handle = @fopen($this->file(), 'c+');
        if (! $handle) {
            return false;
        }
        $free = flock($handle, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $free;
    }

    public function __destruct()
    {
        $this->release();
    }
}

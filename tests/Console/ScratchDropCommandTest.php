<?php

namespace Pine\Commerce\Tests\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pine\Commerce\Console\ScratchDropCommand;
use Pine\Commerce\Tests\Concerns\UsesApplicationDatabase;
use Pine\Commerce\Tests\TestCase;

/** commerce:scratch:drop only ever drops zz_ tables (ARCHITECTURE §13.1 mandatory test). */
class ScratchDropCommandTest extends TestCase
{
    use UsesApplicationDatabase;

    public function test_refuses_a_prefix_that_does_not_start_with_zz(): void
    {
        foreach (['', 'wp_', 'z_', 'zzz'] as $prefix) {
            config(['database.connections.scratch.prefix' => $prefix]);
            DB::purge('scratch');
            $this->assertNull(ScratchDropCommand::tables('scratch'));
            $this->assertSame(0, ScratchDropCommand::drop('scratch'));
            $this->artisan('commerce:scratch:drop', ['--force' => true])->assertFailed();
        }
        // the default connection (no prefix) is refused too
        $this->artisan('commerce:scratch:drop', ['--connection' => config('database.default'), '--force' => true])->assertFailed();
    }

    public function test_drops_only_its_own_prefixed_tables(): void
    {
        $this->useScratchPrefix('zz_t_drop_');
        Schema::connection('scratch')->create('probe', fn ($t) => $t->id());
        $this->useScratchPrefix('zz_t_keep_');
        Schema::connection('scratch')->create('probe', fn ($t) => $t->id());

        try {
            $this->useScratchPrefix('zz_t_drop_');
            $this->assertSame(['zz_t_drop_probe'], ScratchDropCommand::tables('scratch'));
            $this->artisan('commerce:scratch:drop', ['--force' => true])->assertSuccessful();
            $this->assertSame([], ScratchDropCommand::tables('scratch'));

            $this->useScratchPrefix('zz_t_keep_');
            $this->assertSame(['zz_t_keep_probe'], ScratchDropCommand::tables('scratch'));
        } finally {
            foreach (['zz_t_drop_', 'zz_t_keep_'] as $prefix) {
                $this->useScratchPrefix($prefix);
                ScratchDropCommand::drop('scratch');
            }
        }
    }
}

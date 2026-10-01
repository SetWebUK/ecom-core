<?php

namespace Pine\Commerce\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Pine\Commerce\Support\Doctor;
use Pine\Commerce\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    public function test_every_check_has_a_status_and_problems_carry_a_fix(): void
    {
        $results = (new Doctor)->run();

        $this->assertNotEmpty($results);
        foreach ($results as $r) {
            $this->assertContains($r['status'], [Doctor::PASS, Doctor::WARN, Doctor::FAIL, Doctor::INFO]);
            $this->assertNotSame('', $r['title']);
            if ($r['status'] === Doctor::FAIL) {
                $this->assertNotEmpty($r['fix'], "FAIL '{$r['title']}' has no fix");
            }
        }
        $this->assertContains('Application', array_column($results, 'group'));
    }

    public function test_debug_mode_in_production_fails(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        $debug = collect((new Doctor)->run())->firstWhere('title', 'APP_DEBUG');

        $this->assertSame(Doctor::FAIL, $debug['status']);
    }

    public function test_json_output_and_exit_code(): void
    {
        $code = Artisan::call('commerce:doctor', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $this->assertIsArray($json);
        $this->assertArrayHasKey('checks', $json);
        $this->assertSame($json['counts']['fail'] > 0 ? 1 : 0, $code);
    }
}

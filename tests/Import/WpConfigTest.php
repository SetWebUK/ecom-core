<?php

namespace Pine\Commerce\Tests\Import;

use PHPUnit\Framework\TestCase;
use Pine\Commerce\Import\Source\WpConfig;

class WpConfigTest extends TestCase
{
    public function test_it_reads_credentials_and_prefix_without_executing_the_file(): void
    {
        $php = <<<'PHP'
<?php
/** The name of the database for WordPress */
// define( 'DB_NAME', 'commented_out' );
/* define('DB_USER', 'also_commented'); */
define( 'DB_NAME', 'shop_db' );
define("DB_USER", "shop_user");
define( 'DB_PASSWORD', 'p\'a"ss\\word' );
define('DB_HOST','localhost:3307');
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix  = 'wp7_';
exit('this file must never be executed');
require_once ABSPATH . 'wp-settings.php';
PHP;
        $c = WpConfig::parse($php);

        $this->assertSame('shop_db', $c['DB_NAME']);
        $this->assertSame('shop_user', $c['DB_USER']);
        $this->assertSame('p\'a"ss\\word', $c['DB_PASSWORD']);
        $this->assertSame('localhost:3307', $c['DB_HOST']);
        $this->assertSame('utf8mb4', $c['DB_CHARSET']);
        $this->assertSame('', $c['DB_COLLATE']);
        $this->assertSame('wp7_', $c['table_prefix']);
        $this->assertSame([], $c['unresolved']);
    }

    public function test_non_literal_values_are_reported_as_unresolved(): void
    {
        $c = WpConfig::parse("<?php\ndefine('DB_NAME', getenv('DB_NAME'));\ndefine('DB_USER', 'u');\n\$table_prefix = getenv('PREFIX') ?: 'wp_';");

        $this->assertNull($c['DB_NAME']);
        $this->assertSame('u', $c['DB_USER']);
        $this->assertNull($c['table_prefix']);
        $this->assertSame(['DB_NAME', 'table_prefix'], $c['unresolved']);
    }

    public function test_double_quoted_values_are_unescaped(): void
    {
        $c = WpConfig::parse("<?php define(\"DB_PASSWORD\", \"a\\\"b\\\\c\"); \$table_prefix=\"x_\";");

        $this->assertSame('a"b\\c', $c['DB_PASSWORD']);
        $this->assertSame('x_', $c['table_prefix']);
    }

    public function test_db_host_forms(): void
    {
        $this->assertSame(['host' => 'localhost', 'port' => null, 'socket' => null], WpConfig::splitHost('localhost'));
        $this->assertSame(['host' => 'db', 'port' => '3307', 'socket' => null], WpConfig::splitHost('db:3307'));
        $this->assertSame(['host' => 'localhost', 'port' => null, 'socket' => '/tmp/mysql.sock'], WpConfig::splitHost('localhost:/tmp/mysql.sock'));
        $this->assertSame(['host' => 'localhost', 'port' => null, 'socket' => '/run/mysqld.sock'], WpConfig::splitHost(':/run/mysqld.sock'));
        $this->assertSame(['host' => '::1', 'port' => '3306', 'socket' => null], WpConfig::splitHost('[::1]:3306'));
    }

    public function test_it_locates_wp_config_in_the_root_or_one_level_up(): void
    {
        $root = sys_get_temp_dir().'/wpcfg-'.uniqid();
        mkdir($root.'/site', 0777, true);
        file_put_contents($root.'/wp-config.php', "<?php define('DB_NAME', 'up');");
        $this->assertSame($root.'/wp-config.php', WpConfig::locate($root.'/site'));
        $this->assertSame('up', WpConfig::fromPath($root.'/site')['DB_NAME']);

        file_put_contents($root.'/site/wp-config.php', "<?php define('DB_NAME', 'here');");
        $this->assertSame('here', WpConfig::fromPath($root.'/site')['DB_NAME']);

        array_map('unlink', [$root.'/site/wp-config.php', $root.'/wp-config.php']);
        rmdir($root.'/site');
        rmdir($root);
    }
}

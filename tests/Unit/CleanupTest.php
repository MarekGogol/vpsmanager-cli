<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Caches;
use Gogol\VpsManagerCLI\Helpers\Cron;
use Gogol\VpsManagerCLI\Helpers\Sessions;
use Gogol\VpsManagerCLI\Helpers\Settings\MailSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SystemInfo;
use PHPUnit\Framework\TestCase;

class CleanupTest extends TestCase
{
    /**
     * Temporary directory of the test.
     *
     * @var string
     */
    protected $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/vpsmanager-cleanup-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    /**
     * Create a Laravel application with the given config/session.php and .env.
     *
     * @param  string  $path
     * @param  string  $config
     * @param  string  $env
     * @return string
     */
    protected function app(string $path, string $config, string $env = ''): string
    {
        $app = $this->tmp.'/'.$path;
        mkdir($app.'/config', 0755, true);
        mkdir($app.'/storage/framework/sessions', 0755, true);
        touch($app.'/artisan');
        file_put_contents($app.'/config/session.php', $config);
        file_put_contents($app.'/.env', $env);

        return $app;
    }

    public function test_lifetime_from_env_like_laravel(): void
    {
        $app = $this->app('kampo.sk/data/web', <<<'PHP'
<?php

use Illuminate\Support\Str;

return [
    'driver' => env('SESSION_DRIVER', 'database'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'files' => storage_path('framework/sessions'),
    'cookie' => env('SESSION_COOKIE', Str::slug(env('APP_NAME', 'laravel'), '_').'_session'),
];
PHP, "APP_NAME=\"Kampo shop\"\nSESSION_DRIVER=file # inline comment\nSESSION_LIFETIME=129600\n");

        $this->assertSame(['driver' => 'file', 'lifetime' => 129600, 'files' => $app.'/storage/framework/sessions'], Sessions::readConfig($app));
    }

    public function test_hard_coded_lifetime_wins_over_env(): void
    {
        // cech1990.sk: .env says 120, Laravel uses 182 days
        $app = $this->app('cech1990.sk/data/web', <<<'PHP'
<?php

return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'lifetime' => (60 * 24 * 182),
    'cookie' => str_slug(env('APP_NAME', 'laravel'), '_').'_session',
];
PHP, "SESSION_DRIVER=file\nSESSION_LIFETIME=120\n");

        $config = Sessions::readConfig($app);

        $this->assertSame(262080, $config['lifetime']);
        $this->assertSame(['directory' => realpath($app.'/storage/framework/sessions'), 'lifetime' => 262080, 'after' => 262080], Sessions::plan($app, $config));
    }

    public function test_default_of_env_is_used_without_the_variable(): void
    {
        $app = $this->app('qualit.sk/data/web', "<?php\n\nreturn ['driver' => env('SESSION_DRIVER', 'file'), 'lifetime' => env('SESSION_LIFETIME', 44640)];\n");

        $this->assertSame(['driver' => 'file', 'lifetime' => 44640, 'files' => null], Sessions::readConfig($app));
    }

    public function test_broken_config_is_skipped(): void
    {
        $app = $this->app('broken.sk/data/web', "<?php\n\nreturn ['driver' => 'file', 'lifetime' => unknown_helper()];\n");

        $plan = Sessions::plan($app, Sessions::readConfig($app));

        $this->assertArrayHasKey('skip', $plan);
        $this->assertStringContainsString('unknown_helper', $plan['skip']);
    }

    public function test_only_positive_file_sessions_are_cleaned(): void
    {
        $app = $this->app('a.sk/data/web', '<?php return [];');

        $this->assertSame(['skip' => 'driver redis'], Sessions::plan($app, ['driver' => 'redis', 'lifetime' => 120]));
        $this->assertSame(['skip' => 'driver unknown'], Sessions::plan($app, ['driver' => null, 'lifetime' => 120]));
        $this->assertStringContainsString('not a positive number', Sessions::plan($app, ['driver' => 'file', 'lifetime' => 0])['skip']);
        $this->assertStringContainsString('not a positive number', Sessions::plan($app, ['driver' => 'file', 'lifetime' => 'abc'])['skip']);
        $this->assertSame(['skip' => 'boom'], Sessions::plan($app, ['error' => 'boom']));

        // A string lifetime of .env, at least a day
        $this->assertSame(1440, Sessions::plan($app, ['driver' => 'file', 'lifetime' => '120', 'files' => null])['after']);
    }

    public function test_sessions_outside_storage_are_never_cleaned(): void
    {
        $app = $this->app('a.sk/data/web', '<?php return [];');
        mkdir($this->tmp.'/elsewhere');

        $this->assertNull(Sessions::directory($app, $this->tmp.'/elsewhere'));
        $this->assertNull(Sessions::directory($app, $app.'/storage/missing'));
        $this->assertSame(realpath($app.'/storage/framework/sessions'), Sessions::directory($app, null));
    }

    public function test_clean_removes_only_old_regular_files(): void
    {
        $directory = $this->tmp.'/sessions';
        mkdir($directory);
        $now = time();

        foreach (['old1' => 3, 'old2' => 10, 'fresh' => 0, '.gitignore' => 30] as $name => $days) {
            file_put_contents($directory.'/'.$name, 'x');
            touch($directory.'/'.$name, $now - $days * 86400);
        }

        mkdir($directory.'/subdir');
        touch($directory.'/subdir', $now - 30 * 86400);
        symlink($directory.'/old2', $directory.'/link');

        $dry = Sessions::clean($directory, 2 * 1440, true, $now);
        $this->assertSame(3, $dry['files']);
        $this->assertSame(2, $dry['removed']);
        $this->assertFileExists($directory.'/old1');

        $result = Sessions::clean($directory, 2 * 1440, false, $now);
        $this->assertSame(2, $result['removed']);
        $this->assertFileDoesNotExist($directory.'/old1');
        $this->assertFileDoesNotExist($directory.'/old2');
        $this->assertFileExists($directory.'/fresh');
        $this->assertFileExists($directory.'/.gitignore');
        $this->assertDirectoryExists($directory.'/subdir');
    }

    public function test_apps_of_the_hostings(): void
    {
        $web = $this->app('kampo.sk/data/web', '<?php return [];');
        $api = $this->app('haus.sk/data/sub/app', '<?php return [];');
        mkdir($this->tmp.'/static.sk/data/web', 0755, true);

        $this->assertSame([$api, $web], Sessions::apps($this->tmp));
    }

    public function test_durations(): void
    {
        $this->assertSame('2 h', Sessions::duration(120));
        $this->assertSame('1 d', Sessions::duration(1440));
        $this->assertSame('182 d', Sessions::duration(262080));
        $this->assertSame('45 min', Sessions::duration(45));
    }

    public function test_cache_candidates(): void
    {
        $home = $this->tmp.'/crudadmin.com/data';
        $old = time() - 40 * 86400;

        foreach (['.npm/_cacache/index-v5', '.npm/_npx/old', '.npm/_npx/new', '.npm/_logs', '.cache/Cypress/13.0.0', '.cache/composer/files', '.cache/puppeteer/chrome'] as $path) {
            mkdir($home.'/'.$path, 0755, true);
        }

        touch($home.'/.npm/_npx/old', $old);
        touch($home.'/.cache/Cypress/13.0.0', $old);

        $this->assertSame([
            $home.'/.npm/_cacache',
            $home.'/.npm/_npx/old',
            $home.'/.cache/Cypress/13.0.0',
            $home.'/.cache/composer',
        ], Caches::candidates($home));

        $this->assertTrue(Caches::remove($home.'/.npm/_cacache'));
        $this->assertDirectoryDoesNotExist($home.'/.npm/_cacache');
        $this->assertDirectoryExists($home.'/.npm/_logs');
    }

    public function test_cleanup_jobs(): void
    {
        $this->assertSame([
            '17 3 * * 1 root vm clean:sessions > /dev/null 2>&1',
            '17 2 1 * * root vm clean:cache > /dev/null 2>&1',
        ], Cron::cleanupJobs('vm', 17));
    }

    public function test_big_mailboxes_are_noted(): void
    {
        $notes = MailSetting::notes(['/var/mail/cech1990.sk' => 2 * SystemInfo::GB, '/var/mail/mail' => SystemInfo::MB]);

        $this->assertCount(1, $notes);
        $this->assertStringStartsWith('/var/mail/cech1990.sk has 2.0 GB', $notes[0]);
    }
}

<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\RequestStats;
use PHPUnit\Framework\TestCase;

class RequestStatsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/vpsmanager-request-stats-'.uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*'));
        rmdir($this->directory);
    }

    private function lines(string $path, array &$position): array
    {
        return iterator_to_array((new RequestStats)->readNewLines($path, $position), false);
    }

    public function test_server_names_without_www_catch_all_and_regular_expressions(): void
    {
        $conf = "server {\n    server_name www.example.com example.com;\n}\n"
            ."server {\n    server_name _ \"~^www\\.(?<sub>.+)\\.test\\.sk$\";\n    server_name ~^(?<sub>.+)\\.test\\.sk$ ~^api[0-9]+$;\n}\n"
            ."server {\n    # server_name commented.sk;\n    server_name .wild.sk *.star.sk;\n}\n";

        $this->assertSame(['example.com', '*.test.sk', 'wild.sk', '*.wild.sk', '*.star.sk'], array_keys((new RequestStats)->parseServerNames($conf)));
    }

    public function test_hosts_are_counted_with_their_server_section(): void
    {
        $stats = new RequestStats;
        $hosts = ['example.com' => true, 'api.example.com' => true, '*.star.sk' => true];

        $this->assertSame('example.com', $stats->normalizeHost('www.Example.com', $hosts));
        $this->assertSame('example.com', $stats->normalizeHost('example.com:443', $hosts));
        $this->assertSame('api.example.com', $stats->normalizeHost('api.example.com', $hosts));
        $this->assertSame('a.b.star.sk', $stats->normalizeHost('www.a.b.star.sk', $hosts));
        $this->assertSame(RequestStats::OTHER, $stats->normalizeHost('185.1.2.3', $hosts));
        $this->assertSame(RequestStats::OTHER, $stats->normalizeHost('star.sk', $hosts));
    }

    public function test_only_new_complete_lines_are_read(): void
    {
        $log = $this->directory.'/access.log';
        file_put_contents($log, "one\ntwo\n");
        $position = (new RequestStats)->getEndPosition($log);

        $this->assertSame([], $this->lines($log, $position));

        file_put_contents($log, "three\nfou", FILE_APPEND);
        $this->assertSame(['three'], $this->lines($log, $position));

        file_put_contents($log, "r\n", FILE_APPEND);
        $this->assertSame(['four'], $this->lines($log, $position));
    }

    public function test_end_position_with_backfill_starts_at_a_line(): void
    {
        $log = $this->directory.'/access.log';
        file_put_contents($log, "first line\nsecond line\nthird\n");
        $position = (new RequestStats)->getEndPosition($log, 10);

        $this->assertSame(['third'], $this->lines($log, $position));
    }

    public function test_rest_of_the_rotated_log_comes_first(): void
    {
        $log = $this->directory.'/access.log';
        file_put_contents($log, "old\n");
        $position = (new RequestStats)->getEndPosition($log);

        file_put_contents($log, "before rotation\n", FILE_APPEND);
        rename($log, $log.'.1');
        file_put_contents($log, "after rotation\n");

        $this->assertSame(['before rotation', 'after rotation'], $this->lines($log, $position));
        $this->assertSame([], $this->lines($log, $position));
    }

    public function test_truncated_log_is_read_from_the_start(): void
    {
        $log = $this->directory.'/access.log';
        file_put_contents($log, "a long line which is gone\n");
        $position = (new RequestStats)->getEndPosition($log);

        $handle = fopen($log, 'r+');
        ftruncate($handle, 0);
        fclose($handle);
        file_put_contents($log, "new\n");

        $this->assertSame(['new'], $this->lines($log, $position));
    }

    public function test_lines_of_both_logs_are_parsed(): void
    {
        $stats = new RequestStats;

        $request = $stats->parseRequest('1.2.3.4 [2026-10-11T13:15:02+02:00] "www.example.com" "GET /path?a=1" 502 120 0.250 "-" "curl/8"');
        $this->assertSame(['time' => strtotime('2026-10-11T13:15:02+02:00'), 'host' => 'www.example.com', 'status' => 502, 'duration' => 0.25], $request);

        $blocked = $stats->parseBlocked('1.2.3.4 - - [11/Oct/2026:13:15:02 +0200] "GET /.env HTTP/1.1" 403 1234 "-" "Mozilla \x22quoted\x22" "example.com"');
        $this->assertSame(['time' => strtotime('2026-10-11T13:15:02+02:00'), 'host' => 'example.com'], $blocked);

        $this->assertNull($stats->parseRequest('garbage'));
        $this->assertNull($stats->parseBlocked('garbage'));
    }
}

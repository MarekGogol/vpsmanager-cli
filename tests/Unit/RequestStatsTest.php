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
        $this->assertSame(['time' => strtotime('2026-10-11T13:15:02+02:00'), 'host' => 'www.example.com', 'method' => 'GET', 'url' => '/path?a=1', 'status' => 502, 'duration' => 0.25], $request);

        $upload = $stats->parseRequest('1.2.3.4 [2026-10-11T13:15:02+02:00] "www.example.com" "POST /upload.php" 200 120 0.250 "-" "curl/8" "multipart/form-data; boundary=x" 1234');
        $this->assertSame('/upload.php', $upload['url']);

        $blocked = $stats->parseBlocked('1.2.3.4 - - [11/Oct/2026:13:15:02 +0200] "GET /.env HTTP/1.1" 403 1234 "-" "Mozilla \x22quoted\x22" "example.com"');
        $this->assertSame(['time' => strtotime('2026-10-11T13:15:02+02:00'), 'host' => 'example.com'], $blocked);

        $this->assertNull($stats->parseRequest('garbage'));
        $this->assertNull($stats->parseBlocked('garbage'));
    }

    public function test_endpoints_join_numbers_and_hashes_of_the_path(): void
    {
        $stats = new RequestStats;

        $this->assertSame('GET /blog/{id}', $stats->normalizeEndpoint('get', '/blog/123?page=2'));
        $this->assertSame('POST /api/orders/{uuid}/pay', $stats->normalizeEndpoint('POST', '/api/orders/0b9f6c1e-7d2a-4c3e-9f10-2a6b8c4d5e6f/pay'));
        $this->assertSame('GET /files/{hash}', $stats->normalizeEndpoint('GET', '/files/53d015123caf1a5253b408daee377969'));
        $this->assertSame('GET /reset/{token}', $stats->normalizeEndpoint('GET', '/reset/aB3dE5gH7jK9mN1pQ3sT5vX7'));
        $this->assertSame('GET /ebook/{slug}', $stats->normalizeEndpoint('GET', '/ebook/zbav-sa-hypoteky-o-10-az-15-rokov-skor?fbclid=x'));
        $this->assertSame('GET /', $stats->normalizeEndpoint('GET', '/?utm=1'));
    }

    public function test_slugs_of_pages_are_one_endpoint_routes_stay(): void
    {
        $stats = new RequestStats;

        // Pages of all products are one endpoint
        $this->assertSame('GET /produkt/{slug}/{id}', $stats->normalizeEndpoint('GET', '/produkt/diar-a6-filofax-malden-burgundy/8923'));
        $this->assertSame('GET /produkt/{slug}/{id}', $stats->normalizeEndpoint('GET', '/produkt/pero-gulockove-rotring-tikky-tyrkysove/8388'));
        $this->assertSame('GET /kategoria/{slug}/peracniky', $stats->normalizeEndpoint('GET', '/kategoria/skolske-potreby-a-pomocky/peracniky'));
        $this->assertSame('GET /clanok/{slug}', $stats->normalizeEndpoint('GET', '/clanok/ako-vybrat-skolsku-tasku'));

        // Routes of one or two words, the first segment and files stay
        $this->assertSame('POST /api/auth/forgot-password', $stats->normalizeEndpoint('POST', '/api/auth/forgot-password'));
        $this->assertSame('GET /kategoria/skolske-potreby', $stats->normalizeEndpoint('GET', '/kategoria/skolske-potreby'));
        $this->assertSame('GET /obchodne-podmienky-a-reklamacie', $stats->normalizeEndpoint('GET', '/obchodne-podmienky-a-reklamacie'));
        $this->assertSame('GET /vendor/js/ca-translates-and-more.js', $stats->normalizeEndpoint('GET', '/vendor/js/ca-translates-and-more.js'));
        $this->assertSame('POST /admin/api/rows/orders_items', $stats->normalizeEndpoint('POST', '/admin/api/rows/orders_items'));
    }

    public function test_durations_fall_into_their_bands(): void
    {
        $stats = new RequestStats;

        $this->assertSame([0, 0, 1, 2, 3, 4, 5, 6, 7, 7], array_map([$stats, 'durationBand'], [0, 99, 100, 250, 999, 2499, 4999, 9999, 10000, 60000]));
    }
}

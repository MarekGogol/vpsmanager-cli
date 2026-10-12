<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\MonitorAgent;
use PHPUnit\Framework\TestCase;

class MonitorAgentTest extends TestCase
{
    public function test_only_top_endpoints_are_sent_the_rest_is_summed(): void
    {
        $endpoints = [];

        // 30 endpoints by requests, the last one is slow but rare
        for ($i = 1; $i <= 30; $i++) {
            $endpoints['GET /page-'.$i] = [100 - $i, (100 - $i) * 10, 50, 0, '/page-'.$i, 1791720000];
        }

        $endpoints['GET /slow'] = [1, 60000, 60000, 1, '/slow?x=1', 1791720100];

        $rows = array_column((new MonitorAgent)->topEndpoints($endpoints), null, 'endpoint');

        $this->assertArrayHasKey('GET /page-1', $rows);
        $this->assertArrayHasKey('GET /page-20', $rows);
        $this->assertArrayHasKey('GET /slow', $rows, 'the longest total duration is sent although it is rare');
        $this->assertArrayNotHasKey('GET /page-21', $rows);

        // page-21 to page-30 are summed, nothing is lost
        $this->assertSame(array_sum(range(70, 79)), $rows[MonitorAgent::OTHER_ENDPOINTS]['requests']);
        $this->assertSame(array_sum(array_column($endpoints, 0)), array_sum(array_column($rows, 'requests')));
        $this->assertSame(array_sum(array_column($endpoints, 1)), array_sum(array_column($rows, 'duration_ms')));
        $this->assertSame('/slow?x=1', $rows['GET /slow']['slowest_url']);
    }

    private function request(int $status, float $duration, string $url = '/page'): array
    {
        return ['time' => 1791720000, 'host' => 'api.example.com', 'method' => 'GET', 'url' => $url, 'status' => $status, 'duration' => $duration];
    }

    public function test_websockets_are_counted_apart_from_the_requests(): void
    {
        $agent = new MonitorAgent;
        $window = null;

        $agent->addToWindow($window, $this->request(200, 0.120), false);
        $agent->addToWindow($window, $this->request(500, 0.080), false);
        // Reverb connections closed after 45 minutes and 2 hours
        $agent->addToWindow($window, $this->request(101, 2700.0, '/app/abc123?protocol=7&client=js'), false);
        $agent->addToWindow($window, $this->request(101, 7200.0, '/app/abc123?protocol=7&client=js'), false);
        $agent->addToWindow($window, $this->request(403, 0.001, '/.env'), true);

        $row = $agent->windowRow(1791720000, 'api.example.com', $window);

        $this->assertSame(2, $row['requests']);
        $this->assertSame(2, $row['websockets']);
        $this->assertSame(1, $row['blocked']);
        $this->assertSame(1, $row['errors']);
        $this->assertSame(200, $row['duration_ms'], 'the hours of the WebSockets are not in the duration');
        $this->assertSame(2, array_sum($row['histogram']));
        $this->assertSame(0, $row['histogram'][count($row['histogram']) - 1], 'no WebSocket in the slowest band');
        $this->assertSame(['GET /page'], array_column($row['endpoints'], 'endpoint'), 'a WebSocket is no endpoint');
    }

    public function test_window_counted_by_a_version_without_websockets_is_counted_on(): void
    {
        $agent = new MonitorAgent;

        // A window of the state of monitor:collect 2.1.0: requests, blocked, errors, duration, histogram, endpoints
        $window = [3, 0, 0, 300, [3, 0, 0, 0, 0, 0, 0, 0], ['GET /page' => [3, 300, 100, 0, '/page', 1791720000]]];

        $this->assertSame(0, $agent->windowRow(1791720000, 'api.example.com', $window)['websockets']);

        $agent->addToWindow($window, $this->request(101, 600.0), false);
        $agent->addToWindow($window, $this->request(200, 0.100), false);

        $row = $agent->windowRow(1791720000, 'api.example.com', $window);

        $this->assertSame(4, $row['requests']);
        $this->assertSame(1, $row['websockets']);
        $this->assertSame(400, $row['duration_ms']);
    }

    public function test_window_of_a_version_without_histogram_gets_websockets(): void
    {
        $agent = new MonitorAgent;
        $window = [1, 0, 0, 50];

        $agent->addToWindow($window, $this->request(101, 30.0), false);

        $row = $agent->windowRow(1791720000, 'api.example.com', $window);

        $this->assertSame([1, 1, 50], [$row['requests'], $row['websockets'], $row['duration_ms']]);
        $this->assertSame([], $row['endpoints']);
    }
}

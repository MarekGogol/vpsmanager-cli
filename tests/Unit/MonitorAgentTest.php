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
}

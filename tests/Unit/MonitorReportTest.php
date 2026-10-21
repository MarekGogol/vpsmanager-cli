<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Monitor;
use PHPUnit\Framework\TestCase;

class MonitorReportTest extends TestCase
{
    public function test_multipart_requests_are_listed_most_suspicious_first(): void
    {
        // Upload of a user, a chunked body without the length answered 502, a banned scanner of PHP2Shell
        $monitor = new class extends Monitor
        {
            const LOG = __DIR__.'/fixtures/monitor-report.log';
        };

        $rows = $monitor->report(0, ['3.3.3.3'])['multipart_addresses'];

        $this->assertSame(['3.3.3.3', '2.2.2.2', '1.1.1.1'], array_column($rows, 'ip'));
        $this->assertTrue($rows[0]['php2shell'] && $rows[0]['banned']);
        $this->assertSame([1, 1], [$rows[1]['without_length'], $rows[1]['server_errors']]);
        $this->assertSame([0, 0, '/upload'], [$rows[2]['without_length'], $rows[2]['server_errors'], $rows[2]['paths']]);
    }

    public function test_lines_without_the_content_type_and_length_are_read(): void
    {
        $monitor = new class extends Monitor
        {
            const LOG = __DIR__.'/fixtures/monitor-report.log';
        };

        $requests = array_column(iterator_to_array($monitor->readRequests(0), false), null, 'url');

        $this->assertSame(['', null], [$requests['/page']['content_type'], $requests['/page']['content_length']]);
        $this->assertSame(['application/json', 20], [$requests['/api']['content_type'], $requests['/api']['content_length']]);
        $this->assertNull($requests['/index.php']['content_length']);
    }
}

<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Command\Monitor\MonitorListenCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class MonitorListenTest extends TestCase
{
    public function test_websockets_have_their_own_column_and_are_not_requests(): void
    {
        $command = new class extends MonitorListenCommand
        {
            public function count(string $host, int $status, float $duration): void
            {
                $this->add($host, ['time' => time(), 'status' => $status, 'duration' => $duration], false, true);
            }

            public function table(): string
            {
                $output = new BufferedOutput;
                $this->render($output, time() - 30, 30);

                return $output->fetch();
            }
        };

        $command->count('api.example.com', 200, 0.100);
        $command->count('api.example.com', 200, 0.300);
        $command->count('api.example.com', 101, 3600.0);

        $row = array_values(array_filter(explode("\n", $command->table()), fn ($line) => str_contains($line, 'api.example.com')))[0];
        $cells = array_map('trim', array_slice(explode('|', $row), 1, -1));

        // Host, Req/min, Req/s, Avg req/s, Total, 5xx/min, Avg ms, WS/min, Blocked/min, Blocked
        $this->assertSame('2', $cells[1]);
        $this->assertSame('2', $cells[4], 'the WebSocket is not in the total of the requests');
        $this->assertSame('200', $cells[6], 'the hour of the WebSocket is not in the average');
        $this->assertSame('1', $cells[7]);
    }
}

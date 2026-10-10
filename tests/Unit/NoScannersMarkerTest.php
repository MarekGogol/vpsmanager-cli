<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Nginx;
use PHPUnit\Framework\TestCase;

class NoScannersMarkerTest extends TestCase
{
    private function install(string $conf): string
    {
        $nginx = new Nginx;

        return $nginx->syncVpsManagerIncludeAfter($nginx->addVpsManagerInclude($conf, 'scanners.conf', true, Nginx::NO_SCANNERS_MARKER), 'scanners-php.conf', 'scanners.conf');
    }

    private function section(string $lines = ''): string
    {
        return "server {\n    server_name tools.example.com;\n".$lines."    location / {\n    }\n\n    include vpsmanager/general.conf;\n}\n";
    }

    public function test_section_without_the_marker_gets_the_rules(): void
    {
        $conf = $this->install($this->section());

        $this->assertStringContainsString('include vpsmanager/scanners.conf;', $conf);
        $this->assertStringContainsString('include vpsmanager/scanners-php.conf;', $conf);
    }

    public function test_marked_section_gets_no_rules(): void
    {
        $conf = $this->install($this->section('    '.Nginx::NO_SCANNERS_MARKER." (phpMyAdmin)\n"));

        $this->assertStringNotContainsString('scanners.conf', $conf);
        $this->assertStringNotContainsString('scanners-php.conf', $conf);
    }

    public function test_marking_an_installed_section_removes_all_its_rules(): void
    {
        $installed = $this->install($this->section());
        $marked = str_replace("    server_name tools.example.com;\n", "    server_name tools.example.com;\n    ".Nginx::NO_SCANNERS_MARKER."\n", $installed);

        $conf = $this->install($marked);

        $this->assertStringNotContainsString('include vpsmanager/scanners.conf;', $conf);
        $this->assertStringNotContainsString('include vpsmanager/scanners-php.conf;', $conf);
        $this->assertStringContainsString('include vpsmanager/general.conf;', $conf);
    }

    public function test_access_log_ignores_the_marker(): void
    {
        $conf = (new Nginx)->addVpsManagerInclude($this->section('    '.Nginx::NO_SCANNERS_MARKER."\n"), 'monitor.conf', false);

        $this->assertStringContainsString('include vpsmanager/monitor.conf;', $conf);
    }
}

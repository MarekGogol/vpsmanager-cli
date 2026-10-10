<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Nginx;
use PHPUnit\Framework\TestCase;

class PhpRulesIncludeTest extends TestCase
{
    private function section(string $lines): string
    {
        return "server {\n    server_name example.com;\n    root /var/www/example/public;\n".$lines."    include vpsmanager/general.conf;\n    include vpsmanager/scanners.conf;\n}\n";
    }

    private function sync(string $conf): string
    {
        return (new Nginx)->syncVpsManagerIncludeAfter($conf, 'scanners-php.conf', 'scanners.conf');
    }

    public function test_application_gets_the_rules_against_php_files(): void
    {
        $this->assertStringContainsString("include vpsmanager/scanners.conf;\n    include vpsmanager/scanners-php.conf;", $this->sync($this->section('')));
    }

    public function test_section_with_a_password_serves_its_php_files(): void
    {
        $this->assertStringNotContainsString('scanners-php.conf', $this->sync($this->section("    auth_basic \"Tools\";\n")));
    }

    public function test_section_marked_by_the_comment_serves_its_php_files(): void
    {
        $conf = $this->section('    '.Nginx::ALLOW_PHP_MARKER." (booking/proxy.php of the reservations)\n");

        $this->assertStringNotContainsString('scanners-php.conf', $this->sync($conf));
    }

    public function test_marking_a_section_later_removes_the_rules(): void
    {
        $installed = $this->sync($this->section(''));
        $marked = str_replace("    root /var/www/example/public;\n", "    root /var/www/example/public;\n    ".Nginx::ALLOW_PHP_MARKER."\n", $installed);

        $this->assertStringNotContainsString('scanners-php.conf', $this->sync($marked));
    }

    public function test_password_turned_off_in_the_section_keeps_the_rules(): void
    {
        $this->assertStringContainsString('scanners-php.conf', $this->sync($this->section("    auth_basic off;\n")));
    }
}

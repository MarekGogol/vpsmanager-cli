<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Fail2ban;
use PHPUnit\Framework\TestCase;

class CrawlerNetworksTest extends TestCase
{
    public function test_published_list_is_parsed(): void
    {
        $json = '{"creationTime": "2026-10-07T00:00:00Z", "prefixes": [{"ipv4Prefix": "66.249.64.0/27"}, {"ipv6Prefix": "2001:4860:4801:10::/64"}, {"ipv4Prefix": "not an address"}]}';

        $this->assertSame(['66.249.64.0/27', '2001:4860:4801:10::/64'], (new Fail2ban)->parseCrawlerNetworks($json));
    }

    public function test_invalid_list_falls_back(): void
    {
        $this->assertNull((new Fail2ban)->parseCrawlerNetworks('<html>Not found</html>'));
        $this->assertNull((new Fail2ban)->parseCrawlerNetworks('{"prefixes": []}'));
    }

    public function test_fallback_copy_has_every_crawler(): void
    {
        $fallback = json_decode(file_get_contents(Fail2ban::CRAWLERS_FALLBACK), true);

        foreach (array_keys(Fail2ban::CRAWLERS) as $crawler) {
            $this->assertNotEmpty($fallback[$crawler] ?? null, $crawler.' is missing in crawlers.json');
        }
    }

    public function test_real_crawler_is_never_banned_and_fake_one_is(): void
    {
        $fail2ban = new Fail2ban;
        $fallback = json_decode(file_get_contents(Fail2ban::CRAWLERS_FALLBACK), true);
        $networks = array_merge($fallback['Googlebot'], $fallback['ClaudeBot']);

        // Googlebot 66.249.64.0/27, ClaudeBot 216.73.216.0/22
        $this->assertTrue($fail2ban->isIgnoredIp('66.249.64.10', $networks));
        $this->assertTrue($fail2ban->isIgnoredIp('216.73.217.5', $networks));

        // Scanner with the user agent of Googlebot
        $this->assertFalse($fail2ban->isIgnoredIp('87.120.104.29', $networks));
    }
}

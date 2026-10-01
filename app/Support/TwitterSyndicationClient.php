<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared-hosting-friendly browser-like HTTP for public X profile pages.
 * Matches a working curl -L Chrome fingerprint against https://x.com/{handle}.
 * No Playwright/Node, no official API, no third-party bridges.
 */
class TwitterSyndicationClient
{
    private const CHROME_MAJOR = '140';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const PROFILE_URL = 'https://x.com/%s';

    /**
     * Legacy embed endpoint. Kept for tests/fallback parsing only — X rate-limits
     * it tightly (~30/window), which is why live fetches prefer profileUrl().
     */
    private const TIMELINE_URL = 'https://syndication.twitter.com/srv/timeline-profile/screen-name/%s';

    public function profileUrl(string $handle): string
    {
        return sprintf(self::PROFILE_URL, rawurlencode($handle));
    }

    public function timelineUrl(string $handle): string
    {
        return sprintf(self::TIMELINE_URL, rawurlencode($handle));
    }

    public function userAgent(): string
    {
        return self::USER_AGENT;
    }

    /**
     * Chrome-like document navigation headers (same shape as a desktop curl -L).
     *
     * @return array<string, string>
     */
    public function browserHeaders(?string $referer = null, string $fetchSite = 'none'): array
    {
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'max-age=0',
            'Sec-CH-UA' => '"Chromium";v="'.self::CHROME_MAJOR.'", "Not=A?Brand";v="24", "Google Chrome";v="'.self::CHROME_MAJOR.'"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Windows"',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => $fetchSite,
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ];

        if (is_string($referer) && $referer !== '') {
            $headers['Referer'] = $referer;
        }

        return $headers;
    }

    /**
     * Single first-party profile GET — the path that works with Eric's curl.
     */
    public function pendingProfileRequest(): PendingRequest
    {
        return Http::withOptions([
            'allow_redirects' => [
                'max' => 5,
                'track_redirects' => true,
            ],
            'http_errors' => false,
            'version' => '2.0',
        ])
            ->withUserAgent($this->userAgent())
            ->withHeaders($this->browserHeaders(fetchSite: 'none'));
    }
}

<?php

namespace App\Support;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared-hosting-friendly browser-like HTTP shape for X syndication embeds.
 * No Playwright/Node, no official API, no third-party bridges.
 */
class TwitterSyndicationClient
{
    private const CHROME_MAJOR = '141';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    private const TIMELINE_URL = 'https://syndication.twitter.com/srv/timeline-profile/screen-name/%s';

    private const PROFILE_URL = 'https://twitter.com/%s';

    public function timelineUrl(string $handle): string
    {
        return sprintf(self::TIMELINE_URL, rawurlencode($handle));
    }

    public function profileUrl(string $handle): string
    {
        return sprintf(self::PROFILE_URL, rawurlencode($handle));
    }

    public function userAgent(): string
    {
        return self::USER_AGENT;
    }

    public function newCookieJar(): CookieJar
    {
        return new CookieJar;
    }

    /**
     * Chrome-like document navigation headers. Referer/Sec-Fetch-Site should
     * match how the request relates to the prior soft bootstrap hit.
     *
     * @return array<string, string>
     */
    public function browserHeaders(?string $referer = null, string $fetchSite = 'none'): array
    {
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'max-age=0',
            'Sec-CH-UA' => '"Google Chrome";v="'.self::CHROME_MAJOR.'", "Chromium";v="'.self::CHROME_MAJOR.'", "Not A(Brand";v="24"',
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
     * Timeline embed request after visiting the public profile on twitter.com.
     *
     * @return array<string, string>
     */
    public function timelineHeaders(string $handle): array
    {
        return $this->browserHeaders(
            referer: $this->profileUrl($handle),
            fetchSite: 'same-site',
        );
    }

    /**
     * Soft profile hit so syndication can see a prior guest_id on .twitter.com.
     * Failures are ignored — the timeline request still proceeds.
     */
    public function softBootstrap(CookieJar $jar, string $handle, int $timeoutSeconds, int $connectTimeoutSeconds): void
    {
        try {
            Http::withOptions(['cookies' => $jar])
                ->timeout($timeoutSeconds)
                ->connectTimeout($connectTimeoutSeconds)
                ->withUserAgent($this->userAgent())
                ->withHeaders($this->browserHeaders(fetchSite: 'none'))
                ->get($this->profileUrl($handle));
        } catch (Throwable) {
            // Soft bootstrap only.
        }
    }

    public function pendingTimelineRequest(CookieJar $jar, string $handle): PendingRequest
    {
        return Http::withOptions(['cookies' => $jar])
            ->withUserAgent($this->userAgent())
            ->withHeaders($this->timelineHeaders($handle));
    }
}

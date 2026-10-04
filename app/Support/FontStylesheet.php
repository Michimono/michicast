<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Vite's @fonts directive always prints a <style> block, even when the build
 * already emitted a fonts stylesheet. A strict CSP with no 'unsafe-inline'
 * drops that block, so the preloaded font files never apply. This renders
 * preload links plus a stylesheet link instead.
 */
class FontStylesheet
{
    public function __construct(
        private ?string $buildManifest = null,
        private ?string $devManifest = null,
        private ?string $hotFile = null,
    ) {}

    public function render(): HtmlString
    {
        $hot = $this->isHot();
        $manifest = $this->read($hot ? $this->devManifestPath() : $this->buildManifestPath());

        if ($manifest === null) {
            return new HtmlString('');
        }

        return new HtmlString($this->tags($manifest, $hot));
    }

    /**
     * CSS stored inline in the dev font manifest. Served as a stylesheet
     * response so `npm run dev` does not need a <style> tag. Production uses
     * the built file and this returns null.
     */
    public function devCss(): ?string
    {
        $manifest = $this->read($this->devManifestPath());
        $style = is_array($manifest) && is_array($manifest['style'] ?? null) ? $manifest['style'] : [];
        $inline = $style['inline'] ?? null;

        return is_string($inline) && $inline !== '' ? $inline : null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function tags(array $manifest, bool $hot): string
    {
        $tags = [];

        foreach ($manifest['preloads'] ?? [] as $preload) {
            if (! is_array($preload)) {
                continue;
            }

            $href = $this->preloadHref($preload);

            if ($href === null) {
                continue;
            }

            $tags[] = $this->link([
                'rel' => 'preload',
                'as' => is_string($preload['as'] ?? null) ? $preload['as'] : 'font',
                'href' => $href,
                'type' => is_string($preload['type'] ?? null) ? $preload['type'] : null,
                'crossorigin' => is_string($preload['crossorigin'] ?? null) ? $preload['crossorigin'] : null,
            ]);
        }

        $style = is_array($manifest['style'] ?? null) ? $manifest['style'] : [];
        $file = $style['file'] ?? null;

        if (is_string($file) && $file !== '') {
            $tags[] = $this->link([
                'rel' => 'stylesheet',
                'href' => asset('build/'.ltrim($file, '/')),
            ]);
        } elseif ($hot && is_string($style['inline'] ?? null) && $style['inline'] !== '') {
            $tags[] = $this->link([
                'rel' => 'stylesheet',
                'href' => route('fonts.stylesheet'),
            ]);
        }

        return implode("\n", $tags);
    }

    private function isHot(): bool
    {
        return is_file($this->hotFile ?? public_path('hot'));
    }

    private function buildManifestPath(): string
    {
        return $this->buildManifest ?? public_path('build/fonts-manifest.json');
    }

    private function devManifestPath(): string
    {
        return $this->devManifest ?? public_path('fonts-manifest.dev.json');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * @param  array<string, mixed>  $preload
     */
    private function preloadHref(array $preload): ?string
    {
        $url = $preload['url'] ?? null;

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $file = $preload['file'] ?? null;

        if (! is_string($file) || $file === '') {
            return null;
        }

        return asset('build/'.ltrim($file, '/'));
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    private function link(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $name.'="'.e($value).'"';
        }

        return '<link '.implode(' ', $parts).' />';
    }
}

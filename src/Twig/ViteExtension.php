<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * ViteExtension
 *
 * Integrates Vite's asset manifest into Twig templates so that CSS and JS
 * files are always referenced by their content-hashed production filenames,
 * giving automatic cache-busting without any manual version bumps.
 *
 * Usage in templates:
 *   {{ vite_asset('assets/js/app.js') }}   → <script src="/assets/dist/app-Abc123.js"></script>
 *   {{ vite_asset('assets/js/app.js', 'css') }}  → <link rel="stylesheet" href="/assets/dist/app-Abc123.css">
 *
 * In development (ENVIRONMENT != 'production') the function returns the
 * original unversioned path so Vite's HMR / dev-server works normally.
 *
 * The manifest is read from `assets/dist/.vite/manifest.json` (the default
 * Vite output when `build.manifest: true` is set in vite.config.js).
 */
class ViteExtension extends AbstractExtension
{
    /** @var array<string, array<string, mixed>>|null Parsed manifest cache */
    private ?array $manifest = null;

    /** Absolute path to the project root */
    private string $projectRoot;

    /** When true, skip the manifest and return raw paths */
    private bool $isDev;

    public function __construct(string $projectRoot, bool $isDev = false)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->isDev       = $isDev;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vite_asset', [$this, 'viteAsset'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Return an HTML tag for the given Vite entry point.
     *
     * @param string      $entry  Entry key as defined in vite.config.js (e.g. 'assets/js/app.js')
     * @param string|null $type   'js' (default, produces <script>) or 'css' (produces <link>).
     *                             When null the type is inferred from the entry file extension.
     * @return string HTML tag
     */
    public function viteAsset(string $entry, ?string $type = null): string
    {
        if ($this->isDev) {
            return $this->devTag($entry, $type);
        }

        $manifest = $this->loadManifest();

        if (! isset($manifest[$entry])) {
            // Fallback: return a comment so broken assets are visible in dev tools
            return '<!-- vite_asset: "' . htmlspecialchars($entry, ENT_QUOTES) . '" not found in manifest -->';
        }

        $file    = $manifest[$entry]['file'] ?? '';
        $fileUrl = defined('BASE_URL') ? \BASE_URL . '/assets/dist/' . ltrim($file, '/') : '/assets/dist/' . ltrim($file, '/');

        $inferredType = $type ?? $this->inferType($entry);

        if ($inferredType === 'css') {
            return '<link rel="stylesheet" href="' . htmlspecialchars($fileUrl, ENT_QUOTES) . '">';
        }

        return '<script type="module" src="' . htmlspecialchars($fileUrl, ENT_QUOTES) . '"></script>';
    }

    // -------------------------------------------------------------------------

    private function devTag(string $entry, ?string $type): string
    {
        $baseUrl = defined('BASE_URL') ? \BASE_URL : '';
        $src     = $baseUrl . '/' . ltrim($entry, '/');

        $inferredType = $type ?? $this->inferType($entry);

        if ($inferredType === 'css') {
            return '<link rel="stylesheet" href="' . htmlspecialchars($src, ENT_QUOTES) . '">';
        }

        return '<script type="module" src="' . htmlspecialchars($src, ENT_QUOTES) . '"></script>';
    }

    private function inferType(string $entry): string
    {
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        return in_array($ext, ['css', 'scss', 'less', 'sass'], true) ? 'css' : 'js';
    }

    /** @return array<string, array<string, mixed>> */
    private function loadManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->projectRoot . '/assets/dist/.vite/manifest.json';

        if (! file_exists($path)) {
            $this->manifest = [];
            return $this->manifest;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->manifest = [];
            return $this->manifest;
        }

        $data = json_decode($raw, true);
        $this->manifest = is_array($data) ? $data : [];

        return $this->manifest;
    }
}

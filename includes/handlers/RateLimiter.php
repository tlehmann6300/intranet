<?php
/**
 * RateLimiter
 *
 * Provides IP-based request rate limiting to protect sensitive endpoints
 * (OAuth login initiation, API endpoints) against brute-force / abuse.
 *
 * Storage backend: file-based (default, zero-dependency) or APCu when
 * available.  The file backend stores one JSON file per IP address in the
 * configured cache directory.
 *
 * Usage:
 *   $limiter = new RateLimiter('login', maxAttempts: 10, windowSeconds: 600);
 *   if ($limiter->tooManyAttempts()) {
 *       http_response_code(429);
 *       die('Too many requests – try again later.');
 *   }
 *   $limiter->hit(); // record an attempt
 *
 * Rate-limit entries expire automatically after $windowSeconds so no
 * background cleanup process is needed.
 */

declare(strict_types=1);

class RateLimiter
{
    /** Maximum number of attempts allowed within the time window */
    private int $maxAttempts;

    /** Length of the sliding time window in seconds */
    private int $windowSeconds;

    /** Unique key that namespaces attempts (e.g. 'login', 'api_submit_invoice') */
    private string $key;

    /** Client IP address (resolved once per instance) */
    private string $ip;

    /** Directory used by the file backend */
    private string $cacheDir;

    /**
     * @param string $namespace    Logical name for the rate-limited action
     * @param int    $maxAttempts  Max allowed attempts in the window (default: 10)
     * @param int    $windowSeconds Sliding window size in seconds (default: 600 = 10 min)
     * @param string $cacheDir     Directory for file-based storage; defaults to
     *                             <app-root>/logs/rate_limit
     */
    public function __construct(
        string $namespace,
        int $maxAttempts = 10,
        int $windowSeconds = 600,
        string $cacheDir = ''
    ) {
        $this->maxAttempts   = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->ip            = self::resolveClientIp();
        $this->key           = $namespace;
        $this->cacheDir      = $cacheDir !== ''
            ? $cacheDir
            : dirname(__DIR__, 2) . '/logs/rate_limit';
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Returns true when the IP has exceeded the allowed attempt count.
     */
    public function tooManyAttempts(): bool
    {
        return $this->getAttempts() >= $this->maxAttempts;
    }

    /**
     * Returns the number of attempts recorded in the current window.
     */
    public function getAttempts(): int
    {
        $data = $this->read();
        return count($data['attempts'] ?? []);
    }

    /**
     * Returns the number of remaining attempts before the limit is hit.
     */
    public function remainingAttempts(): int
    {
        return max(0, $this->maxAttempts - $this->getAttempts());
    }

    /**
     * Records one attempt.  Old entries outside the time window are pruned
     * automatically so the count always reflects the sliding window.
     */
    public function hit(): void
    {
        $data = $this->read();
        $now  = time();

        // Prune expired entries
        $data['attempts'] = array_filter(
            $data['attempts'] ?? [],
            fn(int $ts) => ($now - $ts) < $this->windowSeconds
        );

        // Record new attempt
        $data['attempts'][] = $now;

        $this->write($data);
    }

    /**
     * Clears all recorded attempts for this IP + namespace combination.
     * Call this after a successful login / action.
     */
    public function clear(): void
    {
        $this->write(['attempts' => []]);
    }

    /**
     * Returns the number of seconds until the oldest attempt in the current
     * window expires.  Returns 0 when there are no recorded attempts.
     */
    public function availableIn(): int
    {
        $data = $this->read();
        if (empty($data['attempts'])) {
            return 0;
        }
        $oldest = min($data['attempts']);
        return max(0, (int) ($this->windowSeconds - (time() - $oldest)));
    }

    // -----------------------------------------------------------------------
    // Storage backend
    // -----------------------------------------------------------------------

    private function cacheKey(): string
    {
        // Hash the IP so it does not appear in file names / APCu keys in plain text
        return 'rl_' . $this->key . '_' . hash('sha256', $this->ip);
    }

    /** @return array{attempts: int[]} */
    private function read(): array
    {
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch($this->cacheKey(), $success);
            return ($success && is_array($data)) ? $data : ['attempts' => []];
        }

        return $this->readFile();
    }

    private function write(array $data): void
    {
        if (function_exists('apcu_store')) {
            apcu_store($this->cacheKey(), $data, $this->windowSeconds);
            return;
        }

        $this->writeFile($data);
    }

    /** @return array{attempts: int[]} */
    private function readFile(): array
    {
        $path = $this->filePath();
        if (!file_exists($path)) {
            return ['attempts' => []];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['attempts' => []];
        }

        $data = json_decode($raw, true);
        return (is_array($data) && isset($data['attempts'])) ? $data : ['attempts' => []];
    }

    private function writeFile(array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }

        $path = $this->filePath();
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }

    private function filePath(): string
    {
        return rtrim($this->cacheDir, '/') . '/' . $this->cacheKey() . '.json';
    }

    // -----------------------------------------------------------------------
    // IP resolution
    // -----------------------------------------------------------------------

    /**
     * Resolves the real client IP address, taking common proxy headers into
     * account while guarding against header spoofing.
     *
     * Only trusted proxy headers are read; the raw REMOTE_ADDR is always used
     * as the fallback so that a malicious client cannot spoof an unlimited IP
     * simply by adding an X-Forwarded-For header on a direct connection.
     */
    private static function resolveClientIp(): string
    {
        // List of trusted proxy headers in order of preference.
        // Adjust if your infrastructure uses a different header.
        $headers = [
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_REAL_IP',          // Nginx
            'HTTP_X_FORWARDED_FOR',
        ];

        // Only trust proxy headers when running behind a known proxy.
        // Set the TRUSTED_PROXY env/constant to enable this.
        $trustProxy = defined('TRUSTED_PROXY') && TRUSTED_PROXY;

        if ($trustProxy) {
            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    // X-Forwarded-For may contain a comma-separated list; take the first entry
                    $ip = trim(explode(',', $_SERVER[$header])[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

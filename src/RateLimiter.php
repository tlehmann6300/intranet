<?php

/**
 * RateLimiter
 *
 * Session-based rate limiter for protecting endpoints against abuse.
 *
 * This is a lightweight, zero-dependency implementation that stores
 * attempt counters in the PHP session.  For high-traffic scenarios a
 * shared cache (Redis / APCu) should be used instead.
 *
 * Usage example (in an API endpoint or controller):
 *
 *   $limiter = new RateLimiter('submit_invoice', maxAttempts: 5, decaySeconds: 60);
 *
 *   if ($limiter->tooManyAttempts()) {
 *       http_response_code(429);
 *       echo json_encode(['error' => 'Too many requests. Try again in ' . $limiter->availableIn() . ' seconds.']);
 *       exit;
 *   }
 *
 *   $limiter->hit();          // record attempt
 *   // … process request …
 *   $limiter->clear();        // reset after success (optional)
 */
class RateLimiter
{
    private string $key;
    private int    $maxAttempts;
    private int    $decaySeconds;

    /** Session key prefix */
    private const PREFIX = '_rl_';

    public function __construct(
        string $key,
        int    $maxAttempts  = 5,
        int    $decaySeconds = 60
    ) {
        $this->key          = $key;
        $this->maxAttempts  = $maxAttempts;
        $this->decaySeconds = $decaySeconds;

        $this->ensureSession();
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** Record one attempt. Returns the new total attempts count. */
    public function hit(): int
    {
        $this->purgeExpired();

        $sessionKey = $this->sessionKey();

        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = ['attempts' => 0, 'first_attempt' => time()];
        }

        $_SESSION[$sessionKey]['attempts']++;

        return (int) $_SESSION[$sessionKey]['attempts'];
    }

    /** Check whether the limit has been exceeded. */
    public function tooManyAttempts(): bool
    {
        $this->purgeExpired();
        return $this->attempts() >= $this->maxAttempts;
    }

    /** Number of recorded attempts within the current window. */
    public function attempts(): int
    {
        $this->purgeExpired();
        return (int) ($_SESSION[$this->sessionKey()]['attempts'] ?? 0);
    }

    /** Remaining attempts before the limit is hit. */
    public function remaining(): int
    {
        return max(0, $this->maxAttempts - $this->attempts());
    }

    /**
     * Seconds until the limit resets (0 if not currently throttled).
     */
    public function availableIn(): int
    {
        $data = $_SESSION[$this->sessionKey()] ?? null;
        if ($data === null) {
            return 0;
        }

        $elapsed = time() - (int) $data['first_attempt'];
        $wait    = $this->decaySeconds - $elapsed;

        return max(0, $wait);
    }

    /** Reset the counter for this key. */
    public function clear(): void
    {
        unset($_SESSION[$this->sessionKey()]);
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function sessionKey(): string
    {
        return self::PREFIX . $this->key;
    }

    private function purgeExpired(): void
    {
        $sessionKey = $this->sessionKey();

        if (!isset($_SESSION[$sessionKey])) {
            return;
        }

        $firstAttempt = (int) ($_SESSION[$sessionKey]['first_attempt'] ?? 0);

        if ((time() - $firstAttempt) >= $this->decaySeconds) {
            unset($_SESSION[$sessionKey]);
        }
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

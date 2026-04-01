<?php

namespace App\Controllers;

use Twig\Environment;

/**
 * BaseController
 *
 * Provides shared helper methods for all controllers:
 *  - Twig template rendering
 *  - JSON responses
 *  - Redirects
 *  - HTTP status code helpers
 */
abstract class BaseController
{
    public function __construct(protected Environment $twig)
    {
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * Render a Twig template and send it to the browser.
     *
     * @param string               $template Relative path inside templates/ (e.g. 'auth/login.html.twig')
     * @param array<string,mixed>  $context  Variables passed to the template
     */
    protected function render(string $template, array $context = []): void
    {
        echo $this->twig->render($template, $context);
    }

    // ------------------------------------------------------------------
    // Response helpers
    // ------------------------------------------------------------------

    /**
     * Send a JSON response and terminate execution.
     *
     * @param mixed $data       Any JSON-serialisable value
     * @param int   $statusCode HTTP status code (default: 200)
     */
    protected function json(mixed $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Redirect to a URL and terminate execution.
     *
     * @param string $url        Absolute URL or path
     * @param int    $statusCode HTTP redirect code (301 or 302)
     */
    protected function redirect(string $url, int $statusCode = 302): never
    {
        http_response_code($statusCode);
        header('Location: ' . $url);
        exit;
    }

    /**
     * Abort with an HTTP error code and optional message.
     */
    protected function abort(int $statusCode, string $message = ''): never
    {
        http_response_code($statusCode);
        if ($message !== '') {
            echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        exit;
    }

    // ------------------------------------------------------------------
    // Session helpers
    // ------------------------------------------------------------------

    protected function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    /** @return array{type:string,message:string}|null */
    protected function popFlash(): ?array
    {
        if (!isset($_SESSION['flash'])) {
            return null;
        }

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        $type    = array_key_first($flash);
        $message = $flash[$type] ?? '';

        return ['type' => (string) $type, 'message' => (string) $message];
    }

    // ------------------------------------------------------------------
    // Request helpers
    // ------------------------------------------------------------------

    protected function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    protected function isGet(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET';
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use Twig\Environment;

abstract class BaseController
{
    public function __construct(protected Environment $twig) {}

    protected function render(string $template, array $data = []): void
    {
        echo $this->twig->render($template, $data);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    protected function requireAuth(): void
    {
        if (!\Auth::check()) {
            $this->redirect(\BASE_URL . '/login');
        }
    }

    protected function validate(array $data, array $rules, array $messages = []): array
    {
        return \Validator::validate($data, $rules, $messages);
    }
}

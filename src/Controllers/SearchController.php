<?php

declare(strict_types=1);

namespace App\Controllers;

use Twig\Environment;

/**
 * SearchController
 *
 * Provides a unified full-text search across blog posts, events, projects and
 * inventory objects using MySQL's built-in FULLTEXT index.
 *
 * The search bar in the main navigation (layouts/main.twig) submits to GET /search.
 */
class SearchController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();

        $query   = trim($_GET['q'] ?? '');
        $results = [
            'blog'      => [],
            'events'    => [],
            'projects'  => [],
            'inventory' => [],
        ];
        $totalCount = 0;

        if (strlen($query) >= 2) {
            $results    = $this->search($query);
            $totalCount = array_sum(array_map('count', $results));
        }

        $this->render('search/index.twig', [
            'query'      => $query,
            'results'    => $results,
            'totalCount' => $totalCount,
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Run full-text search across four tables and return grouped results.
     *
     * @return array{blog: list<array<string,mixed>>, events: list<array<string,mixed>>, projects: list<array<string,mixed>>, inventory: list<array<string,mixed>>}
     */
    private function search(string $query): array
    {
        // Strip FULLTEXT boolean operators to prevent query manipulation
        $safeQuery = preg_replace('/[+\-><()\~*"@]/', ' ', $query);
        $safeQuery = trim(preg_replace('/\s+/', ' ', $safeQuery) ?? '');
        if ($safeQuery === '') {
            $safeQuery = $query; // fallback to original if sanitization removes everything
        }

        $ftQuery = $safeQuery . '*';
        $results = [
            'blog'      => [],
            'events'    => [],
            'projects'  => [],
            'inventory' => [],
        ];

        try {
            $db = \Database::getContentDB();

            // Blog posts
            $stmt = $db->prepare(
                "SELECT id, title, content, created_at
                 FROM blog_posts
                 WHERE MATCH(title, content) AGAINST (? IN BOOLEAN MODE)
                 ORDER BY MATCH(title, content) AGAINST (? IN BOOLEAN MODE) DESC
                 LIMIT 20"
            );
            $stmt->execute([$ftQuery, $ftQuery]);
            $results['blog'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception) {
            // FULLTEXT index may not exist; fall back to LIKE
            try {
                $db   = \Database::getContentDB();
                $like = '%' . $query . '%';
                $stmt = $db->prepare(
                    'SELECT id, title, content, created_at FROM blog_posts WHERE title LIKE ? OR content LIKE ? LIMIT 20'
                );
                $stmt->execute([$like, $like]);
                $results['blog'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                error_log('search blog fallback error: ' . $e->getMessage());
            }
        }

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT id, title, description, start_time, location, status
                 FROM events
                 WHERE MATCH(title, description) AGAINST (? IN BOOLEAN MODE)
                 ORDER BY MATCH(title, description) AGAINST (? IN BOOLEAN MODE) DESC
                 LIMIT 20"
            );
            $stmt->execute([$ftQuery, $ftQuery]);
            $results['events'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception) {
            try {
                $db   = \Database::getContentDB();
                $like = '%' . $query . '%';
                $stmt = $db->prepare(
                    'SELECT id, title, description, start_time, location, status FROM events WHERE title LIKE ? OR description LIKE ? LIMIT 20'
                );
                $stmt->execute([$like, $like]);
                $results['events'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                error_log('search events fallback error: ' . $e->getMessage());
            }
        }

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT id, name, description, status
                 FROM projects
                 WHERE MATCH(name, description) AGAINST (? IN BOOLEAN MODE)
                 ORDER BY MATCH(name, description) AGAINST (? IN BOOLEAN MODE) DESC
                 LIMIT 20"
            );
            $stmt->execute([$ftQuery, $ftQuery]);
            $results['projects'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception) {
            try {
                $db   = \Database::getContentDB();
                $like = '%' . $query . '%';
                $stmt = $db->prepare(
                    'SELECT id, name, description, status FROM projects WHERE name LIKE ? OR description LIKE ? LIMIT 20'
                );
                $stmt->execute([$like, $like]);
                $results['projects'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                error_log('search projects fallback error: ' . $e->getMessage());
            }
        }

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT id, name, description, category, total_quantity
                 FROM inventory_objects
                 WHERE MATCH(name, description) AGAINST (? IN BOOLEAN MODE)
                 ORDER BY MATCH(name, description) AGAINST (? IN BOOLEAN MODE) DESC
                 LIMIT 20"
            );
            $stmt->execute([$ftQuery, $ftQuery]);
            $results['inventory'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception) {
            try {
                $db   = \Database::getContentDB();
                $like = '%' . $query . '%';
                $stmt = $db->prepare(
                    'SELECT id, name, description, category, total_quantity FROM inventory_objects WHERE name LIKE ? OR description LIKE ? LIMIT 20'
                );
                $stmt->execute([$like, $like]);
                $results['inventory'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                error_log('search inventory fallback error: ' . $e->getMessage());
            }
        }

        return $results;
    }
}

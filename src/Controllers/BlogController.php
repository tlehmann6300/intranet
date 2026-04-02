<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class BlogController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userRole = $_SESSION['user_role'] ?? 'mitglied';
        $userId   = $user['id'];

        $filterCategory = $_GET['category'] ?? null;
        $page           = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage        = 10;
        $offset         = ($page - 1) * $perPage;

        $allPosts   = \BlogPost::getAll($perPage + 1, $offset, $filterCategory);
        $hasNextPage = count($allPosts) > $perPage;
        $posts      = array_slice($allPosts, 0, $perPage);

        $contentDb  = \Database::getContentDB();
        $postIds    = array_column($posts, 'id');
        $likedPostIds = [];
        if (!empty($postIds)) {
            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $userLikesStmt = $contentDb->prepare("SELECT post_id FROM blog_likes WHERE post_id IN ($placeholders) AND user_id = ?");
            $userLikesStmt->execute(array_merge($postIds, [$userId]));
            $likedPostIds = array_flip($userLikesStmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        foreach ($posts as &$post) {
            $likeStmt = $contentDb->prepare("SELECT COUNT(*) FROM blog_likes WHERE post_id = ?");
            $likeStmt->execute([$post['id']]);
            $post['like_count'] = $likeStmt->fetchColumn();
            $post['user_has_liked'] = isset($likedPostIds[$post['id']]);
            $commentStmt = $contentDb->prepare("SELECT COUNT(*) FROM blog_comments WHERE post_id = ?");
            $commentStmt->execute([$post['id']]);
            $post['comment_count'] = $commentStmt->fetchColumn();
        }

        $this->render('blog/index.twig', [
            'user'           => $user,
            'userRole'       => $userRole,
            'posts'          => $posts,
            'filterCategory' => $filterCategory,
            'page'           => $page,
            'hasNextPage'    => $hasNextPage,
        ]);
    }

    public function view(array $vars = []): void
    {
        $this->requireAuth();
        $user   = \Auth::user();
        $userId = $user['id'];

        $postId = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if (!$postId) {
            $_SESSION['error_message'] = 'Kein Beitrag angegeben.';
            $this->redirect(\BASE_URL . '/blog');
        }

        $post = \BlogPost::getById($postId);
        if (!$post) {
            $_SESSION['error_message'] = 'Beitrag nicht gefunden.';
            $this->redirect(\BASE_URL . '/blog');
        }

        $contentDb    = \Database::getContentDB();
        $likeStmt     = $contentDb->prepare("SELECT COUNT(*) FROM blog_likes WHERE post_id = ? AND user_id = ?");
        $likeStmt->execute([$postId, $userId]);
        $userHasLiked = $likeStmt->fetchColumn() > 0;

        $errors         = [];
        $successMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            if ($_POST['action'] === 'toggle_like') {
                $newLikeState = \BlogPost::toggleLike($postId, $userId);
                $post         = \BlogPost::getById($postId);
                $userHasLiked = $newLikeState;
                $successMessage = $newLikeState ? 'Beitrag geliked!' : 'Like entfernt.';
            } elseif ($_POST['action'] === 'add_comment') {
                $commentContent = trim($_POST['comment_content'] ?? '');
                if (empty($commentContent)) {
                    $errors[] = 'Bitte geben Sie einen Kommentar ein.';
                } elseif (strlen($commentContent) > 2000) {
                    $errors[] = 'Der Kommentar ist zu lang. Maximum: 2000 Zeichen.';
                }
                if (empty($errors)) {
                    try {
                        \BlogPost::addComment($postId, $userId, $commentContent);
                        $post           = \BlogPost::getById($postId);
                        $successMessage = 'Kommentar erfolgreich hinzugefügt!';
                    } catch (\Exception $e) {
                        $errors[] = 'Fehler beim Hinzufügen des Kommentars. Bitte versuchen Sie es erneut.';
                    }
                }
            }
        }

        $this->render('blog/view.twig', [
            'user'           => $user,
            'post'           => $post,
            'userHasLiked'   => $userHasLiked,
            'errors'         => $errors,
            'successMessage' => $successMessage,
            'csrfToken'      => \CSRFHandler::getToken(),
        ]);
    }

    public function edit(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userId   = $_SESSION['user_id'];
        $userRole = $_SESSION['user_role'] ?? 'mitglied';

        if (!\BlogPost::canAuth($userRole)) {
            $this->redirect(\BASE_URL . '/blog');
        }

        $postId = isset($_GET['id']) ? (int)$_GET['id'] : null;
        $post   = null;
        $isEdit = false;

        if ($postId) {
            $post = \BlogPost::getById($postId);
            if (!$post) {
                $_SESSION['error_message'] = 'Beitrag nicht gefunden.';
                $this->redirect(\BASE_URL . '/blog');
            }
            $isEdit = true;
        }

        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $title        = trim($_POST['title'] ?? '');
            $category     = trim($_POST['category'] ?? '');
            $content      = trim($_POST['content'] ?? '');
            $externalLink = trim($_POST['external_link'] ?? '');

            if (empty($title)) {
                $errors[] = 'Bitte geben Sie einen Titel ein.';
            }
            if (empty($category)) {
                $errors[] = 'Bitte wählen Sie eine Kategorie aus.';
            }
            if (empty($content)) {
                $errors[] = 'Bitte geben Sie einen Inhalt ein.';
            }

            $allowedCategories = ['Allgemein', 'IT', 'Marketing', 'Human Resources', 'Qualitätsmanagement', 'Akquise', 'Vorstand'];
            if (!empty($category) && !in_array($category, $allowedCategories)) {
                $errors[] = 'Ungültige Kategorie ausgewählt.';
            }
            if ($category === 'Vorstand' && !in_array($userRole, \Auth::BOARD_ROLES)) {
                $errors[] = 'Die Kategorie "Vorstand" darf nur von Vorstandsmitgliedern verwendet werden.';
            }
            if (!empty($externalLink) && !filter_var($externalLink, FILTER_VALIDATE_URL)) {
                $errors[] = 'Bitte geben Sie eine gültige URL für den externen Link ein.';
            }

            if (empty($errors)) {
                $data = [
                    'title'         => $title,
                    'category'      => $category,
                    'content'       => $content,
                    'external_link' => $externalLink ?: null,
                ];

                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $blogUploadDir = __DIR__ . '/../../uploads/blog/';
                    if (!is_dir($blogUploadDir)) {
                        mkdir($blogUploadDir, 0775, true);
                    }
                    $uploadResult = \SecureImageUpload::uploadImage($_FILES['image'], $blogUploadDir);
                    if ($uploadResult['success']) {
                        $data['image_path'] = $uploadResult['path'];
                    } else {
                        $errors[] = $uploadResult['error'];
                    }
                }

                if (empty($errors)) {
                    try {
                        if ($isEdit) {
                            \BlogPost::update($postId, $data, $userId);
                            $this->redirect(\BASE_URL . '/blog/view?id=' . $postId . '&success=1');
                        } else {
                            $newPostId = \BlogPost::create($data, $userId);
                            $this->redirect(\BASE_URL . '/blog/view?id=' . $newPostId . '&success=1');
                        }
                    } catch (\Exception $e) {
                        $errors[] = 'Fehler beim Speichern: ' . $e->getMessage();
                    }
                }
            }
        }

        $this->render('blog/edit.twig', [
            'user'     => $user,
            'userRole' => $userRole,
            'post'     => $post,
            'isEdit'   => $isEdit,
            'errors'   => $errors,
            'csrfToken' => \CSRFHandler::getToken(),
        ]);
    }
}

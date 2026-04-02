<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class InventoryApiController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function cartToggle(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        }

        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        $action = $input['action'] ?? 'toggle';
        $itemId = trim((string)($input['item_id'] ?? ''));

        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        $inCart = null;

        if ($action === 'toggle') {
            if (isset($_SESSION['cart'][$itemId])) {
                unset($_SESSION['cart'][$itemId]);
                $inCart = false;
            } else {
                $_SESSION['cart'][$itemId] = [
                    'id'       => $itemId,
                    'name'     => $input['item_name'] ?? '',
                    'imageSrc' => $input['image_src'] ?? null,
                    'pieces'   => (int)($input['pieces'] ?? 0),
                    'quantity' => max(1, (int)($input['quantity'] ?? 1)),
                ];
                $inCart = true;
            }
        } elseif ($action === 'set_qty') {
            $qty = (int)($input['quantity'] ?? 0);
            if ($qty <= 0) {
                unset($_SESSION['cart'][$itemId]);
                $inCart = false;
            } elseif (isset($_SESSION['cart'][$itemId])) {
                $_SESSION['cart'][$itemId]['quantity'] = $qty;
                $inCart = true;
            }
        } elseif ($action === 'remove') {
            unset($_SESSION['cart'][$itemId]);
            $inCart = false;
        } elseif ($action === 'clear') {
            $_SESSION['cart'] = [];
            $inCart = false;
        }

        $this->json([
            'success'    => true,
            'in_cart'    => $inCart,
            'cart_count' => count($_SESSION['cart']),
            'cart'       => array_values($_SESSION['cart']),
        ]);
    }

    public function inventoryRequest(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        }

        $action = $input['action'] ?? '';
        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        try {
            $evi = new \EasyVereinInventory();

            if ($action === 'check_availability') {
                $inventoryObjectId = $input['inventory_object_id'] ?? '';
                $startDate         = $input['start_date'] ?? '';
                $endDate           = $input['end_date'] ?? '';

                if (empty($inventoryObjectId) || empty($startDate) || empty($endDate)) {
                    throw new \Exception('Pflichtfelder fehlen: inventory_object_id, start_date, end_date');
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                    throw new \Exception('Ungültiges Datumsformat. Erwartet: YYYY-MM-DD');
                }
                if ($startDate > $endDate) {
                    throw new \Exception('Startdatum muss vor dem Enddatum liegen');
                }

                $availableQty = $evi->getAvailableQuantity($inventoryObjectId, $startDate, $endDate);
                $this->json(['success' => true, 'available_quantity' => $availableQty]);
            } elseif ($action === 'submit_request') {
                $userId = \Auth::getUserId();

                $items     = $input['items'] ?? [];
                $startDate = $input['start_date'] ?? '';
                $endDate   = $input['end_date'] ?? '';
                $purpose   = $input['purpose'] ?? '';

                if (empty($items) || empty($startDate) || empty($endDate) || empty($purpose)) {
                    throw new \Exception('Pflichtfelder fehlen');
                }

                $db = \Database::getContentDB();
                foreach ($items as $item) {
                    $stmt = $db->prepare("INSERT INTO inventory_requests (user_id, inventory_object_id, quantity, start_date, end_date, purpose, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
                    $stmt->execute([$userId, $item['id'], $item['quantity'], $startDate, $endDate, $purpose]);
                }

                $user       = \Auth::user();
                $emailBody  = '<h2>Neue Inventar-Anfrage</h2>';
                $emailBody .= '<p>Von: ' . htmlspecialchars($user['email']) . '</p>';
                $emailBody .= '<p>Zeitraum: ' . htmlspecialchars($startDate) . ' bis ' . htmlspecialchars($endDate) . '</p>';
                $emailBody .= '<p>Zweck: ' . htmlspecialchars($purpose) . '</p>';
                try {
                    \MailService::sendEmail(
                        defined('SMTP_FROM') ? \SMTP_FROM : '',
                        'Neue Inventar-Anfrage von ' . $user['email'],
                        $emailBody
                    );
                } catch (\Exception $e) {
                    error_log('inventoryRequest email failed: ' . $e->getMessage());
                }

                $_SESSION['cart'] = [];
                $this->json(['success' => true, 'message' => 'Anfrage erfolgreich eingereicht']);
            } else {
                throw new \Exception('Ungültige Aktion');
            }
        } catch (\Exception $e) {
            error_log('inventoryRequest: ' . $e->getMessage());
            http_response_code(400);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function rentalRequestAction(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        if (!\Auth::isBoard()) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        }

        $action    = $input['action'] ?? '';
        $requestId = (int)($input['request_id'] ?? 0);
        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        if ($requestId <= 0) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Anfrage-ID']);
        }

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare("SELECT * FROM inventory_requests WHERE id = ?");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();

            if (!$request) {
                http_response_code(404);
                $this->json(['success' => false, 'message' => 'Anfrage nicht gefunden']);
            }

            if ($action === 'approve') {
                $stmt = $db->prepare("UPDATE inventory_requests SET status = 'approved', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$requestId]);
                $this->json(['success' => true, 'message' => 'Anfrage genehmigt']);
            } elseif ($action === 'reject') {
                $stmt = $db->prepare("UPDATE inventory_requests SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$requestId]);
                $this->json(['success' => true, 'message' => 'Anfrage abgelehnt']);
            } elseif ($action === 'verify_return') {
                $stmt = $db->prepare("UPDATE inventory_requests SET status = 'returned', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$requestId]);
                $this->json(['success' => true, 'message' => 'Rückgabe bestätigt']);
            } elseif ($action === 'verify_rental_return') {
                $db2  = \Database::getInventoryDB();
                $stmt = $db2->prepare("DELETE FROM inventory_rentals WHERE id = ?");
                $stmt->execute([$requestId]);
                $this->json(['success' => true, 'message' => 'Mietartikel zurückgegeben']);
            } else {
                throw new \Exception('Ungültige Aktion');
            }
        } catch (\Exception $e) {
            error_log('rentalRequestAction: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Proxy for EasyVerein-hosted item images.
     *
     * Validates the requested URL against the easyverein.com domain, fetches
     * the image using the API token, and streams it to the client.  Auth is
     * enforced by the AuthMiddleware in the route definition.
     */
    public function easyvereinImage(array $vars = []): void
    {
        $url = $_GET['url'] ?? '';
        if (empty($url)) {
            http_response_code(403);
            exit;
        }

        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = strtolower($parsed['host']   ?? '');

        if (
            $scheme !== 'https' ||
            ($host !== 'easyverein.com' && !str_ends_with($host, '.easyverein.com'))
        ) {
            http_response_code(403);
            exit;
        }

        $token = defined('EASYVEREIN_API_TOKEN') ? \EASYVEREIN_API_TOKEN : ($_ENV['EASYVEREIN_API_TOKEN'] ?? '');
        if (empty($token)) {
            http_response_code(500);
            exit;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
        $data        = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $data !== false && str_starts_with((string) $contentType, 'image/')) {
            header('Content-Type: ' . $contentType);
            echo $data;
        } else {
            header('Location: ' . \BASE_URL . '/assets/img/ibc_logo_original.webp', true, 302);
        }
    }
}

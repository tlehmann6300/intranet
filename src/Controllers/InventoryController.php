<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class InventoryController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();

        $syncResult = $_SESSION['sync_result'] ?? null;
        unset($_SESSION['sync_result']);

        $search = trim($_GET['search'] ?? '');
        $inventoryObjects = [];
        $loadError = null;
        try {
            $filters = [];
            if ($search !== '') {
                $filters['search'] = $search;
            }
            $inventoryObjects = \Inventory::getAll($filters);
        } catch (\Exception $e) {
            $loadError = $e->getMessage();
            error_log('Inventory index: fetch failed: ' . $e->getMessage());
        }

        $checkoutSuccess = $_SESSION['checkout_success'] ?? null;
        $checkoutError   = $_SESSION['checkout_error']   ?? null;
        unset($_SESSION['checkout_success'], $_SESSION['checkout_error']);

        $this->render('inventory/index.twig', [
            'inventoryObjects' => $inventoryObjects,
            'search'           => $search,
            'syncResult'       => $syncResult,
            'checkoutSuccess'  => $checkoutSuccess,
            'checkoutError'    => $checkoutError,
            'loadError'        => $loadError,
        ]);
    }

    public function view(array $vars = []): void
    {
        $this->requireAuth();

        $itemId = $_GET['id'] ?? null;
        if (!$itemId) {
            $this->redirect(\BASE_URL . '/inventory');
        }

        $item = \Inventory::getById($itemId);
        if (!$item) {
            $this->redirect(\BASE_URL . '/inventory');
        }

        $message = '';
        $error   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_return'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            $rentalId = intval($_POST['rental_id'] ?? 0);
            $result   = \Inventory::requestReturn($rentalId);
            if ($result['success']) {
                $_SESSION['rental_success'] = $result['message'];
            } else {
                $_SESSION['rental_error'] = $result['message'];
            }
            $this->redirect(\BASE_URL . '/inventory/view?id=' . $itemId);
        }

        if (isset($_SESSION['checkout_success'])) {
            $message = $_SESSION['checkout_success'];
            unset($_SESSION['checkout_success']);
        }
        if (isset($_SESSION['rental_success'])) {
            $message = $_SESSION['rental_success'];
            unset($_SESSION['rental_success']);
        }
        if (isset($_SESSION['rental_error'])) {
            $error = $_SESSION['rental_error'];
            unset($_SESSION['rental_error']);
        }

        $syncResult = $_SESSION['sync_result'] ?? null;
        unset($_SESSION['sync_result']);

        $this->render('inventory/view.twig', [
            'item'       => $item,
            'message'    => $message,
            'error'      => $error,
            'syncResult' => $syncResult,
            'csrfToken'  => \CSRFHandler::getToken(),
        ]);
    }

    public function add(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::hasPermission('manager')) {
            $this->redirect(\BASE_URL . '/login');
        }

        $isAdmin = \Auth::isBoard();
        $message = '';
        $error   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            if (!$isAdmin) {
                $error = 'Neue Artikel müssen zuerst in EasyVerein erstellt und dann synchronisiert werden.';
            } else {
                $name        = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $category_id = $_POST['category_id'] ?? null;
                $location_id = $_POST['location_id'] ?? null;
                $quantity    = intval($_POST['current_stock'] ?? 0);
                $min_stock   = intval($_POST['min_stock'] ?? 0);
                $unit        = $_POST['unit'] ?? 'Stück';
                $unit_price  = floatval(str_replace(',', '.', $_POST['unit_price'] ?? '0'));
                $notes       = $_POST['notes'] ?? '';

                if (empty($name)) {
                    $error = 'Name ist erforderlich';
                } else {
                    $imagePath = null;
                    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $uploadResult = \SecureImageUpload::uploadImage($_FILES['image']);
                        if ($uploadResult['success']) {
                            $imagePath = $uploadResult['path'];
                        } else {
                            $error = $uploadResult['error'];
                        }
                    }

                    if (empty($error)) {
                        $data   = [
                            'name'        => $name,
                            'description' => $description,
                            'category_id' => $category_id,
                            'location_id' => $location_id,
                            'quantity'    => $quantity,
                            'min_stock'   => $min_stock,
                            'unit'        => $unit,
                            'unit_price'  => $unit_price,
                            'image_path'  => $imagePath,
                            'notes'       => $notes,
                        ];
                        $itemId = \Inventory::create($data, $_SESSION['user_id']);
                        if ($itemId) {
                            $this->redirect(\BASE_URL . '/inventory/view?id=' . $itemId);
                        } else {
                            $error = 'Fehler beim Erstellen des Artikels';
                        }
                    }
                }
            }
        }

        $categories = \Inventory::getCategories();
        $locations  = \Inventory::getLocations();

        $this->render('inventory/add.twig', [
            'isAdmin'    => $isAdmin,
            'message'    => $message,
            'error'      => $error,
            'categories' => $categories,
            'locations'  => $locations,
            'csrfToken'  => \CSRFHandler::getToken(),
        ]);
    }

    public function edit(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::hasPermission('manager')) {
            $this->redirect(\BASE_URL . '/login');
        }

        $itemId = $_GET['id'] ?? null;
        if (!$itemId) {
            $this->redirect(\BASE_URL . '/inventory');
        }

        $item = \Inventory::getById($itemId);
        if (!$item) {
            $this->redirect(\BASE_URL . '/inventory');
        }

        $isSyncedItem = !empty($item['easyverein_id']);
        $message      = '';
        $error        = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            if (isset($_POST['delete'])) {
                if (\Inventory::delete($itemId, $_SESSION['user_id'])) {
                    $this->redirect(\BASE_URL . '/inventory');
                } else {
                    $error = 'Fehler beim Löschen des Artikels';
                }
            } else {
                $name        = $_POST['name'] ?? '';
                $description = $_POST['description'] ?? '';
                $category_id = $_POST['category_id'] ?? null;
                $location_id = $_POST['location_id'] ?? null;
                $min_stock   = intval($_POST['min_stock'] ?? 0);
                $unit        = $_POST['unit'] ?? 'Stück';
                $unit_price  = floatval(str_replace(',', '.', $_POST['unit_price'] ?? '0'));
                $notes       = $_POST['notes'] ?? '';

                if ($isSyncedItem) {
                    $data = [
                        'location_id' => $location_id,
                        'min_stock'   => $min_stock,
                        'unit'        => $unit,
                        'unit_price'  => $unit_price,
                        'notes'       => $notes,
                    ];
                } else {
                    if (empty($name)) {
                        $error = 'Name ist erforderlich';
                    }
                    $data = [
                        'name'        => $name,
                        'description' => $description,
                        'category_id' => $category_id,
                        'location_id' => $location_id,
                        'min_stock'   => $min_stock,
                        'unit'        => $unit,
                        'unit_price'  => $unit_price,
                        'notes'       => $notes,
                    ];
                }

                if (empty($error)) {
                    $imagePath = $item['image_path'];
                    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $uploadResult = \SecureImageUpload::uploadImage($_FILES['image']);
                        if ($uploadResult['success']) {
                            if ($imagePath) {
                                \SecureImageUpload::deleteImage($imagePath);
                            }
                            $imagePath = $uploadResult['path'];
                        } else {
                            $error = $uploadResult['error'];
                        }
                    }
                    if (empty($error)) {
                        $data['image_path'] = $imagePath;
                        if (\Inventory::update($itemId, $data, $_SESSION['user_id'])) {
                            $message = 'Artikel erfolgreich aktualisiert';
                            $item    = \Inventory::getById($itemId);
                        } else {
                            $error = 'Fehler beim Aktualisieren';
                        }
                    }
                }
            }
        }

        $categories = \Inventory::getCategories();
        $locations  = \Inventory::getLocations();

        $this->render('inventory/edit.twig', [
            'item'         => $item,
            'isSyncedItem' => $isSyncedItem,
            'message'      => $message,
            'error'        => $error,
            'categories'   => $categories,
            'locations'    => $locations,
            'csrfToken'    => \CSRFHandler::getToken(),
        ]);
    }

    public function checkout(array $vars = []): void
    {
        $this->requireAuth();

        $itemId   = $_GET['id'] ?? null;
        $cartMode = !$itemId;

        $item = null;
        if (!$cartMode) {
            $item = \Inventory::getById($itemId);
            if (!$item) {
                $this->redirect(\BASE_URL . '/inventory');
            }
        }

        $cartItems = [];
        if ($cartMode) {
            $sessionCart = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : [];
            foreach ($sessionCart as $entry) {
                $eid = (string)($entry['id'] ?? '');
                if ($eid === '') {
                    continue;
                }
                $fullItem = \Inventory::getById($eid);
                if ($fullItem) {
                    $rawImg = $fullItem['image_path'] ?? null;
                    if ($rawImg && strpos($rawImg, 'easyverein.com') !== false) {
                        $imgSrc = \BASE_URL . '/api/inventory/easyverein-image?url=' . urlencode($rawImg);
                    } elseif ($rawImg) {
                        $imgSrc = '/' . ltrim($rawImg, '/');
                    } else {
                        $imgSrc = null;
                    }
                    $cartItems[] = [
                        'id'       => (string)$fullItem['id'],
                        'name'     => $fullItem['name'],
                        'imageSrc' => $imgSrc,
                        'pieces'   => (int)$fullItem['available_quantity'],
                        'quantity' => max(1, (int)($entry['quantity'] ?? 1)),
                        'unit'     => $fullItem['unit'] ?? 'Stück',
                    ];
                } else {
                    $cartItems[] = [
                        'id'       => $eid,
                        'name'     => $entry['name'] ?? 'Artikel',
                        'imageSrc' => $entry['imageSrc'] ?? null,
                        'pieces'   => (int)($entry['pieces'] ?? 0),
                        'quantity' => max(1, (int)($entry['quantity'] ?? 1)),
                        'unit'     => 'Stück',
                    ];
                }
            }
        }

        $message = '';
        $error   = '';

        if (!$cartMode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $returnTo    = ($_POST['return_to'] ?? '') === 'index' ? 'index' : 'view';
            $quantity    = intval($_POST['quantity'] ?? 0);
            $purpose     = trim($_POST['purpose'] ?? '');
            $destination = trim($_POST['destination'] ?? '');
            $startDate   = trim($_POST['start_date'] ?? '');
            $endDate     = trim($_POST['end_date'] ?? '') ?: null;

            $result = \Inventory::checkoutItem($itemId, $_SESSION['user_id'], $quantity, $purpose, $destination, $startDate, $endDate);
            if ($result['success']) {
                $_SESSION['checkout_success'] = $result['message'];
                $target = $returnTo === 'index' ? \BASE_URL . '/inventory' : \BASE_URL . '/inventory/view?id=' . $itemId;
                $this->redirect($target);
            } else {
                $error = $result['message'];
            }
        }

        $this->render('inventory/checkout.twig', [
            'item'      => $item,
            'cartMode'  => $cartMode,
            'cartItems' => $cartItems,
            'message'   => $message,
            'error'     => $error,
            'csrfToken' => \CSRFHandler::getToken(),
        ]);
    }

    public function checkin(array $vars = []): void
    {
        $this->requireAuth();

        $checkoutId = $_GET['id'] ?? null;
        if (!$checkoutId) {
            $this->redirect(\BASE_URL . '/inventory/my-checkouts');
        }

        $checkout = \Inventory::getCheckoutById($checkoutId);
        if (!$checkout || $checkout['actual_return'] !== null) {
            $this->redirect(\BASE_URL . '/inventory/my-checkouts');
        }

        $message = '';
        $error   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkin'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $returnedQuantity  = intval($_POST['returned_quantity'] ?? 0);
            $isDefective       = isset($_POST['is_defective']) && $_POST['is_defective'] === 'yes';
            $defectiveQuantity = $isDefective ? intval($_POST['defective_quantity'] ?? 0) : 0;
            $defectiveReason   = $isDefective ? trim($_POST['defective_reason'] ?? '') : null;

            if ($returnedQuantity <= 0 || $returnedQuantity > $checkout['amount']) {
                $error = 'Bitte geben Sie eine gültige Rückgabemenge ein';
            } elseif ($isDefective && $defectiveQuantity <= 0) {
                $error = 'Bitte geben Sie die defekte Menge ein';
            } elseif ($isDefective && empty($defectiveReason)) {
                $error = 'Bitte geben Sie einen Grund für den Defekt an';
            } else {
                $result = \Inventory::checkinItem($checkoutId, $returnedQuantity, $isDefective, $defectiveQuantity, $defectiveReason);
                if ($result['success']) {
                    $_SESSION['checkin_success'] = $result['message'];
                    $this->redirect(\BASE_URL . '/inventory/my-checkouts');
                } else {
                    $error = $result['message'];
                }
            }
        }

        $this->render('inventory/checkin.twig', [
            'checkout'  => $checkout,
            'message'   => $message,
            'error'     => $error,
            'csrfToken' => \CSRFHandler::getToken(),
        ]);
    }

    public function myCheckouts(array $vars = []): void
    {
        $this->requireAuth();

        try {
            $evi            = new \EasyVereinInventory();
            $activeCheckouts = $evi->getMyAssignedItems(\Auth::getUserId());
        } catch (\Exception $e) {
            error_log('EasyVereinInventory::getMyAssignedItems failed: ' . $e->getMessage());
            $activeCheckouts = [];
        }

        $successMessage = $_SESSION['rental_success'] ?? $_SESSION['checkin_success'] ?? null;
        unset($_SESSION['rental_success'], $_SESSION['checkin_success']);
        $errorMessage   = $_SESSION['rental_error'] ?? null;
        unset($_SESSION['rental_error']);

        $this->render('inventory/my_checkouts.twig', [
            'activeCheckouts' => $activeCheckouts,
            'successMessage'  => $successMessage,
            'errorMessage'    => $errorMessage,
        ]);
    }

    public function myRentals(array $vars = []): void
    {
        $this->requireAuth();

        $userId  = (int)\Auth::getUserId();
        $rentals = [];
        try {
            $dbInventory = \Database::getInventoryDB();
            $stmt        = $dbInventory->prepare(
                "SELECT r.id,
                        ii.easyverein_item_id,
                        1            AS quantity,
                        r.start_date AS rented_at,
                        r.end_date,
                        r.status,
                        r.created_at
                   FROM inventory_rentals r
                   JOIN inventory_items ii ON ii.id = r.item_id
                  WHERE r.user_id = ?
                    AND r.status IN ('pending', 'active', 'overdue')
                  ORDER BY r.created_at DESC"
            );
            $stmt->execute([$userId]);
            $rentals = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('my_rentals: inventory_rentals query failed: ' . $e->getMessage());
        }

        try {
            $dbContent = \Database::getContentDB();
            $stmt      = $dbContent->prepare(
                "SELECT id,
                        inventory_object_id AS easyverein_item_id,
                        quantity,
                        start_date          AS rented_at,
                        end_date,
                        status,
                        created_at
                   FROM inventory_requests
                  WHERE user_id = ?
                    AND status IN ('pending', 'approved')
                  ORDER BY created_at DESC"
            );
            $stmt->execute([$userId]);
            $contentRentals = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $rentals        = array_merge($rentals, $contentRentals);
        } catch (\Exception $e) {
            error_log('my_rentals: inventory_requests query failed: ' . $e->getMessage());
        }

        $this->render('inventory/my_rentals.twig', [
            'rentals' => $rentals,
        ]);
    }
}

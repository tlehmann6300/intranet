<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/services/EasyVereinInventory.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$message = '';
$error = '';

// Handle rental creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_rental'])) {
    $itemId         = intval($_POST['item_id'] ?? 0);
    $quantity       = intval($_POST['quantity'] ?? 0);
    $expectedReturn = $_POST['expected_return'] ?? '';
    $purpose        = trim($_POST['purpose'] ?? '');

    if ($itemId <= 0 || $quantity < 1) {
        $_SESSION['rental_error'] = 'Ungültige Artikel-ID oder Menge';
        header('Location: view.php?id=' . $itemId);
        exit;
    }

    if (empty($expectedReturn)) {
        $_SESSION['rental_error'] = 'Bitte geben Sie ein voraussichtliches Rückgabedatum an';
        header('Location: view.php?id=' . $itemId);
        exit;
    }

    // Get item info for the notification email
    $item = Inventory::getById($itemId);
    if (!$item) {
        $_SESSION['rental_error'] = 'Artikel nicht gefunden';
        header('Location: index.php');
        exit;
    }

    // Check available quantity using the Inventory Model
    $available = Inventory::getAvailableQuantity($itemId);
    if ($available < $quantity) {
        $_SESSION['rental_error'] = 'Nicht genügend Bestand verfügbar. Nur noch ' . $available . ' vorhanden.';
        header('Location: view.php?id=' . $itemId);
        exit;
    }

    try {
        $db = Database::getContentDB();

        // Create rental record with rented_quantity
        $stmt = $db->prepare(
            "INSERT INTO inventory_rentals (easyverein_item_id, user_id, rented_quantity, purpose, expected_return, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([(string)$itemId, (int)$_SESSION['user_id'], $quantity, $purpose, $expectedReturn]);

        // Send notification email to board
        $borrowerEmail = $_SESSION['user_email'] ?? 'Unbekannt';
        $safeSubject   = str_replace(["\r", "\n"], '', $item['name']);
        $emailBody = MailService::getTemplate(
            'Neue Ausleihe im Inventar',
            '<p class="email-text">Ein Mitglied hat einen Artikel aus dem Inventar ausgeliehen.</p>
            <table class="info-table">
                <tr><td>Artikel</td><td>' . htmlspecialchars($item['name']) . '</td></tr>
                <tr><td>Menge</td><td>' . htmlspecialchars($quantity . ' ' . ($item['unit'] ?? 'Stück')) . '</td></tr>
                <tr><td>Ausgeliehen von</td><td>' . htmlspecialchars($borrowerEmail) . '</td></tr>
                <tr><td>Verwendungszweck</td><td>' . htmlspecialchars($purpose) . '</td></tr>
                <tr><td>Rückgabe bis</td><td>' . htmlspecialchars(date('d.m.Y', strtotime($expectedReturn))) . '</td></tr>
                <tr><td>Datum</td><td>' . date('d.m.Y H:i') . '</td></tr>
            </table>'
        );
        MailService::sendEmail(MAIL_INVENTORY, 'Neue Ausleihe: ' . $safeSubject, $emailBody);

        $_SESSION['rental_success'] = 'Artikel erfolgreich ausgeliehen! Bitte geben Sie ihn bis zum ' . date('d.m.Y', strtotime($expectedReturn)) . ' zurück.';
        header('Location: view.php?id=' . $itemId);
        exit;

    } catch (Exception $e) {
        $_SESSION['rental_error'] = 'Fehler beim Ausleihen: ' . $e->getMessage();
        header('Location: view.php?id=' . $itemId);
        exit;
    }
}

// Handle "Rückgabe melden" – marks the local rental as pending_return for board approval.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_return'])) {
    $rentalId = intval($_POST['rental_id'] ?? 0);

    if ($rentalId <= 0) {
        $_SESSION['rental_error'] = 'Ungültige Ausleihe-ID';
        header('Location: my_rentals.php');
        exit;
    }

    // Verify the rental belongs to the current user.
    try {
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "SELECT id FROM inventory_rentals WHERE id = ? AND user_id = ? AND status = 'active'"
        );
        $stmt->execute([$rentalId, (int)$_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            $_SESSION['rental_error'] = 'Ausleihe nicht gefunden oder nicht berechtigt';
            header('Location: my_rentals.php');
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['rental_error'] = 'Datenbankfehler: ' . $e->getMessage();
        header('Location: my_rentals.php');
        exit;
    }

    $result = Inventory::requestReturn($rentalId);
    if ($result['success']) {
        $_SESSION['rental_success'] = 'Rückgabe gemeldet – wartet auf Bestätigung durch den Vorstand.';
    } else {
        $_SESSION['rental_error'] = $result['message'];
    }
    header('Location: my_rentals.php');
    exit;
}

// Handle early return request for approved inventory_requests (new board-approval workflow).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_return_approved'])) {
    $requestId = intval($_POST['request_id'] ?? 0);

    if ($requestId <= 0) {
        $_SESSION['rental_error'] = 'Ungültige Anfrage-ID';
        header('Location: my_rentals.php');
        exit;
    }

    try {
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "UPDATE inventory_requests SET status = 'pending_return' WHERE id = ? AND user_id = ? AND status = 'approved'"
        );
        $stmt->execute([$requestId, (int)Auth::getUserId()]);

        if ($stmt->rowCount() === 0) {
            $_SESSION['rental_error'] = 'Anfrage nicht gefunden, nicht berechtigt oder bereits in Bearbeitung';
        } else {
            $_SESSION['rental_success'] = 'Rückgabe gemeldet – wartet auf Bestätigung durch den Vorstand.';

            // Notify the board about the pending return
            try {
                $reqStmt = $db->prepare(
                    "SELECT inventory_object_id, quantity, end_date FROM inventory_requests WHERE id = ?"
                );
                $reqStmt->execute([$requestId]);
                $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);

                if ($reqData) {
                    $itemName = '#' . $reqData['inventory_object_id'];
                    try {
                        $item = Inventory::getById($reqData['inventory_object_id']);
                        if ($item) {
                            $itemName = $item['name'] ?? $itemName;
                        }
                    } catch (Exception $ex) {
                        // Use fallback item name
                    }

                    $borrowerEmail = $_SESSION['user_email'] ?? 'Unbekannt';
                    $safeItemName  = str_replace(["\r", "\n"], '', $itemName);
                    $endDate       = $reqData['end_date'] ?? '';

                    $emailBody = MailService::getTemplate(
                        'Rückgabe gemeldet',
                        '<p class="email-text">Ein Mitglied hat die Rückgabe eines Inventarartikels gemeldet und wartet auf Ihre Bestätigung.</p>
                        <table class="info-table">
                            <tr><td>Artikel</td><td>' . htmlspecialchars($itemName) . '</td></tr>
                            <tr><td>Menge</td><td>' . htmlspecialchars((string)$reqData['quantity']) . '</td></tr>
                            <tr><td>Zurückgegeben von</td><td>' . htmlspecialchars($borrowerEmail) . '</td></tr>
                            <tr><td>Ursprüngliches Rückgabedatum</td><td>' . htmlspecialchars($endDate ? date('d.m.Y', strtotime($endDate)) : '-') . '</td></tr>
                            <tr><td>Gemeldet am</td><td>' . date('d.m.Y H:i') . '</td></tr>
                        </table>'
                    );
                    MailService::sendEmail(
                        MAIL_INVENTORY,
                        'Rückgabe gemeldet: ' . $safeItemName,
                        $emailBody
                    );
                }
            } catch (Exception $mailEx) {
                error_log('rental.php: Fehler beim Senden der Rückgabe-Benachrichtigung: ' . $mailEx->getMessage());
            }
        }
    } catch (Exception $e) {
        $_SESSION['rental_error'] = 'Datenbankfehler: ' . $e->getMessage();
    }
    header('Location: my_rentals.php');
    exit;
}

// Handle rental return via EasyVerein API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_rental'])) {
    $itemId         = intval($_POST['rental_id'] ?? 0);
    $quantity       = max(1, intval($_POST['return_quantity'] ?? 1));
    $isDefective    = isset($_POST['is_defective']) && $_POST['is_defective'] === 'yes';
    $defectNotes    = $isDefective ? trim($_POST['defect_notes'] ?? '') : null;
    $returnLocation = trim($_POST['return_location'] ?? '');
    $returnComment  = trim($_POST['return_comment'] ?? '');

    if ($itemId <= 0) {
        $_SESSION['rental_error'] = 'Ungültige Artikel-ID';
        header('Location: my_rentals.php');
        exit;
    }

    if (empty($returnLocation)) {
        $_SESSION['rental_error'] = 'Bitte geben Sie den Ort der Rückgabe an';
        header('Location: my_rentals.php');
        exit;
    }

    try {
        $evi = new EasyVereinInventory();

        // Verify the item is currently assigned to the logged-in user
        $assigned = $evi->getMyAssignedItems(Auth::getUserId());
        $item     = null;
        foreach ($assigned as $candidate) {
            if ((int)($candidate['id'] ?? 0) === $itemId) {
                $item = $candidate;
                break;
            }
        }

        if (!$item) {
            $_SESSION['rental_error'] = 'Artikel nicht gefunden oder nicht dir zugewiesen';
            header('Location: my_rentals.php');
            exit;
        }

        // Return item via EasyVerein (writes timestamped log entry to the note field)
        $evi->returnItem($itemId, $quantity);

        // Send notification email to board
        $borrowerEmail = $_SESSION['user_email'] ?? 'Unbekannt';
        $itemName      = $item['name'] ?? ('Artikel #' . $itemId);
        $safeItemName  = str_replace(["\r", "\n"], '', $itemName);
        $emailBody = MailService::getTemplate(
            'Artikel zurückgegeben',
            '<p class="email-text">Ein Mitglied hat einen Artikel aus dem Inventar zurückgegeben.</p>
            <table class="info-table">
                <tr><td>Artikel</td><td>' . htmlspecialchars($itemName) . '</td></tr>
                <tr><td>Menge</td><td>' . htmlspecialchars((string)$quantity) . '</td></tr>
                <tr><td>Zurückgegeben von</td><td>' . htmlspecialchars($borrowerEmail) . '</td></tr>
                <tr><td>Ort der Rückgabe</td><td>' . htmlspecialchars($returnLocation) . '</td></tr>
                ' . ($returnComment ? '<tr><td>Kommentar</td><td>' . htmlspecialchars($returnComment) . '</td></tr>' : '') . '
                ' . ($defectNotes  ? '<tr><td>Defekt</td><td>'   . htmlspecialchars($defectNotes)   . '</td></tr>' : '') . '
                <tr><td>Datum</td><td>' . date('d.m.Y H:i') . '</td></tr>
            </table>'
        );
        $emailSent = MailService::sendEmail(MAIL_INVENTORY, 'Rückgabe: ' . $safeItemName, $emailBody);
        if (!$emailSent) {
            error_log('Inventory return notification email failed for item ID ' . $itemId);
        }

        $_SESSION['rental_success'] = 'Artikel erfolgreich zurückgegeben.';
        header('Location: my_rentals.php');
        exit;

    } catch (Exception $e) {
        $_SESSION['rental_error'] = 'Fehler beim Zurückgeben: ' . $e->getMessage();
        header('Location: my_rentals.php');
        exit;
    }
}

// If direct access, redirect to inventory
header('Location: index.php');
exit;

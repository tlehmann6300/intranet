<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/EventDocumentation.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Check if user has permission to view documentation (board and alumni_vorstand only)
$allowedDocRoles = array_merge(Auth::BOARD_ROLES, ['alumni_vorstand']);
if (!in_array($userRole, $allowedDocRoles)) {
    header('Location: index.php');
    exit;
}

// Get all event documentation with event titles
$allDocs = EventDocumentation::getAllWithEvents();

$title = 'Event-Statistiken - Historie';
ob_start();
?>

<style>
@keyframes est-fadeIn {
    from {
        opacity: 0;
        transform: translateY(16px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.est-container {
    max-width: 80rem;
    margin-left: auto;
    margin-right: auto;
}

.est-back-link {
    display: inline-flex;
    align-items: center;
    color: var(--ibc-blue);
    text-decoration: none;
    margin-bottom: 1.5rem;
    font-weight: 500;
    transition: color 0.3s ease;
}

.est-back-link:hover {
    color: var(--ibc-blue);
    opacity: 0.8;
}

.est-header {
    background: linear-gradient(to bottom right, #9333ea, #a855f7);
    box-shadow: var(--shadow-card);
    border-radius: 0.75rem;
    padding: 2rem;
    margin-bottom: 1.5rem;
    color: white;
    animation: est-fadeIn 0.5s ease;
}

.est-header h1 {
    font-size: clamp(1.5rem, 4vw, 2.25rem);
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.est-header p {
    opacity: 0.95;
    font-size: 1.125rem;
}

.est-empty-state {
    background-color: var(--bg-card);
    box-shadow: var(--shadow-card);
    border-radius: 0.75rem;
    padding: 3rem;
    text-align: center;
    animation: est-fadeIn 0.5s ease;
}

.est-empty-state i {
    font-size: 3.75rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
    opacity: 0.5;
}

.est-empty-state h3 {
    font-size: clamp(1rem, 3vw, 1.25rem);
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.est-empty-state p {
    color: var(--text-muted);
}

.est-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: clamp(0.75rem, 2vw, 1.5rem);
    margin-bottom: 1.5rem;
}

.est-stat-card {
    background-color: var(--bg-card);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1.5rem;
    animation: est-fadeIn 0.5s ease;
    transition: all 0.3s ease;
    min-height: 150px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.est-stat-card:hover {
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-4px);
}

.dark-mode .est-stat-card {
    background-color: var(--bg-card);
}

.est-stat-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.est-stat-number {
    font-size: clamp(1.5rem, 5vw, 2rem);
    font-weight: 700;
    color: var(--ibc-blue);
    margin-bottom: 1rem;
}

.est-stat-icon {
    font-size: 2.5rem;
    opacity: 0.15;
    text-align: right;
    color: var(--ibc-blue);
}

.est-events-list {
    display: flex;
    flex-direction: column;
    gap: clamp(0.75rem, 2vw, 1.5rem);
}

.est-event-card {
    background-color: var(--bg-card);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1.5rem;
    animation: est-fadeIn 0.5s ease;
}

.est-event-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    flex-wrap: wrap;
}

@media (max-width: 640px) {
    .est-event-header {
        flex-direction: column;
    }
}

.est-event-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.est-event-date {
    font-size: 0.875rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
}

.est-event-date i {
    margin-right: 0.5rem;
}

.est-button-primary {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    background-color: #9333ea;
    color: white;
    border-radius: 0.5rem;
    text-decoration: none;
    font-size: 0.875rem;
    transition: all 0.3s ease;
    min-height: 44px;
    border: none;
    cursor: pointer;
    font-weight: 500;
}

.est-button-primary:hover {
    background-color: #a855f7;
    text-decoration: none;
}

.est-button-primary i {
    margin-right: 0.5rem;
}

.est-section {
    margin-bottom: 1.5rem;
}

.est-section-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
}

.est-section-title i {
    margin-right: 0.5rem;
    color: var(--ibc-blue);
}

.est-table-wrapper {
    overflow-x: auto;
    border-radius: 0.5rem;
    border: 1px solid var(--border-color);
}

.est-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.est-table thead {
    background-color: var(--bg-body);
}

.dark-mode .est-table thead {
    background-color: rgba(255, 255, 255, 0.05);
}

.est-table th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-weight: 600;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-color);
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
}

.est-table td {
    padding: 0.75rem 1rem;
    color: var(--text-main);
    border-bottom: 1px solid var(--border-color);
}

.est-table tbody tr {
    transition: background-color 0.2s ease;
}

.est-table tbody tr:hover {
    background-color: var(--bg-body);
}

.dark-mode .est-table tbody tr:hover {
    background-color: rgba(255, 255, 255, 0.05);
}

.est-sales-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}

.est-sale-item {
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    padding: 1rem;
    transition: all 0.3s ease;
}

.dark-mode .est-sale-item {
    background-color: rgba(147, 51, 234, 0.1);
    border-color: rgba(147, 51, 234, 0.3);
}

.est-sale-item:hover {
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1);
}

.est-sale-label {
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.est-sale-amount {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--ibc-green);
    margin-bottom: 0.5rem;
}

.est-sale-date {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.est-calc-link {
    background-color: var(--bg-body);
    border-radius: 0.5rem;
    padding: 1rem;
    display: inline-block;
}

.dark-mode .est-calc-link {
    background-color: rgba(255, 255, 255, 0.05);
}

.est-calc-link a {
    display: inline-flex;
    align-items: center;
    color: var(--ibc-blue);
    text-decoration: none;
    font-weight: 500;
    transition: all 0.3s ease;
    word-break: break-all;
}

.est-calc-link a:hover {
    color: var(--ibc-blue);
    opacity: 0.8;
}

.est-calc-link i {
    margin-right: 0.5rem;
}

@media (max-width: 900px) {
    .est-stat-card {
        min-height: auto;
    }
}

@media (max-width: 640px) {
    .est-header {
        padding: 1.5rem;
    }

    .est-header h1 {
        font-size: 1.5rem;
    }

    .est-event-header {
        padding-bottom: 1rem;
        gap: 0.5rem;
    }

    .est-table th,
    .est-table td {
        padding: 0.5rem;
    }
}
</style>

<div class="est-container">
    <!-- Back Button -->
    <a href="index.php" class="est-back-link">
        <i class="fas fa-arrow-left" style="margin-right: 0.5rem;"></i>
        Zurück zur Übersicht
    </a>

    <!-- Page Header -->
    <div class="est-header">
        <h1>
            <i class="fas fa-chart-bar"></i>
            Event-Statistiken Historie
        </h1>
        <p>Übersicht aller Verkäufer und Statistiken vergangener Events</p>
    </div>

    <?php if (empty($allDocs)): ?>
        <div class="est-empty-state">
            <i class="fas fa-chart-line"></i>
            <h3>Noch keine Statistiken vorhanden</h3>
            <p>Erstellen Sie Event-Dokumentationen, um hier Statistiken zu sehen.</p>
        </div>
    <?php else: ?>
        <!-- Statistics Summary -->
        <div class="est-summary-grid">
            <?php
            $totalEvents = count($allDocs);
            $totalSellers = 0;
            $totalSales = 0;

            foreach ($allDocs as $doc) {
                if (!empty($doc['sellers_data'])) {
                    $totalSellers += count($doc['sellers_data']);
                }
                if (!empty($doc['sales_data'])) {
                    foreach ($doc['sales_data'] as $sale) {
                        $totalSales += floatval($sale['amount'] ?? 0);
                    }
                }
            }
            ?>

            <div class="est-stat-card">
                <div>
                    <div class="est-stat-label">Events dokumentiert</div>
                    <div class="est-stat-number"><?php echo $totalEvents; ?></div>
                </div>
                <div class="est-stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
            </div>

            <div class="est-stat-card">
                <div>
                    <div class="est-stat-label">Verkäufer-Einträge</div>
                    <div class="est-stat-number"><?php echo $totalSellers; ?></div>
                </div>
                <div class="est-stat-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
            </div>

            <div class="est-stat-card">
                <div>
                    <div class="est-stat-label">Gesamtumsatz</div>
                    <div class="est-stat-number" style="color: var(--ibc-green);"><?php echo number_format($totalSales, 2, ',', '.'); ?>€</div>
                </div>
                <div class="est-stat-icon" style="color: var(--ibc-green);">
                    <i class="fas fa-euro-sign"></i>
                </div>
            </div>
        </div>

        <!-- Events List with Statistics -->
        <div class="est-events-list">
            <?php foreach ($allDocs as $doc): ?>
                <div class="est-event-card">
                    <!-- Event Header -->
                    <div class="est-event-header">
                        <div>
                            <h2 class="est-event-title">
                                <?php echo htmlspecialchars($doc['event_title']); ?>
                            </h2>
                            <p class="est-event-date">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('d.m.Y', strtotime($doc['start_time'])); ?>
                            </p>
                        </div>
                        <a href="view.php?id=<?php echo $doc['event_id']; ?>" class="est-button-primary">
                            <i class="fas fa-eye"></i>
                            Event ansehen
                        </a>
                    </div>

                    <!-- Sellers Data -->
                    <?php if (!empty($doc['sellers_data'])): ?>
                        <div class="est-section">
                            <h3 class="est-section-title">
                                <i class="fas fa-user-tie"></i>
                                Verkäufer
                            </h3>
                            <div class="est-table-wrapper">
                                <table class="est-table">
                                    <thead>
                                        <tr>
                                            <th>Verkäufer/Stand</th>
                                            <th>Artikel</th>
                                            <th>Menge</th>
                                            <th>Umsatz</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($doc['sellers_data'] as $seller): ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($seller['seller_name'] ?? '-'); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($seller['items'] ?? '-'); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($seller['quantity'] ?? '-'); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($seller['revenue'] ?? '-'); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Sales Data -->
                    <?php if (!empty($doc['sales_data'])): ?>
                        <div class="est-section">
                            <h3 class="est-section-title">
                                <i class="fas fa-chart-line"></i>
                                Verkaufsdaten
                            </h3>
                            <div class="est-sales-grid">
                                <?php foreach ($doc['sales_data'] as $sale): ?>
                                    <div class="est-sale-item">
                                        <p class="est-sale-label"><?php echo htmlspecialchars($sale['label'] ?? 'Unbenannt'); ?></p>
                                        <p class="est-sale-amount">
                                            <?php echo number_format(floatval($sale['amount'] ?? 0), 2, ',', '.'); ?>€
                                        </p>
                                        <?php if (!empty($sale['date'])): ?>
                                            <p class="est-sale-date">
                                                <?php echo date('d.m.Y', strtotime($sale['date'])); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Calculations Link -->
                    <?php if (!empty($doc['calculation_link'])): ?>
                        <div class="est-section">
                            <h3 class="est-section-title">
                                <i class="fas fa-calculator"></i>
                                Kalkulationen
                            </h3>
                            <div class="est-calc-link">
                                <a href="<?php echo htmlspecialchars($doc['calculation_link']); ?>"
                                   target="_blank" rel="noopener noreferrer">
                                    <i class="fas fa-external-link-alt"></i>
                                    <?php echo htmlspecialchars($doc['calculation_link']); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>

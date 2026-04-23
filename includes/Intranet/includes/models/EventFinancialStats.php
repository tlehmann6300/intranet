<?php
/**
 * EventFinancialStats Model
 * Manages financial statistics for events (sales and calculations)
 * Enables historical comparison across years
 */

require_once __DIR__ . '/../../src/Database.php';

class EventFinancialStats {
    
    /**
     * Get all financial stats for an event
     * 
     * @param int $eventId Event ID
     * @param string|null $category Optional category filter ('Verkauf' or 'Kalkulation')
     * @param int|null $year Optional year filter
     * @return array Array of financial stats
     */
    public static function getByEventId($eventId, $category = null, $year = null) {
        $db = Database::getContentDB();
        
        $sql = "SELECT * FROM event_financial_stats WHERE event_id = ?";
        $params = [$eventId];
        
        if ($category !== null) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        if ($year !== null) {
            $sql .= " AND record_year = ?";
            $params[] = $year;
        }
        
        $sql .= " ORDER BY record_year DESC, category ASC, item_name ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get financial stats grouped by year for comparison
     * 
     * @param int $eventId Event ID
     * @param string|null $category Optional category filter
     * @return array Array grouped by year and item
     */
    public static function getYearlyComparison($eventId, $category = null) {
        $db = Database::getContentDB();
        
        $sql = "
            SELECT 
                item_name,
                category,
                record_year,
                SUM(quantity) as total_quantity,
                SUM(revenue) as total_revenue,
                COUNT(*) as entry_count
            FROM event_financial_stats 
            WHERE event_id = ? AND category != 'Spenden'
        ";
        $params = [$eventId];
        
        if ($category !== null) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        $sql .= " GROUP BY item_name, category, record_year ORDER BY item_name ASC, record_year DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get available years for an event
     * 
     * @param int $eventId Event ID
     * @return array Array of years
     */
    public static function getAvailableYears($eventId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT DISTINCT record_year 
            FROM event_financial_stats 
            WHERE event_id = ? AND category != 'Spenden'
            ORDER BY record_year DESC
        ");
        $stmt->execute([$eventId]);
        
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'record_year');
    }
    
    /**
     * Create a new financial stat entry
     * 
     * @param int $eventId Event ID
     * @param string $category Category ('Verkauf' or 'Kalkulation')
     * @param string $itemName Item name
     * @param int $quantity Quantity
     * @param float|null $revenue Revenue (optional)
     * @param int $recordYear Year
     * @param int $createdBy User ID
     * @return bool Success status
     */
    public static function create($eventId, $category, $itemName, $quantity, $revenue, $recordYear, $createdBy) {
        // Validation
        if (!in_array($category, ['Verkauf', 'Kalkulation'])) {
            throw new InvalidArgumentException('Invalid category. Must be "Verkauf" or "Kalkulation"');
        }
        
        if ($quantity < 0) {
            throw new InvalidArgumentException('Quantity cannot be negative');
        }
        
        if ($revenue !== null && $revenue < 0) {
            throw new InvalidArgumentException('Revenue cannot be negative');
        }
        
        if (empty($itemName)) {
            throw new InvalidArgumentException('Item name cannot be empty');
        }
        
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            INSERT INTO event_financial_stats 
            (event_id, category, item_name, quantity, revenue, record_year, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $eventId,
            $category,
            $itemName,
            $quantity,
            $revenue,
            $recordYear,
            $createdBy
        ]);
    }
    
    /**
     * Update a financial stat entry
     * 
     * @param int $id Entry ID
     * @param string $itemName Item name
     * @param int $quantity Quantity
     * @param float|null $revenue Revenue (optional)
     * @return bool Success status
     */
    public static function update($id, $itemName, $quantity, $revenue) {
        // Validation
        if ($quantity < 0) {
            throw new InvalidArgumentException('Quantity cannot be negative');
        }
        
        if ($revenue !== null && $revenue < 0) {
            throw new InvalidArgumentException('Revenue cannot be negative');
        }
        
        if (empty($itemName)) {
            throw new InvalidArgumentException('Item name cannot be empty');
        }
        
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            UPDATE event_financial_stats 
            SET item_name = ?, quantity = ?, revenue = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([$itemName, $quantity, $revenue, $id]);
    }
    
    /**
     * Delete a financial stat entry
     * 
     * @param int $id Entry ID
     * @return bool Success status
     */
    public static function delete($id) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("DELETE FROM event_financial_stats WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Save (insert or replace) the donations total for an event.
     *
     * Stores the value in a dedicated 'Spenden' row so it is kept separate
     * from the item-based Verkauf/Kalkulation rows.
     *
     * SQL to add the required column (run once on existing installations):
     *   ALTER TABLE event_financial_stats
     *     MODIFY COLUMN category ENUM('Verkauf','Kalkulation','Spenden') NOT NULL,
     *     ADD COLUMN donations_total DECIMAL(10,2) DEFAULT NULL AFTER revenue;
     *
     * @param int $eventId Event ID
     * @param float $amount Donation total in EUR
     * @param int $createdBy User ID
     * @return bool Success status
     */
    public static function saveDonationsTotal($eventId, $amount, $createdBy) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Donations total cannot be negative');
        }

        $db = Database::getContentDB();

        // Remove any existing donations row for this event first
        $del = $db->prepare("DELETE FROM event_financial_stats WHERE event_id = ? AND category = 'Spenden'");
        $del->execute([$eventId]);

        $stmt = $db->prepare("
            INSERT INTO event_financial_stats
                (event_id, category, item_name, quantity, revenue, donations_total, record_year, created_by)
            VALUES (?, 'Spenden', 'Gesamt', 0, NULL, ?, YEAR(NOW()), ?)
        ");

        return $stmt->execute([$eventId, $amount, $createdBy]);
    }

    /**
     * Get the donations total for an event.
     *
     * @param int $eventId Event ID
     * @return float|null Donation total or null if not set
     */
    public static function getDonationsTotal($eventId) {
        $db = Database::getContentDB();

        try {
            $stmt = $db->prepare("
                SELECT donations_total
                FROM event_financial_stats
                WHERE event_id = ? AND category = 'Spenden'
                LIMIT 1
            ");
            $stmt->execute([$eventId]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['donations_total'] : null;
        } catch (PDOException $e) {
            // Gracefully handle missing column (SQLSTATE 42S22) when migration hasn't run yet
            if (strpos($e->getCode(), '42S22') !== false || strpos($e->getMessage(), 'donations_total') !== false) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Get totals by category and year
     * 
     * @param int $eventId Event ID
     * @param string $category Category filter
     * @param int $year Year filter
     * @return array Totals
     */
    public static function getTotals($eventId, $category, $year) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT 
                SUM(quantity) as total_quantity,
                SUM(revenue) as total_revenue,
                COUNT(*) as entry_count
            FROM event_financial_stats 
            WHERE event_id = ? AND category = ? AND record_year = ?
        ");
        $stmt->execute([$eventId, $category, $year]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

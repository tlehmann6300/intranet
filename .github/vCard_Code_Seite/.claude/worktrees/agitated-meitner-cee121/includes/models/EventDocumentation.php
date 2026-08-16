<?php
/**
 * EventDocumentation Model
 * Manages event documentation for board and alumni_board members
 */

require_once __DIR__ . '/../../src/Database.php';

class EventDocumentation {
    
    /**
     * Get documentation for an event
     * 
     * @param int $eventId Event ID
     * @return array|null Documentation data or null if not found
     */
    public static function getByEventId($eventId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT * FROM event_documentation 
            WHERE event_id = ?
        ");
        $stmt->execute([$eventId]);
        
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            // Decode JSON sales data
            if (!empty($doc['sales_data'])) {
                $doc['sales_data'] = json_decode($doc['sales_data'], true);
            }
            // Decode JSON sellers data
            if (!empty($doc['sellers_data'])) {
                $doc['sellers_data'] = json_decode($doc['sellers_data'], true);
            }
        }
        
        return $doc ?: null;
    }
    
    /**
     * Save or update the calculation link for an event
     *
     * @param int $eventId Event ID
     * @param string $calculationLink URL or empty string to clear
     * @param int $userId User ID making the update
     * @return bool Success status
     */
    public static function saveCalculationLink($eventId, $calculationLink, $userId) {
        $db = Database::getContentDB();
        $link = trim($calculationLink ?? '');
        
        $existing = self::getByEventId($eventId);
        
        if ($existing) {
            $stmt = $db->prepare("
                UPDATE event_documentation 
                SET calculation_link = ?, updated_by = ?
                WHERE event_id = ?
            ");
            return $stmt->execute([$link ?: null, $userId, $eventId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO event_documentation (event_id, calculation_link, created_by, updated_by)
                VALUES (?, ?, ?, ?)
            ");
            return $stmt->execute([$eventId, $link ?: null, $userId, $userId]);
        }
    }
    
    /**
     * Save or update documentation for an event
     * 
     * @param int $eventId Event ID
     * @param array $salesData Array of sales entries
     * @param array $sellersData Array of seller entries
     * @param int $userId User ID making the update
     * @return bool Success status
     */
    public static function save($eventId, $salesData, $sellersData, $userId) {
        $db = Database::getContentDB();
        
        // Encode sales data and sellers data as JSON
        $salesDataJson = json_encode($salesData);
        $sellersDataJson = json_encode($sellersData);
        
        // Check if documentation exists
        $existing = self::getByEventId($eventId);
        
        if ($existing) {
            // Update existing
            $stmt = $db->prepare("
                UPDATE event_documentation 
                SET sales_data = ?, sellers_data = ?, updated_by = ?
                WHERE event_id = ?
            ");
            return $stmt->execute([$salesDataJson, $sellersDataJson, $userId, $eventId]);
        } else {
            // Insert new
            $stmt = $db->prepare("
                INSERT INTO event_documentation (event_id, sales_data, sellers_data, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            return $stmt->execute([$eventId, $salesDataJson, $sellersDataJson, $userId, $userId]);
        }
    }
    
    /**
     * Save or update the total costs for an event
     *
     * @param int $eventId Event ID
     * @param float|null $totalCosts Total costs in EUR, or null to clear
     * @param int $userId User ID making the update
     * @return bool Success status
     */
    public static function saveTotalCosts($eventId, $totalCosts, $userId) {
        $db = Database::getContentDB();
        
        $existing = self::getByEventId($eventId);
        
        if ($existing) {
            $stmt = $db->prepare("
                UPDATE event_documentation 
                SET total_costs = ?, updated_by = ?
                WHERE event_id = ?
            ");
            return $stmt->execute([$totalCosts, $userId, $eventId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO event_documentation (event_id, total_costs, created_by, updated_by)
                VALUES (?, ?, ?, ?)
            ");
            return $stmt->execute([$eventId, $totalCosts, $userId, $userId]);
        }
    }
    
    /**
     * Get financial summary for all events with costs and sales for dashboard comparison
     *
     * @param int $limit Maximum number of events to return
     * @return array Array of events with costs and sales totals
     */
    public static function getFinancialSummary($limit = 20) {
        $db = Database::getContentDB();
        
        try {
            $stmt = $db->prepare("
                SELECT e.id, e.title, e.start_time,
                       ed.total_costs,
                       ed.sales_data,
                       ed.calculation_link
                FROM events e
                LEFT JOIN event_documentation ed ON ed.event_id = e.id
                ORDER BY e.start_time DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        } catch (PDOException $e) {
            // If total_costs column doesn't exist yet, return empty array gracefully
            // Run update_database_schema.php to add the missing column
            error_log("EventDocumentation::getFinancialSummary error: " . $e->getMessage());
            return [];
        }
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($rows as &$row) {
            // Sum sales_data amounts
            $salesTotal = 0;
            if (!empty($row['sales_data'])) {
                $salesData = json_decode($row['sales_data'], true);
                if (is_array($salesData)) {
                    foreach ($salesData as $sale) {
                        $salesTotal += floatval($sale['amount'] ?? 0);
                    }
                }
            }
            $row['sales_total'] = $salesTotal;
            unset($row['sales_data']);
        }
        
        return $rows;
    }
    
    /**
     * Get all event documentation for history view
     * 
     * @return array Array of documentation entries with event titles
     */
    public static function getAllWithEvents() {
        $db = Database::getContentDB();
        
        $stmt = $db->query("
            SELECT ed.*, e.title as event_title, e.start_time, e.end_time
            FROM event_documentation ed
            INNER JOIN events e ON ed.event_id = e.id
            ORDER BY e.start_time DESC
        ");
        
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($docs as &$doc) {
            // Decode JSON data
            if (!empty($doc['sales_data'])) {
                $doc['sales_data'] = json_decode($doc['sales_data'], true);
            }
            if (!empty($doc['sellers_data'])) {
                $doc['sellers_data'] = json_decode($doc['sellers_data'], true);
            }
        }
        
        return $docs;
    }
}

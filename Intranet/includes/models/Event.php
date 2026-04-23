<?php
/**
 * Event Model
 * Manages events, helper slots, signups, and locking mechanism
 */

require_once __DIR__ . '/../utils/SecureImageUpload.php';
require_once __DIR__ . '/../../src/MailService.php';

class Event {
    
    // Lock timeout in seconds (15 minutes)
    const LOCK_TIMEOUT = 900;
    
    // Fields to exclude from update operations
    const EXCLUDED_UPDATE_FIELDS = ['id', 'allowed_roles', 'helper_types', 'status'];

    // Default image used when no event image has been uploaded
    const DEFAULT_IMAGE = 'assets/img/ibc_logo_original.webp';

    /**
     * Get the image URL for an event, falling back to the default image if none is set.
     *
     * @param string|null $imagePath Stored image path from the database
     * @return string URL-ready image path
     */
    public static function getImageUrl(?string $imagePath): string {
        return !empty($imagePath) ? $imagePath : self::DEFAULT_IMAGE;
    }
    
    /**
     * Calculate event status based on current time and event dates
     * 
     * @param array $data Event data with timestamps
     * @return string Status: 'planned', 'open', 'closed', 'running', or 'past'
     */
    private static function calculateStatus($data) {
        try {
            // Use Berlin timezone for consistent time handling
            $timezone = new DateTimeZone('Europe/Berlin');
            $now = new DateTime('now', $timezone);
            
            // Parse timestamps relative to Berlin timezone
            $registrationStart = null;
            $registrationEnd = null;
            $startTime = null;
            $endTime = null;
            
            if (!empty($data['registration_start'])) {
                $registrationStart = new DateTime($data['registration_start'], $timezone);
            }
            if (!empty($data['registration_end'])) {
                $registrationEnd = new DateTime($data['registration_end'], $timezone);
            }
            if (!empty($data['start_time'])) {
                $startTime = new DateTime($data['start_time'], $timezone);
            }
            if (!empty($data['end_time'])) {
                $endTime = new DateTime($data['end_time'], $timezone);
            }
            
            // Validate that we have the required dates
            if ($startTime === null || $endTime === null) {
                return 'planned';
            }
            
            // Status logic based on timestamps
            // 1. If event has ended -> past
            if ($now > $endTime) {
                return 'past';
            }
            
            // 2. If event is running -> running
            if ($now >= $startTime && $now <= $endTime) {
                return 'running';
            }
            
            // 3. If registration dates are set, use them
            if ($registrationStart !== null && $registrationEnd !== null) {
                // Before registration starts -> planned
                if ($now < $registrationStart) {
                    return 'planned';
                }
                
                // During registration period -> open
                if ($now >= $registrationStart && $now <= $registrationEnd) {
                    return 'open';
                }
                
                // After registration ends but before event starts -> closed
                if ($now > $registrationEnd && $now < $startTime) {
                    return 'closed';
                }
            } else {
                // No registration dates: if event hasn't started yet -> open
                if ($now < $startTime) {
                    return 'open';
                }
            }
            
            // Default fallback
            return 'planned';
        } catch (Throwable $e) {
            // Error resilience: Log error and fall back to 'planned'
            // Catches both Exception and PHP 8.3+ DateMalformedStringException
            error_log("calculateStatus failed: " . $e->getMessage());
            return 'planned';
        }
    }
    
    /**
     * Update event status in database if it differs from calculated status
     * 
     * OPTIMIZATION: This method minimizes database writes during read operations by:
     * 1. Comparing the current status (from database) with the calculated status
     * 2. Only executing a database UPDATE when the status has actually changed
     * 3. Avoiding unnecessary write operations when status is already correct
     * 
     * This lazy-update pattern ensures efficient read operations while keeping
     * status data in sync with event timestamps.
     * 
     * @param array $event Event data fetched directly from database (must contain 'status' field)
     * @param PDO $db Database connection
     * @return array Updated event data with correct status
     */
    private static function updateEventStatusIfNeeded($event, $db) {
        // Get current status from database object
        // This is the actual value stored in the database at query time
        $currentStatus = $event['status'];
        
        // Draft events are not auto-updated by time-based logic
        if ($currentStatus === 'draft') {
            return $event;
        }
        
        // Calculate what the status should be based on event timestamps
        $calculatedStatus = self::calculateStatus($event);
        
        // OPTIMIZATION: Only update if status has changed
        // Using strict comparison (!==) ensures type-safe comparison
        if ($currentStatus !== $calculatedStatus) {
            // Status has changed - update database
            $updateStmt = $db->prepare("UPDATE events SET status = ? WHERE id = ?");
            $updateStmt->execute([$calculatedStatus, $event['id']]);
            
            // Update the event array to reflect the new status
            $event['status'] = $calculatedStatus;
        }
        // If statuses match, no database write occurs (optimization)
        
        return $event;
    }
    
    /**
     * Validate event data for logical consistency
     * 
     * @param array $data Event data to validate
     * @throws Exception If validation fails
     */
    private static function validateEventData($data) {
        $timezone = new DateTimeZone('Europe/Berlin');
        
        // Validate that end_time > start_time
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            try {
                $startTime = new DateTime($data['start_time'], $timezone);
                $endTime = new DateTime($data['end_time'], $timezone);
                
                if ($endTime <= $startTime) {
                    throw new Exception("Event end time must be after start time");
                }
            } catch (Throwable $e) {
                // Re-throw validation errors, convert date parsing errors
                if (strpos($e->getMessage(), 'end time must be after') !== false) {
                    throw $e;
                }
                throw new Exception("Invalid date format for start_time or end_time");
            }
        }
        
        // Validate that registration_end < end_time
        if (!empty($data['registration_end']) && !empty($data['end_time'])) {
            try {
                $registrationEnd = new DateTime($data['registration_end'], $timezone);
                $endTime = new DateTime($data['end_time'], $timezone);
                
                if ($registrationEnd >= $endTime) {
                    throw new Exception("Registration end time must be before event end time");
                }
            } catch (Throwable $e) {
                // Re-throw validation errors, convert date parsing errors
                if (strpos($e->getMessage(), 'Registration end time') !== false) {
                    throw $e;
                }
                throw new Exception("Invalid date format for registration_end or end_time");
            }
        }
        
        // Validate maps_link if provided
        if (!empty($data['maps_link'])) {
            $mapsLink = trim($data['maps_link']);
            if ($mapsLink !== '' && filter_var($mapsLink, FILTER_VALIDATE_URL) === false) {
                throw new Exception("Maps link must be a valid URL");
            }
        }
    }
    
    /**
     * Create new event
     */
    public static function create($data, $userId, $files = []) {
        $db = Database::getContentDB();
        
        // Validate event data
        self::validateEventData($data);
        
        // Begin transaction
        $db->beginTransaction();
        
        try {
            // Handle image upload
            // Note: If image upload fails, event creation continues with null image_path.
            // This allows events to be created even if the image upload fails,
            // as the image is optional. The error is logged for debugging.
            $imagePath = null;
            if (isset($files['event_image']) && $files['event_image']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = SecureImageUpload::uploadImage($files['event_image']);
                if ($uploadResult['success']) {
                    $imagePath = $uploadResult['path'];
                } else {
                    error_log("Failed to upload event image: " . $uploadResult['error']);
                }
            }
            
            // If no file was uploaded, check if image_path is provided in $data
            // Only accept non-empty strings as valid image paths
            if ($imagePath === null && isset($data['image_path']) && $data['image_path'] !== '') {
                $imagePath = $data['image_path'];
            }
            
            // Calculate status: use 'draft' if explicitly set, otherwise auto-calculate
            $calculatedStatus = ($data['status'] ?? '') === 'draft' ? 'draft' : self::calculateStatus($data);
            
            // Insert event
            // Note: requires_application has been removed from the Event editor.
            // The DB column still exists but is always written as 0 for new events.
            $stmt = $db->prepare("
                INSERT INTO events (title, description, location, maps_link, start_time, end_time,
                                  registration_start, registration_end, status,
                                  is_external, external_link, registration_link, needs_helpers,
                                  image_path, created_by, is_internal_project, requires_application)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");

            $stmt->execute([
                $data['title'],
                $data['description'] ?? null,
                $data['location'] ?? null,
                $data['maps_link'] ?? null,
                $data['start_time'],
                $data['end_time'],
                $data['registration_start'] ?? null,
                $data['registration_end'] ?? null,
                $calculatedStatus,
                $data['is_external'] ?? false,
                $data['external_link'] ?? null,
                $data['registration_link'] ?? null,
                $data['needs_helpers'] ?? false,
                $imagePath,
                $userId,
                $data['is_internal_project'] ?? 0
            ]);
            
            $eventId = $db->lastInsertId();
            
            // Insert allowed roles if provided
            if (!empty($data['allowed_roles']) && is_array($data['allowed_roles'])) {
                self::setEventRoles($eventId, $data['allowed_roles']);
            }
            
            // Process helper types and slots if needs_helpers is enabled
            if (!empty($data['needs_helpers']) && !empty($data['helper_types'])) {
                self::processHelperTypesAndSlots(
                    $eventId, 
                    $data['helper_types'], 
                    $data['start_time'], 
                    $data['end_time'],
                    $userId
                );
            }
            
            // Log creation
            self::logHistory($eventId, $userId, 'create', [
                'action' => 'Event created',
                'title' => $data['title'],
                'calculated_status' => $calculatedStatus
            ]);
            
            $db->commit();
            
            // Send email notifications to users with notify_new_events = 1
            // This is done after commit to ensure event is saved even if email fails
            try {
                $userDB = Database::getUserDB();
                $stmt = $userDB->prepare("SELECT id, email FROM users WHERE notify_new_events = 1");
                $stmt->execute();
                $recipients = $stmt->fetchAll();
                
                // Get the newly created event details
                $event = self::getById($eventId, false);
                
                // Format event date
                $startDate = new DateTime($event['start_time']);
                $formattedDate = $startDate->format('d.m.Y H:i');
                
                // Build event link
                $eventLink = BASE_URL . '/pages/events/view.php?id=' . intval($eventId);
                
                // Send email to each recipient
                foreach ($recipients as $recipient) {
                    try {
                        $subject = 'Neues Event: ' . $event['title'] . ' - Jetzt anmelden!';
                        
                        // Build HTML body
                        $bodyContent = '<p class="email-text">Hallo,</p>
                        <p class="email-text">es gibt ein neues Event: <strong>' . htmlspecialchars($event['title']) . '</strong> am ' . htmlspecialchars($formattedDate) . '.</p>';
                        
                        // Add helper message only if event needs helpers
                        if (!empty($event['needs_helpers'])) {
                            $bodyContent .= '<p class="email-text">Wir suchen noch Helfer!</p>';
                        }
                        
                        // Add call-to-action button
                        $callToAction = '<a href="' . htmlspecialchars($eventLink) . '" class="button">Hier klicken zum Ansehen</a>';
                        
                        // Use MailService template
                        $htmlBody = MailService::getTemplate('Neues Event', $bodyContent, $callToAction);
                        // Use absolute URL for logo so it renders in Outlook/Gmail
                        $logoUrl = htmlspecialchars(BASE_URL . '/assets/img/ibc_logo_original_navbar.webp', ENT_QUOTES, 'UTF-8');
                        $htmlBody = str_replace('cid:ibc_logo', $logoUrl, $htmlBody);
                        
                        // Send email
                        MailService::sendEmail($recipient['email'], $subject, $htmlBody);
                    } catch (Exception $mailError) {
                        // Log individual email errors but continue with other recipients
                        error_log("Failed to send event notification to {$recipient['email']}: " . $mailError->getMessage());
                    }
                }
            } catch (Exception $notificationError) {
                // Log the error but don't throw - event was already created successfully
                error_log("Failed to send event notifications: " . $notificationError->getMessage());
            }
            
            return $eventId;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to create event: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get event by ID
     */
    public static function getById($id, $includeHelperSlots = true) {
        $db = Database::getContentDB();
        
        // Use a subquery to get the attendee count in one query
        $stmt = $db->prepare("
            SELECT e.*,
                   (SELECT COUNT(*) 
                    FROM event_signups 
                    WHERE event_id = e.id AND status = 'confirmed') as attendee_count
            FROM events e
            WHERE e.id = ?
        ");
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        
        if (!$event) {
            return null;
        }
        
        // Lazy update: Check and update status if needed
        $event = self::updateEventStatusIfNeeded($event, $db);
        
        // Get allowed roles
        $event['allowed_roles'] = self::getEventRoles($id);
        
        // Get helper types and slots if needed and event needs helpers
        if ($includeHelperSlots && $event['needs_helpers']) {
            $event['helper_types'] = self::getHelperTypes($id);
        }
        
        // Get list of attendees with user ID and name
        $event['attendees'] = self::getEventAttendees($id);

        // Apply default image fallback
        $event['image_path'] = self::getImageUrl($event['image_path']);
        
        return $event;
    }
    
    /**
     * Update event
     */
    public static function update($id, $data, $userId, $files = []) {
        $db = Database::getContentDB();
        
        // Check if event is locked by another user
        $lockInfo = self::checkLock($id, $userId);
        if ($lockInfo['is_locked'] && $lockInfo['locked_by'] !== $userId) {
            throw new Exception("Event is locked by another user");
        }
        
        // Begin transaction
        $db->beginTransaction();
        
        try {
            // Get current event data for status calculation
            $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
            $stmt->execute([$id]);
            $currentEvent = $stmt->fetch();
            
            if (!$currentEvent) {
                throw new Exception("Event not found");
            }
            
            // Handle image deletion
            $oldImagePath = null;
            if (isset($data['delete_image']) && $data['delete_image'] === true) {
                // Store old image path for deletion after transaction
                $oldImagePath = $currentEvent['image_path'] ?? null;
                // Set image_path to NULL in database
                $data['image_path'] = null;
                // Remove delete_image from data array (not a database field)
                unset($data['delete_image']);
            } 
            // Handle image upload (only if not deleting)
            elseif (isset($files['event_image']) && $files['event_image']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = SecureImageUpload::uploadImage($files['event_image']);
                if ($uploadResult['success']) {
                    // Store old image path for deletion after transaction
                    $oldImagePath = $currentEvent['image_path'] ?? null;
                    // Set new image path in data
                    $data['image_path'] = $uploadResult['path'];
                } else {
                    error_log("Failed to upload event image for event ID $id: " . $uploadResult['error']);
                }
            }
            
            // If image_path is provided in $data but is empty string, remove it to preserve the old value
            // This handles the case where image_path might be set to '' in the data array but we want to keep the existing image
            // Note: NULL is intentionally allowed to explicitly clear the image (via delete_image flag)
            if (isset($data['image_path']) && $data['image_path'] === '') {
                unset($data['image_path']);
            }
            
            // Merge current data with updates for status calculation
            $mergedData = array_merge($currentEvent, $data);
            
            // Validate merged event data
            self::validateEventData($mergedData);
            
            // Calculate status: use 'draft' if explicitly set, otherwise auto-calculate
            $calculatedStatus = ($data['status'] ?? '') === 'draft' ? 'draft' : self::calculateStatus($mergedData);
            
            $fields = [];
            $values = [];
            
            // Build update query dynamically, excluding status from manual update
            foreach ($data as $key => $value) {
                if (!in_array($key, self::EXCLUDED_UPDATE_FIELDS, true)) {
                    $fields[] = "$key = ?";
                    $values[] = $value;
                }
            }
            
            // Add calculated status to update
            $fields[] = "status = ?";
            $values[] = $calculatedStatus;
            
            if (!empty($fields)) {
                $values[] = $id;
                $sql = "UPDATE events SET " . implode(', ', $fields) . " WHERE id = ?";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($values);
            }
            
            // Update allowed roles if provided
            if (isset($data['allowed_roles']) && is_array($data['allowed_roles'])) {
                self::setEventRoles($id, $data['allowed_roles']);
            }
            
            // Process helper types and slots if needs_helpers is enabled
            if (!empty($data['needs_helpers']) && isset($data['helper_types'])) {
                // Delete old helper types and slots (Clean Slate approach)
                $stmt = $db->prepare("DELETE FROM event_helper_types WHERE event_id = ?");
                $stmt->execute([$id]);
                
                // Process new helper types and slots
                self::processHelperTypesAndSlots(
                    $id, 
                    $data['helper_types'], 
                    $data['start_time'] ?? null, 
                    $data['end_time'] ?? null,
                    $userId
                );
            }
            
            // Log update
            self::logHistory($id, $userId, 'update', [
                'action' => 'Event updated',
                'changes' => $data,
                'calculated_status' => $calculatedStatus
            ]);
            
            $db->commit();
            
            // Delete old image after successful transaction
            if (!empty($oldImagePath)) {
                SecureImageUpload::deleteImage($oldImagePath);
            }
            
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to update event: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete event
     */
    public static function delete($id, $userId) {
        $db = Database::getContentDB();

        // Fetch the event's image path before deletion so we can clean up the file
        $stmt = $db->prepare("SELECT image_path FROM events WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $imagePath = ($row !== false) ? ($row['image_path'] ?? null) : null;
        
        // Log deletion before deleting
        self::logHistory($id, $userId, 'delete', [
            'action' => 'Event deleted'
        ]);
        
        $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
        $result = $stmt->execute([$id]);

        // Delete the cover image file after successful DB deletion
        if ($result && !empty($imagePath)) {
            SecureImageUpload::deleteImage($imagePath);
        }

        return $result;
    }
    
    /**
     * Roles that may see ALL draft events, including those created by other users.
     * Users with one of these roles (admin / board / Vorstand) can review unpublished
     * events, while regular members only see their own drafts.
     */
    const DRAFT_VIEWER_ROLES = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'alumni_vorstand', 'ressortleiter', 'manager'];

    /**
     * Get events with filters
     * Respects user role for visibility
     * Alumni users NEVER see helper slots or helper-related information
     *
     * @param array  $filters  Associative filter array
     * @param string|null $userRole  Role of the current user
     * @param int|null    $userId   ID of the current user (used to show own drafts)
     */
    public static function getEvents($filters = [], $userRole = null, $userId = null) {
        $db = Database::getContentDB();
        
        $sql = "SELECT * FROM events WHERE 1=1";
        $params = [];
        
        // Filter by status
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = str_repeat('?,', count($filters['status']) - 1) . '?';
                $sql .= " AND status IN ($placeholders)";
                $params = array_merge($params, $filters['status']);
            } else {
                $sql .= " AND status = ?";
                $params[] = $filters['status'];
            }
        }
        
        // Filter by needs_helpers
        if (isset($filters['needs_helpers'])) {
            $sql .= " AND needs_helpers = ?";
            $params[] = $filters['needs_helpers'] ? 1 : 0;
        }
        
        // Filter by date range
        if (!empty($filters['start_date'])) {
            $sql .= " AND start_time >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND end_time <= ?";
            $params[] = $filters['end_date'];
        }
        
        // Filter by external/internal
        if (isset($filters['is_external'])) {
            $sql .= " AND is_external = ?";
            $params[] = $filters['is_external'] ? 1 : 0;
        }
        
        // Order by start time
        $sql .= " ORDER BY start_time DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $events = $stmt->fetchAll();
        
        // Determine whether the current user can see all draft events
        $canViewAllDrafts = $userRole !== null && in_array($userRole, self::DRAFT_VIEWER_ROLES, true);
        
        // OPTIMIZATION: Batch update statuses - only update events that need changes
        // This minimizes write operations by comparing current vs calculated status
        $eventsToUpdate = [];
        foreach ($events as &$event) {
            // Draft events keep their status until explicitly published
            if ($event['status'] === 'draft') {
                continue;
            }
            // Current status from database
            $currentStatus = $event['status'];
            // Calculate what status should be based on timestamps
            $calculatedStatus = self::calculateStatus($event);
            
            // Only add to update batch if status has changed
            if ($currentStatus !== $calculatedStatus) {
                $eventsToUpdate[] = [
                    'id' => $event['id'],
                    'status' => $calculatedStatus
                ];
                // Update array for immediate use
                $event['status'] = $calculatedStatus;
            }
        }
        unset($event); // Break reference
        
        // Perform batch status updates only if there are changes
        // OPTIMIZATION: If all statuses are already correct, no database writes occur
        if (!empty($eventsToUpdate)) {
            $db->beginTransaction();
            try {
                $updateStmt = $db->prepare("UPDATE events SET status = ? WHERE id = ?");
                foreach ($eventsToUpdate as $update) {
                    $updateStmt->execute([$update['status'], $update['id']]);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Failed to batch update event statuses: " . $e->getMessage());
            }
        }
        
        // Filter events by role visibility and remove helper information for alumni
        $filteredEvents = [];
        foreach ($events as $event) {
            // Draft events are only visible to their creator or privileged roles
            if ($event['status'] === 'draft') {
                $isCreator = $userId !== null && (int)$event['created_by'] === (int)$userId;
                if (!$isCreator && !$canViewAllDrafts) {
                    continue; // Skip draft events the user is not allowed to see
                }
            }
            
            // Get allowed roles for this event
            $allowedRoles = self::getEventRoles($event['id']);
            
            // Check if user has access
            if ($userRole && !empty($allowedRoles) && !in_array($userRole, $allowedRoles)) {
                continue; // Skip this event
            }
            
            // IMPORTANT: Alumni must NEVER see helper slots or needs_helpers
            if ($userRole === 'alumni') {
                $event['needs_helpers'] = false;
                $event['helper_types'] = [];
            } else if ($event['needs_helpers']) {
                // For non-alumni users, include helper information if requested
                if (!empty($filters['include_helpers'])) {
                    $event['helper_types'] = self::getHelperTypes($event['id']);
                }
            }
            
            $event['allowed_roles'] = $allowedRoles;
            // Apply default image fallback
            $event['image_path'] = self::getImageUrl($event['image_path']);
            $filteredEvents[] = $event;
        }
        
        return $filteredEvents;
    }
    
    /**
     * Get event roles (allowed roles for participation)
     */
    private static function getEventRoles($eventId) {
        $db = Database::getContentDB();
        $stmt = $db->prepare("SELECT role FROM event_roles WHERE event_id = ?");
        $stmt->execute([$eventId]);
        return array_column($stmt->fetchAll(), 'role');
    }
    
    /**
     * Set event roles (replace existing roles)
     */
    private static function setEventRoles($eventId, $roles) {
        $db = Database::getContentDB();
        
        // Delete existing roles
        $stmt = $db->prepare("DELETE FROM event_roles WHERE event_id = ?");
        $stmt->execute([$eventId]);
        
        // Insert new roles
        if (!empty($roles)) {
            $stmt = $db->prepare("INSERT INTO event_roles (event_id, role) VALUES (?, ?)");
            foreach ($roles as $role) {
                $stmt->execute([$eventId, $role]);
            }
        }
    }
    
    /**
     * Process helper types and slots for an event
     * This method handles creating helper types and their associated time slots.
     * 
     * IMPORTANT: This method must be called within an existing database transaction.
     * The caller (create/update methods) is responsible for transaction management.
     * 
     * @param int $eventId Event ID
     * @param array $helperTypes Array of helper types with their slots
     * @param string|null $eventStartTime Event start time for validation
     * @param string|null $eventEndTime Event end time for validation
     * @param int $userId User ID for logging
     * @throws Exception If validation fails or database operations fail
     */
    private static function processHelperTypesAndSlots($eventId, $helperTypes, $eventStartTime, $eventEndTime, $userId) {
        if (empty($helperTypes) || !is_array($helperTypes)) {
            return;
        }
        
        $db = Database::getContentDB();
        
        foreach ($helperTypes as $helperType) {
            // Skip if no title provided
            if (empty($helperType['title'])) {
                continue;
            }
            
            // Create helper type
            $helperTypeId = self::createHelperType(
                $eventId,
                $helperType['title'],
                $helperType['description'] ?? null,
                $userId
            );
            
            // Process slots for this helper type
            if (!empty($helperType['slots']) && is_array($helperType['slots'])) {
                foreach ($helperType['slots'] as $slot) {
                    // Skip if required fields are missing
                    if (empty($slot['start_time']) || empty($slot['end_time'])) {
                        continue;
                    }
                    
                    // Validate slot start/end times
                    $slotStart = strtotime($slot['start_time']);
                    $slotEnd = strtotime($slot['end_time']);

                    // Validate parsing was successful
                    if ($slotStart === false) {
                        throw new Exception('Ungültige Slot-Startzeit: ' . $slot['start_time']);
                    }
                    if ($slotEnd === false) {
                        throw new Exception('Ungültige Slot-Endzeit: ' . $slot['end_time']);
                    }

                    // Validate slot start is before end
                    if ($slotStart >= $slotEnd) {
                        throw new Exception('Slot-Startzeit muss vor der Endzeit liegen');
                    }
                    
                    // Create slot
                    self::createSlot(
                        $helperTypeId,
                        $slot['start_time'],
                        $slot['end_time'],
                        intval($slot['quantity'] ?? 1),
                        $userId,
                        $eventId
                    );
                }
            }
        }
    }
    
    /**
     * Create helper type for event
     */
    public static function createHelperType($eventId, $title, $description = null, $userId = null) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            INSERT INTO event_helper_types (event_id, title, description)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$eventId, $title, $description]);
        
        $helperTypeId = $db->lastInsertId();
        
        if ($userId) {
            self::logHistory($eventId, $userId, 'helper_type_created', [
                'helper_type_id' => $helperTypeId,
                'title' => $title
            ]);
        }
        
        return $helperTypeId;
    }
    
    /**
     * Get helper types for event
     */
    public static function getHelperTypes($eventId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("SELECT * FROM event_helper_types WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $helperTypes = $stmt->fetchAll();
        
        // Get slots for each helper type
        foreach ($helperTypes as &$helperType) {
            $helperType['slots'] = self::getSlots($helperType['id']);
        }
        
        return $helperTypes;
    }
    
    /**
     * Create slot for helper type
     */
    public static function createSlot($helperTypeId, $startTime, $endTime, $quantityNeeded, $userId = null, $eventId = null) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            INSERT INTO event_slots (helper_type_id, start_time, end_time, quantity_needed)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$helperTypeId, $startTime, $endTime, $quantityNeeded]);
        
        $slotId = $db->lastInsertId();
        
        if ($userId && $eventId) {
            self::logHistory($eventId, $userId, 'slot_created', [
                'slot_id' => $slotId,
                'helper_type_id' => $helperTypeId,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'quantity_needed' => $quantityNeeded
            ]);
        }
        
        return $slotId;
    }
    
    /**
     * Get slots for helper type
     */
    public static function getSlots($helperTypeId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM event_signups WHERE slot_id = s.id AND status = 'confirmed') as signups_count
            FROM event_slots s
            WHERE s.helper_type_id = ?
            ORDER BY s.start_time
        ");
        $stmt->execute([$helperTypeId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Sign up for event or slot
     * IMPORTANT: Alumni users are NOT allowed to sign up for helper slots
     */
    public static function signup($eventId, $userId, $slotId = null, $userRole = null) {
        $db = Database::getContentDB();
        
        // Get event to check if it needs helpers
        $event = self::getById($eventId, false);
        if (!$event) {
            throw new Exception("Event not found");
        }
        
        // CRITICAL: Alumni users must NOT be able to sign up for helper slots
        if ($slotId !== null && $userRole === 'alumni') {
            throw new Exception("Alumni users are not allowed to sign up for helper slots");
        }
        
        // If slot_id is provided, verify the event needs helpers
        if ($slotId !== null && !$event['needs_helpers']) {
            throw new Exception("This event does not have helper slots");
        }
        
        // Check if user has permission to participate
        $allowedRoles = self::getEventRoles($eventId);
        if (!empty($allowedRoles) && $userRole && !in_array($userRole, $allowedRoles)) {
            throw new Exception("You do not have permission to participate in this event");
        }
        
        // Check if slot is full (if signing up for a slot)
        if ($slotId !== null) {
            $stmt = $db->prepare("
                SELECT s.quantity_needed,
                       (SELECT COUNT(*) FROM event_signups WHERE slot_id = s.id AND status = 'confirmed') as signups_count
                FROM event_slots s
                WHERE s.id = ?
            ");
            $stmt->execute([$slotId]);
            $slot = $stmt->fetch();
            
            if ($slot && $slot['signups_count'] >= $slot['quantity_needed']) {
                // Add to waitlist instead
                $status = 'waitlist';
            } else {
                $status = 'confirmed';
            }
        } else {
            $status = 'confirmed';
        }
        
        // Insert signup
        $stmt = $db->prepare("
            INSERT INTO event_signups (event_id, user_id, slot_id, status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$eventId, $userId, $slotId, $status]);
        
        $signupId = $db->lastInsertId();
        
        // Log signup
        self::logHistory($eventId, $userId, 'signup', [
            'signup_id' => $signupId,
            'slot_id' => $slotId,
            'status' => $status
        ]);
        
        return ['id' => $signupId, 'status' => $status];
    }
    
    /**
     * Cancel signup
     */
    public static function cancelSignup($signupId, $userId) {
        $db = Database::getContentDB();
        
        // Get signup info
        $stmt = $db->prepare("SELECT * FROM event_signups WHERE id = ? AND user_id = ?");
        $stmt->execute([$signupId, $userId]);
        $signup = $stmt->fetch();
        
        if (!$signup) {
            throw new Exception("Signup not found");
        }
        
        // Update status to cancelled
        $stmt = $db->prepare("UPDATE event_signups SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$signupId]);
        
        // Log cancellation
        self::logHistory($signup['event_id'], $userId, 'signup_cancelled', [
            'signup_id' => $signupId,
            'slot_id' => $signup['slot_id']
        ]);
        
        // If this was a confirmed slot signup, check if someone on waitlist can be promoted
        $promotedUserId = null;
        if ($signup['slot_id'] && $signup['status'] === 'confirmed') {
            $promotedUserId = self::promoteWaitlistUser($signup['event_id'], $signup['slot_id']);
        }
        
        return [
            'success' => true,
            'promoted_user_id' => $promotedUserId,
            'event_id' => $signup['event_id'],
            'slot_id' => $signup['slot_id']
        ];
    }
    
    /**
     * Promote a waitlisted user to confirmed for a slot
     * Called when a confirmed user cancels
     * 
     * @param int $eventId Event ID
     * @param int $slotId Slot ID
     * @return int|null User ID that was promoted, or null if no one was promoted
     */
    private static function promoteWaitlistUser($eventId, $slotId) {
        $db = Database::getContentDB();
        
        // Get the first waitlisted user for this slot (oldest signup first)
        $stmt = $db->prepare("
            SELECT * FROM event_signups 
            WHERE event_id = ? AND slot_id = ? AND status = 'waitlist'
            ORDER BY created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$eventId, $slotId]);
        $waitlistSignup = $stmt->fetch();
        
        if (!$waitlistSignup) {
            return null; // No one on waitlist
        }
        
        // Promote to confirmed
        $stmt = $db->prepare("UPDATE event_signups SET status = 'confirmed' WHERE id = ?");
        $stmt->execute([$waitlistSignup['id']]);
        
        // Log promotion
        self::logHistory($eventId, $waitlistSignup['user_id'], 'waitlist_promoted', [
            'signup_id' => $waitlistSignup['id'],
            'slot_id' => $slotId
        ]);
        
        return $waitlistSignup['user_id'];
    }
    
    /**
     * Get signups for event
     */
    public static function getSignups($eventId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT s.*, es.title as slot_title
            FROM event_signups s
            LEFT JOIN event_slots es_slots ON s.slot_id = es_slots.id
            LEFT JOIN event_helper_types es ON es_slots.helper_type_id = es.id
            WHERE s.event_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get user signups
     */
    public static function getUserSignups($userId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT s.*, e.title as event_title, e.start_time, e.end_time, e.location
            FROM event_signups s
            JOIN events e ON s.event_id = e.id
            WHERE s.user_id = ? AND s.status != 'cancelled'
            ORDER BY e.start_time DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get registration count for an event
     * Returns the number of confirmed registrations (excluding cancelled and waitlist)
     */
    public static function getRegistrationCount($eventId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM event_signups
            WHERE event_id = ? AND status = 'confirmed'
        ");
        $stmt->execute([$eventId]);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }
    
    /**
     * Get event attendees (confirmed signups) with user ID and name
     * Returns an array of attendees with user_id, first_name, and last_name
     */
    public static function getEventAttendees($eventId) {
        $contentDb = Database::getContentDB();
        $userDb = Database::getUserDB();
        
        // First, get confirmed signups from content database
        $stmt = $contentDb->prepare("
            SELECT DISTINCT user_id
            FROM event_signups
            WHERE event_id = ? AND status = 'confirmed'
        ");
        $stmt->execute([$eventId]);
        $signups = $stmt->fetchAll();
        
        if (empty($signups)) {
            return [];
        }
        
        // Extract user IDs
        $userIds = array_column($signups, 'user_id');
        
        // Get user emails from user database for fallback
        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        
        $stmt = $userDb->prepare("
            SELECT u.id as user_id, u.email, u.privacy_hide_email
            FROM users u
            WHERE u.id IN ($placeholders)
        ");
        $stmt->execute($userIds);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create a map of user_id => user data for quick lookup
        $userMap = [];
        foreach ($users as $user) {
            $userMap[$user['user_id']] = $user;
        }
        
        // Get alumni profiles from content database
        $stmt = $contentDb->prepare("
            SELECT user_id, first_name, last_name
            FROM alumni_profiles
            WHERE user_id IN ($placeholders)
        ");
        $stmt->execute($userIds);
        $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create a map of user_id => profile for quick lookup
        $profileMap = [];
        foreach ($profiles as $profile) {
            $profileMap[$profile['user_id']] = $profile;
        }
        
        // Merge user data with profiles
        $attendees = [];
        foreach ($userIds as $userId) {
            // Skip if user not found in database (defensive programming)
            if (!isset($userMap[$userId])) {
                continue;
            }
            
            $userData = $userMap[$userId];
            $email = $userData['email'] ?? '';
            $hideEmail = !empty($userData['privacy_hide_email'] ?? true);
            $attendee = [
                'user_id' => $userId,
                'email' => $email,
                'first_name' => $profileMap[$userId]['first_name'] ?? '',
                'last_name' => $profileMap[$userId]['last_name'] ?? '',
                'privacy_hide_email' => $hideEmail,
            ];
            
            // For users without alumni profiles, use a fallback display name
            if (empty($attendee['first_name']) && empty($attendee['last_name'])) {
                // Only use email local part as display name if the user has not hidden their email
                if (!$hideEmail && !empty($email) && strpos($email, '@') !== false) {
                    $emailParts = explode('@', $email);
                    $attendee['first_name'] = $emailParts[0];
                    $attendee['last_name'] = '';
                } else {
                    $attendee['first_name'] = 'Benutzer';
                    $attendee['last_name'] = '';
                }
            }
            
            // Store sort key for proper sorting (use email as fallback for last name)
            $attendee['_sort_key'] = !empty($attendee['last_name']) ? $attendee['last_name'] : (!$hideEmail ? $email : 'Benutzer');
            
            $attendees[] = $attendee;
        }
        
        // Sort by last name (or email if no last name), then first name
        usort($attendees, function($a, $b) {
            $sortKeyCmp = strcasecmp($a['_sort_key'], $b['_sort_key']);
            if ($sortKeyCmp !== 0) {
                return $sortKeyCmp;
            }
            return strcasecmp($a['first_name'], $b['first_name']);
        });
        
        // Remove temporary sort key and email from output
        foreach ($attendees as &$attendee) {
            unset($attendee['_sort_key']);
            unset($attendee['email']);
        }
        
        return $attendees;
    }
    
    /**
     * Check if event is locked
     * Returns: ['is_locked' => bool, 'locked_by' => user_id or null, 'locked_at' => timestamp or null]
     */
    public static function checkLock($eventId, $userId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("SELECT locked_by, locked_at FROM events WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        
        if (!$event) {
            return ['is_locked' => false, 'locked_by' => null, 'locked_at' => null];
        }
        
        // No lock
        if (!$event['locked_by'] || !$event['locked_at']) {
            return ['is_locked' => false, 'locked_by' => null, 'locked_at' => null];
        }
        
        // Check if lock has expired (15 minutes)
        $lockTime = strtotime($event['locked_at']);
        $now = time();
        
        if (($now - $lockTime) > self::LOCK_TIMEOUT) {
            // Lock expired, release it
            self::releaseLock($eventId, $event['locked_by']);
            return ['is_locked' => false, 'locked_by' => null, 'locked_at' => null];
        }
        
        // Lock is active
        return [
            'is_locked' => true,
            'locked_by' => $event['locked_by'],
            'locked_at' => $event['locked_at']
        ];
    }
    
    /**
     * Acquire lock on event (max 15 minutes valid)
     */
    public static function acquireLock($eventId, $userId) {
        $db = Database::getContentDB();
        
        // Check current lock status
        $lockInfo = self::checkLock($eventId, $userId);
        
        // If locked by another user, cannot acquire
        if ($lockInfo['is_locked'] && $lockInfo['locked_by'] !== $userId) {
            return [
                'success' => false,
                'message' => 'Event is currently locked by another user',
                'locked_by' => $lockInfo['locked_by']
            ];
        }
        
        // Acquire or refresh lock
        $stmt = $db->prepare("
            UPDATE events 
            SET locked_by = ?, locked_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$userId, $eventId]);
        
        // Log lock acquisition
        self::logHistory($eventId, $userId, 'lock_acquired', [
            'action' => 'Lock acquired',
            'timeout' => self::LOCK_TIMEOUT . ' seconds'
        ]);
        
        return [
            'success' => true,
            'message' => 'Lock acquired successfully',
            'expires_in' => self::LOCK_TIMEOUT
        ];
    }
    
    /**
     * Release lock on event
     */
    public static function releaseLock($eventId, $userId) {
        $db = Database::getContentDB();
        
        // Only the user who locked it can release it (or if lock expired)
        $lockInfo = self::checkLock($eventId, $userId);
        
        if ($lockInfo['is_locked'] && $lockInfo['locked_by'] !== $userId) {
            return [
                'success' => false,
                'message' => 'Cannot release lock held by another user'
            ];
        }
        
        $stmt = $db->prepare("
            UPDATE events 
            SET locked_by = NULL, locked_at = NULL 
            WHERE id = ? AND (locked_by = ? OR locked_by IS NULL)
        ");
        $stmt->execute([$eventId, $userId]);
        
        // Log lock release
        self::logHistory($eventId, $userId, 'lock_released', [
            'action' => 'Lock released'
        ]);
        
        return [
            'success' => true,
            'message' => 'Lock released successfully'
        ];
    }
    
    /**
     * Log event history entry
     */
    private static function logHistory($eventId, $userId, $changeType, $changeDetails) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            INSERT INTO event_history (event_id, user_id, change_type, change_details)
            VALUES (?, ?, ?, ?)
        ");
        
        $detailsJson = json_encode($changeDetails);
        $stmt->execute([$eventId, $userId, $changeType, $detailsJson]);
    }
    
    /**
     * Get event history
     */
    public static function getHistory($eventId, $limit = 50) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT * FROM event_history
            WHERE event_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$eventId, $limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Update event statuses based on current time (pseudo-cron)
     * This should be called on page loads to keep statuses current
     * 
     * Updates:
     * - "planned" events to "open" when registration should open
     * - Events to "past" when they have ended
     * 
     * @return array Summary of updates made
     */
    public static function updateEventStatuses() {
        $db = Database::getContentDB();
        $now = new DateTime();
        $updates = [
            'planned_to_open' => 0,
            'to_past' => 0
        ];
        
        try {
            // Update "planned" events to "open" when registration period starts
            // Assuming registration opens when current time is past the event start time minus some buffer
            // For simplicity, we'll open registration as soon as the event is not in the past
            // In a real system, you might have a separate registration_opens_at field
            
            $stmt = $db->prepare("
                UPDATE events 
                SET status = 'open' 
                WHERE status = 'planned' 
                AND start_time > NOW()
            ");
            $stmt->execute();
            $updates['planned_to_open'] = $stmt->rowCount();
            
            // Update events to "past" when end_time has passed
            $stmt = $db->prepare("
                UPDATE events 
                SET status = 'past' 
                WHERE status IN ('open', 'running', 'closed', 'planned')
                AND end_time < NOW()
            ");
            $stmt->execute();
            $updates['to_past'] = $stmt->rowCount();
            
            // Log if any updates were made
            if ($updates['planned_to_open'] > 0 || $updates['to_past'] > 0) {
                error_log("Event status update: " . 
                         $updates['planned_to_open'] . " events opened, " . 
                         $updates['to_past'] . " events moved to past");
            }
            
            return $updates;
        } catch (Exception $e) {
            error_log("Error updating event statuses: " . $e->getMessage());
            return $updates;
        }
    }

    /**
     * Set or remove the feedback contact (alumni Ansprechpartner) for an event.
     *
     * @param int $eventId Event ID
     * @param int|null $userId User ID to set as contact, or null to remove
     * @return bool True on success
     */
    public static function setFeedbackContact(int $eventId, ?int $userId): bool {
        $db = Database::getContentDB();
        $stmt = $db->prepare("UPDATE events SET feedback_contact_user_id = ? WHERE id = ?");
        return $stmt->execute([$userId, $eventId]);
    }

    /**
     * Get the feedback contact user profile for an event.
     *
     * @param int $eventId Event ID
     * @return array|null Profile data (first_name, last_name, image_path, position, company) or null
     */
    public static function getFeedbackContact(int $eventId): ?array {
        $db = Database::getContentDB();
        $stmt = $db->prepare("SELECT feedback_contact_user_id FROM events WHERE id = ?");
        $stmt->execute([$eventId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['feedback_contact_user_id'])) {
            return null;
        }
        $userId = (int)$row['feedback_contact_user_id'];

        // Get profile from alumni_profiles
        $stmt = $db->prepare("SELECT user_id, first_name, last_name, image_path, position, company FROM alumni_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        if (!$profile) {
            return ['user_id' => $userId, 'first_name' => '', 'last_name' => '', 'image_path' => null, 'position' => null, 'company' => null];
        }
        return $profile;
    }
}

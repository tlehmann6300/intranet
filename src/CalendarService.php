<?php
/**
 * Calendar Service
 * Generates iCal (.ics) files for events and slots
 */

class CalendarService {
    
    /**
     * Generate ICS file content for an event or slot
     * 
     * @param array $event Event data from database
     * @param array|null $slot Optional slot data (if specific slot is selected)
     * @return string ICS formatted string
     */
    public static function generateICS($event, $slot = null) {
        // Determine times based on whether we have a slot or full event
        if ($slot !== null) {
            $startTime = $slot['start_time'];
            $endTime = $slot['end_time'];
            $summary = $event['title'] . ' - ' . ($slot['slot_title'] ?? 'Helfer-Slot');
        } else {
            $startTime = $event['start_time'];
            $endTime = $event['end_time'];
            $summary = $event['title'];
        }
        
        // Convert to DateTime objects
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        $now = new DateTime();
        
        // Format dates for iCal (UTC format: yyyyMMddTHHmmssZ)
        $dtStart = $start->format('Ymd\THis');
        $dtEnd = $end->format('Ymd\THis');
        $dtStamp = $now->format('Ymd\THis\Z');
        
        // Generate unique ID for the event
        $uid = md5($event['id'] . ($slot['id'] ?? '') . $startTime);
        
        // Build description
        $description = $event['description'] ?? '';
        if ($slot !== null) {
            $description .= "\n\nZeit: " . $start->format('d.m.Y H:i') . ' - ' . $end->format('H:i');
        }
        if (!empty($event['contact_person'])) {
            $description .= "\n\nKontakt: " . $event['contact_person'];
        }
        
        // Escape special characters for iCal
        $summary = self::escapeICalString($summary);
        $description = self::escapeICalString($description);
        $location = self::escapeICalString($event['location'] ?? '');
        
        // Build ICS content
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//IBC Intranet//Event System//DE\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $uid . "@ibc-intranet\r\n";
        $ics .= "DTSTAMP:" . $dtStamp . "\r\n";
        $ics .= "DTSTART:" . $dtStart . "\r\n";
        $ics .= "DTEND:" . $dtEnd . "\r\n";
        $ics .= "SUMMARY:" . $summary . "\r\n";
        
        if (!empty($description)) {
            $ics .= "DESCRIPTION:" . $description . "\r\n";
        }
        
        if (!empty($location)) {
            $ics .= "LOCATION:" . $location . "\r\n";
        }
        
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "SEQUENCE:0\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";
        
        return $ics;
    }
    
    /**
     * Generate Google Calendar link for an event or slot
     * 
     * @param array $event Event data from database
     * @param array|null $slot Optional slot data
     * @return string Google Calendar URL
     */
    public static function generateGoogleCalendarLink($event, $slot = null) {
        // Determine times based on whether we have a slot or full event
        if ($slot !== null) {
            $startTime = $slot['start_time'];
            $endTime = $slot['end_time'];
            $title = $event['title'] . ' - ' . ($slot['slot_title'] ?? 'Helfer-Slot');
        } else {
            $startTime = $event['start_time'];
            $endTime = $event['end_time'];
            $title = $event['title'];
        }
        
        // Convert to DateTime objects
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        
        // Format dates for Google Calendar (yyyyMMddTHHmmss format)
        $dates = $start->format('Ymd\THis') . '/' . $end->format('Ymd\THis');
        
        // Build description
        $description = $event['description'] ?? '';
        if ($slot !== null) {
            $description .= "\n\nZeit: " . $start->format('d.m.Y H:i') . ' - ' . $end->format('H:i');
        }
        if (!empty($event['contact_person'])) {
            $description .= "\n\nKontakt: " . $event['contact_person'];
        }
        
        // Build Google Calendar URL
        $params = [
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $dates,
            'details' => $description,
            'location' => $event['location'] ?? '',
        ];
        
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }
    
    /**
     * Generate Google Calendar link (alias for generateGoogleCalendarLink for compatibility)
     * 
     * @param array $event Event data from database
     * @param array|null $slot Optional slot data
     * @return string Google Calendar URL
     */
    public static function getGoogleLink($event, $slot = null) {
        return self::generateGoogleCalendarLink($event, $slot);
    }
    
    /**
     * Generate ICS file content (alias for generateICS for compatibility)
     * 
     * @param array $event Event data from database
     * @param array|null $slot Optional slot data
     * @return string ICS formatted string
     */
    public static function generateIcsFile($event, $slot = null) {
        return self::generateICS($event, $slot);
    }
    
    /**
     * Escape special characters for iCal format
     * 
     * @param string $str String to escape
     * @return string Escaped string
     */
    private static function escapeICalString($str) {
        // First escape backslashes
        $str = str_replace('\\', '\\\\', $str);
        
        // Replace line breaks with \n
        $str = str_replace(["\r\n", "\n", "\r"], '\\n', $str);
        
        // Escape special characters
        $str = str_replace([',', ';'], ['\\,', '\\;'], $str);
        
        return $str;
    }
}

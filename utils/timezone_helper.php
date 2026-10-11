<?php
/**
 * Timezone Helper Utilities
 * 
 * Core Principle: Store UTC in the database, display relative to the local user,
 * and handle relative inputs during scheduling.
 * 
 * This file provides centralized timezone handling across the application.
 */

// Default timezone fallback.
// Lessons are scheduled against a single reference clock — UK (Europe/London) —
// so an unset user falls back to UK rather than raw UTC. Tutors are separately
// forced to pick their own zone (see tutor/auth_tutor_only.php); this is only a
// safety net for students/admins who haven't chosen one yet.
define('DEFAULT_TIMEZONE', 'Europe/London');
define('FALLBACK_TIMEZONE', 'Europe/London');

/**
 * Get the current user's timezone from session.
 * Falls back to the UK reference zone when the user has not set one.
 *
 * @return string Valid timezone identifier
 */
function getUserTimezone(): string {
    $tz = $_SESSION['timezone'] ?? DEFAULT_TIMEZONE;

    // Validate the timezone
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        return DEFAULT_TIMEZONE;
    }

    return $tz;
}

/**
 * Resolve the timezone a lesson time typed by the CURRENT user should be
 * interpreted in when scheduling. Each scheduler enters times in their own
 * timezone; we convert that to a UTC instant for storage.
 *
 * @return string Valid timezone identifier (falls back to the UK reference zone)
 */
function getSchedulerTimezone(): string {
    return getUserTimezone();
}

/**
 * Convert a local datetime string to UTC for database storage
 * 
 * @param string $localDatetime The datetime string in user's local timezone
 * @param string|null $fromTimezone The source timezone (defaults to user's session timezone)
 * @param string $format The input format (defaults to 'Y-m-d H:i:s')
 * @return DateTime DateTime object in UTC
 */
function localToUtc(string $localDatetime, ?string $fromTimezone = null, string $format = 'Y-m-d H:i:s'): DateTime {
    $fromTz = $fromTimezone ?? getUserTimezone();
    
    // Create DateTime in the source timezone
    $sourceTz = new DateTimeZone($fromTz);
    $dt = DateTime::createFromFormat($format, $localDatetime, $sourceTz);
    
    // Handle various input formats
    if (!$dt) {
        // Try with just date and time
        $dt = DateTime::createFromFormat('Y-m-d H:i', $localDatetime, $sourceTz);
    }
    if (!$dt) {
        // Try standard parsing
        $dt = new DateTime($localDatetime, $sourceTz);
    }
    
    // Convert to UTC
    $dt->setTimezone(new DateTimeZone('UTC'));
    
    return $dt;
}

/**
 * Convert a UTC datetime to user's local timezone for display
 * 
 * @param string|DateTime $utcDatetime The UTC datetime (string or DateTime object)
 * @param string|null $toTimezone The target timezone (defaults to user's session timezone)
 * @return DateTime DateTime object in local timezone
 */
function utcToLocal($utcDatetime, ?string $toTimezone = null): DateTime {
    $toTz = $toTimezone ?? getUserTimezone();
    
    // Create DateTime in UTC
    if ($utcDatetime instanceof DateTime) {
        $dt = clone $utcDatetime;
        $dt->setTimezone(new DateTimeZone('UTC'));
    } else {
        $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
    }
    
    // Convert to target timezone
    $dt->setTimezone(new DateTimeZone($toTz));
    
    return $dt;
}

/**
 * Format a UTC datetime string for display in user's local timezone
 * 
 * @param string|null $utcDatetime The UTC datetime string from database
 * @param string $format The output format
 * @param string|null $toTimezone The target timezone (defaults to user's session timezone)
 * @return string Formatted datetime string
 */
function formatLocalDatetime(?string $utcDatetime, string $format = 'M j, Y g:i A', ?string $toTimezone = null): string {
    if (empty($utcDatetime)) {
        return '—';
    }
    
    try {
        $local = utcToLocal($utcDatetime, $toTimezone);
        return $local->format($format);
    } catch (Exception $e) {
        return '—';
    }
}

/**
 * Get the start of "today" in UTC based on user's local timezone
 * Useful for queries like "lessons today" that need UTC boundaries
 * 
 * @param string|null $timezone The timezone to use (defaults to user's session timezone)
 * @return DateTime DateTime representing start of today in UTC
 */
function getTodayStartUtc(?string $timezone = null): DateTime {
    $tz = $timezone ?? getUserTimezone();
    
    // Get start of today in user's local timezone
    $localNow = new DateTime('now', new DateTimeZone($tz));
    $localTodayStart = new DateTime($localNow->format('Y-m-d') . ' 00:00:00', new DateTimeZone($tz));
    
    // Convert to UTC
    $localTodayStart->setTimezone(new DateTimeZone('UTC'));
    
    return $localTodayStart;
}

/**
 * Get the end of "today" in UTC based on user's local timezone
 * 
 * @param string|null $timezone The timezone to use (defaults to user's session timezone)
 * @return DateTime DateTime representing end of today in UTC
 */
function getTodayEndUtc(?string $timezone = null): DateTime {
    $tz = $timezone ?? getUserTimezone();
    
    // Get end of today in user's local timezone
    $localNow = new DateTime('now', new DateTimeZone($tz));
    $localTodayEnd = new DateTime($localNow->format('Y-m-d') . ' 23:59:59', new DateTimeZone($tz));
    
    // Convert to UTC
    $localTodayEnd->setTimezone(new DateTimeZone('UTC'));
    
    return $localTodayEnd;
}

/**
 * Get the start of the current week in UTC based on user's local timezone
 * 
 * @param string|null $timezone The timezone to use (defaults to user's session timezone)
 * @return DateTime DateTime representing start of week in UTC
 */
function getWeekStartUtc(?string $timezone = null): DateTime {
    $tz = $timezone ?? getUserTimezone();
    
    $localNow = new DateTime('now', new DateTimeZone($tz));
    $localWeekStart = new DateTime('monday this week 00:00:00', new DateTimeZone($tz));
    
    // Convert to UTC
    $localWeekStart->setTimezone(new DateTimeZone('UTC'));
    
    return $localWeekStart;
}

/**
 * Get the start of the current month in UTC based on user's local timezone
 * 
 * @param string|null $timezone The timezone to use (defaults to user's session timezone)
 * @return DateTime DateTime representing start of month in UTC
 */
function getMonthStartUtc(?string $timezone = null): DateTime {
    $tz = $timezone ?? getUserTimezone();
    
    $localNow = new DateTime('now', new DateTimeZone($tz));
    $localMonthStart = new DateTime('first day of this month 00:00:00', new DateTimeZone($tz));
    
    // Convert to UTC
    $localMonthStart->setTimezone(new DateTimeZone('UTC'));
    
    return $localMonthStart;
}

/**
 * Get current time in UTC
 * 
 * @return DateTime Current UTC time
 */
function getNowUtc(): DateTime {
    return new DateTime('now', new DateTimeZone('UTC'));
}

/**
 * Get current time in user's local timezone
 * 
 * @param string|null $timezone The timezone to use (defaults to user's session timezone)
 * @return DateTime Current local time
 */
function getNowLocal(?string $timezone = null): DateTime {
    $tz = $timezone ?? getUserTimezone();
    return new DateTime('now', new DateTimeZone($tz));
}

/**
 * Check if a lesson is currently "in progress" using UTC comparison
 * 
 * @param string $startTimeUtc The lesson start time in UTC
 * @param string $endTimeUtc The lesson end time in UTC
 * @return bool True if the lesson is currently in progress
 */
function isLessonInProgress(string $startTimeUtc, string $endTimeUtc): bool {
    $now = getNowUtc();
    $start = new DateTime($startTimeUtc, new DateTimeZone('UTC'));
    $end = new DateTime($endTimeUtc, new DateTimeZone('UTC'));
    
    return $now >= $start && $now <= $end;
}

/**
 * Check if a lesson is upcoming (starts within the join window)
 * 
 * @param string $startTimeUtc The lesson start time in UTC
 * @param int $joinWindowMinutes Minutes before start when joining is allowed
 * @return bool True if within the join window
 */
function isWithinJoinWindow(string $startTimeUtc, int $joinWindowMinutes = 10): bool {
    $now = getNowUtc();
    $start = new DateTime($startTimeUtc, new DateTimeZone('UTC'));
    $windowStart = (clone $start)->modify("-{$joinWindowMinutes} minutes");
    
    return $now >= $windowStart && $now <= $start;
}

/**
 * Is now inside the window in which joining a lesson should count as attendance?
 *
 * Mirrors the dashboard's "state B": from $minutesBefore before the start until
 * the scheduled end. isWithinJoinWindow() above stops at the start time, so it
 * cannot be used for a lesson that is already running.
 *
 * @param string $startTimeUtc Lesson start, UTC
 * @param string $endTimeUtc   Lesson end, UTC
 * @param int    $minutesBefore How early the window opens
 * @return bool
 */
function isWithinAttendanceWindow(string $startTimeUtc, string $endTimeUtc, int $minutesBefore = 15): bool {
    try {
        $now   = getNowUtc();
        $start = (new DateTime($startTimeUtc, new DateTimeZone('UTC')))->modify("-{$minutesBefore} minutes");
        $end   = new DateTime($endTimeUtc, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return true; // unparseable times: do not block the normal path
    }
    return $now >= $start && $now <= $end;
}

/**
 * Milliseconds until the attendance window opens, or 0 if it is already open
 * (or has closed). Used to tell the room when to re-register presence.
 */
function msUntilAttendanceWindow(string $startTimeUtc, int $minutesBefore = 15): int {
    try {
        $now   = getNowUtc();
        $open  = (new DateTime($startTimeUtc, new DateTimeZone('UTC')))->modify("-{$minutesBefore} minutes");
    } catch (Exception $e) {
        return 0;
    }
    $diff = $open->getTimestamp() - $now->getTimestamp();
    return $diff > 0 ? $diff * 1000 : 0;
}

/**
 * Get a human-readable time until/since a lesson
 * 
 * @param string $startTimeUtc The lesson start time in UTC
 * @param string|null $endTimeUtc The lesson end time in UTC (optional)
 * @return string Human-readable string like "In 2h 30m" or "Started 5m ago"
 */
function humanTimeUntilLesson(string $startTimeUtc, ?string $endTimeUtc = null): string {
    $now = getNowUtc();
    $start = new DateTime($startTimeUtc, new DateTimeZone('UTC'));
    
    if ($endTimeUtc) {
        $end = new DateTime($endTimeUtc, new DateTimeZone('UTC'));
        if ($now >= $start && $now <= $end) {
            $remaining = $now->diff($end);
            $h = $remaining->h;
            $m = $remaining->i;
            if ($h === 0 && $m <= 1) return "Ending soon";
            return "Live · " . trim(($h ? "{$h}h " : "") . ($m ? "{$m}m" : "")) . " left";
        }
    }
    
    if ($now >= $start) {
        $diff = $start->diff($now);
        $h = $diff->days * 24 + $diff->h;
        $m = $diff->i;
        if ($h === 0 && $m <= 1) return "Just started";
        if ($h === 0) return "Started {$m}m ago";
        return "Started {$h}h " . ($m ? "{$m}m " : "") . "ago";
    }
    
    $diff = $now->diff($start);
    $totalMin = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;
    
    if ($totalMin < 1) return "Starting now";
    if ($totalMin < 60) return "In {$diff->i}m";
    if ($totalMin < 180) return "In {$diff->h}h " . ($diff->i ? "{$diff->i}m" : "");
    
    // Format in user's local timezone for longer periods
    $localStart = utcToLocal($start);
    if ($diff->days === 0) return "Today at " . $localStart->format('g:i A');
    if ($diff->days === 1) return "Tomorrow at " . $localStart->format('g:i A');
    if ($diff->days < 7) return $localStart->format('l \a\t g:i A');
    
    return $localStart->format('M j \a\t g:i A');
}

/**
 * Get a formatted timezone label for display (e.g., "Asia/Tokyo (UTC+9)")
 * 
 * @param string $timezone The timezone identifier
 * @return string Formatted timezone label
 */
function getTimezoneLabel(string $timezone): string {
    try {
        $tz = new DateTimeZone($timezone);
        $now = new DateTime('now', $tz);
        $offset = $tz->getOffset($now);
        
        $hours = abs(floor($offset / 3600));
        $minutes = abs(($offset % 3600) / 60);
        
        $offsetStr = ($offset >= 0 ? '+' : '-') . str_pad($hours, 2, '0', STR_PAD_LEFT);
        if ($minutes > 0) {
            $offsetStr .= ':' . str_pad($minutes, 2, '0', STR_PAD_LEFT);
        }
        
        return "{$timezone} (UTC{$offsetStr})";
    } catch (Exception $e) {
        return $timezone;
    }
}

/**
 * Get a list of common timezones for dropdowns
 * 
 * @return array Array of timezone identifiers
 */
function getCommonTimezones(): array {
    return [
        // Africa
        'Africa/Blantyre',
        'Africa/Cairo',
        'Africa/Johannesburg',
        'Africa/Lagos',
        'Africa/Nairobi',
        
        // Americas
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/New_York',
        'America/Sao_Paulo',
        'America/Toronto',
        
        // Asia
        'Asia/Bangkok',
        'Asia/Dubai',
        'Asia/Hong_Kong',
        'Asia/Kolkata',
        'Asia/Seoul',
        'Asia/Shanghai',
        'Asia/Singapore',
        'Asia/Tokyo',
        
        // Australia
        'Australia/Melbourne',
        'Australia/Sydney',
        
        // Europe
        'Europe/Amsterdam',
        'Europe/Berlin',
        'Europe/London',
        'Europe/Madrid',
        'Europe/Moscow',
        'Europe/Paris',
        'Europe/Rome',
        
        // Pacific
        'Pacific/Auckland',
        'Pacific/Honolulu',
        
        // UTC
        'UTC',
    ];
}

/**
 * Get all available timezones grouped by region
 * 
 * @return array Grouped array of timezones
 */
function getAllTimezones(): array {
    $timezones = DateTimeZone::listIdentifiers();
    $grouped = [];
    
    foreach ($timezones as $tz) {
        $parts = explode('/', $tz, 2);
        $region = $parts[0];
        
        if (!isset($grouped[$region])) {
            $grouped[$region] = [];
        }
        
        $grouped[$region][] = $tz;
    }
    
    return $grouped;
}

/**
 * Validate a timezone identifier
 * 
 * @param string $timezone The timezone to validate
 * @return bool True if valid
 */
function isValidTimezone(string $timezone): bool {
    return in_array($timezone, DateTimeZone::listIdentifiers(), true);
}

/**
 * Ensure the users table has the timezone column
 * 
 * @param PDO $pdo Database connection
 */
function ensureTimezoneColumn(PDO $pdo): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `users` LIKE 'timezone'");
        $stmt->execute();
        
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `timezone` VARCHAR(64) DEFAULT 'UTC' AFTER `is_active`");
        }
    } catch (PDOException $e) {
        error_log("Failed to ensure timezone column: " . $e->getMessage());
    }
}

/**
 * Load timezone from database for a specific user
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return string|null The user's timezone or null if not found
 */
function loadUserTimezone(PDO $pdo, int $userId): ?string {
    try {
        // First ensure the column exists
        ensureTimezoneColumn($pdo);
        
        $stmt = $pdo->prepare("SELECT timezone FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $tz = $stmt->fetchColumn();
        
        if ($tz && isValidTimezone($tz)) {
            return $tz;
        }
    } catch (PDOException $e) {
        error_log("Failed to load user timezone: " . $e->getMessage());
    }
    
    return null;
}

/**
 * Save timezone for a specific user
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $timezone The timezone identifier
 * @return bool True on success
 */
function saveUserTimezone(PDO $pdo, int $userId, string $timezone): bool {
    if (!isValidTimezone($timezone)) {
        return false;
    }
    
    try {
        // Ensure column exists
        ensureTimezoneColumn($pdo);
        
        $stmt = $pdo->prepare("UPDATE users SET timezone = ? WHERE id = ?");
        return $stmt->execute([$timezone, $userId]);
    } catch (PDOException $e) {
        error_log("Failed to save user timezone: " . $e->getMessage());
        return false;
    }
}

/**
 * Initialize timezone in session from database
 * Call this after successful authentication
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 */
function initializeSessionTimezone(PDO $pdo, int $userId): void {
    $tz = loadUserTimezone($pdo, $userId);
    
    if ($tz) {
        $_SESSION['timezone'] = $tz;
    } else {
        $_SESSION['timezone'] = DEFAULT_TIMEZONE;
    }
}

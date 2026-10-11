<?php
/**
 * Email Configuration
 * 
 */

return [
    // SMTP Server Settings
    'smtp_host' => getenv('SMTP_HOST') ?: 'smtp.hostinger.com',
    'smtp_port' => getenv('SMTP_PORT') ?: 465,
    'smtp_secure' => getenv('SMTP_SECURE') ?: 'ssl',
    
    // SMTP Authentication
    'smtp_username' => getenv('SMTP_USERNAME') ?: '',
    'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
    
    // Email Settings
    'from_email' => getenv('FROM_EMAIL') ?: 'contact@bloomeducationonline.com',
    'from_name' => getenv('FROM_NAME') ?: 'Bloom Education',
    
    // Site Settings
    // Set SITE_URL in .env; this fallback only applies if .env is missing, and email links
    // built from it must point at wherever the portal is actually deployed.
    'site_url' => getenv('SITE_URL') ?: 'http://localhost/tutsys3.1',
    
    // Debug Settings
    'debug_mode' => getenv('DEBUG_MODE') === 'true',
];

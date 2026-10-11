<?php
/**
 * Simple Email Test (Non-interactive)
 * 
 * Usage: php test_email_simple.php your-email@example.com
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/email_helper.php';

// Get email from command line argument or use default
$testEmail = $argv[1] ?? 'contact@bloomeducationonline.com';

echo "========================================\n";
echo "Simple Email Test\n";
echo "========================================\n\n";

echo "Sending test email to: $testEmail\n";
echo "Please wait (this may take up to 30 seconds)...\n\n";

// Set timeout
set_time_limit(30);

// Load email configuration
$config = require __DIR__ . '/../config/email_config.php';

if (empty($config['smtp_username']) || empty($config['smtp_password'])) {
    echo "✗ Email configuration is incomplete!\n";
    exit(1);
}

// Send test email
$testSubject = "Test Email from TutSys Daemon - " . date('Y-m-d H:i:s');
$testHtml = "<h1>Test Email</h1><p>This is a test email from the TutSys notification daemon.</p><p>Sent at: " . date('Y-m-d H:i:s') . "</p><p>If you received this, your email configuration is working correctly!</p>";
$testPlain = "Test Email\n\nThis is a test email from the TutSys notification daemon.\nSent at: " . date('Y-m-d H:i:s') . "\n\nIf you received this, your email configuration is working correctly!";

$startTime = microtime(true);

try {
    $result = send_email($testEmail, "Test Recipient", $testSubject, $testHtml, $testPlain);
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    echo "Email sending took: {$duration} seconds\n\n";
    
    if ($result['success']) {
        echo "✓ SUCCESS: Test email sent!\n";
        echo "  Check your inbox (and spam/junk folder) for the email.\n";
        echo "  Subject: $testSubject\n";
    } else {
        echo "✗ FAILED: Could not send email\n";
        echo "  Error: " . $result['message'] . "\n\n";
        echo "Common issues:\n";
        echo "  - SMTP_PASSWORD must be the MAILBOX password from hPanel > Emails\n";
        echo "  - FROM_EMAIL must equal SMTP_USERNAME or Hostinger will not relay\n";
        echo "  - Firewall blocking SMTP port 465 (try 587 with SMTP_SECURE=tls)\n";
        echo "  - SMTP server not accessible from hosting\n";
        echo "  - Wrong SMTP credentials\n";
    }
} catch (Exception $e) {
    echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
    echo "  Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n";

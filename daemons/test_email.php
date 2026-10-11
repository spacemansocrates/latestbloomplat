<?php
/**
 * Test Email Configuration
 * 
 * This script tests if email configuration is working.
 * Usage: php test_email.php
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/email_helper.php';

echo "========================================\n";
echo "Email Configuration Test\n";
echo "========================================\n\n";

// Load email configuration
$config = require __DIR__ . '/../config/email_config.php';

echo "1. Email Configuration:\n";
echo "   SMTP Host: " . $config['smtp_host'] . "\n";
echo "   SMTP Port: " . $config['smtp_port'] . "\n";
echo "   SMTP Secure: " . $config['smtp_secure'] . "\n";
echo "   SMTP Username: " . ($config['smtp_username'] ?: 'NOT SET') . "\n";
echo "   SMTP Password: " . ($config['smtp_password'] ? '***SET***' : 'NOT SET') . "\n";
echo "   From Email: " . $config['from_email'] . "\n";
echo "   From Name: " . $config['from_name'] . "\n\n";

// Check if configuration is complete
if (empty($config['smtp_username']) || empty($config['smtp_password'])) {
    echo "✗ Email configuration is INCOMPLETE!\n\n";
    echo "You need to set SMTP credentials in your .env file:\n";
    echo "  SMTP_HOST=smtp.hostinger.com\n";
    echo "  SMTP_PORT=465\n";
    echo "  SMTP_SECURE=ssl\n";
    echo "  SMTP_USERNAME=support@bloomeducationonline.com\n";
    echo "  SMTP_PASSWORD=<the mailbox password>\n";
    echo "  FROM_EMAIL=support@bloomeducationonline.com\n";
    echo "  FROM_NAME=Bloom Education\n\n";
    echo "Notes:\n";
    echo "  1. FROM_EMAIL must equal SMTP_USERNAME, or Hostinger refuses to relay.\n";
    echo "  2. SMTP_PASSWORD is the MAILBOX password from hPanel > Emails,\n";
    echo "     not the Hostinger account password.\n";
    echo "  3. Port 587 with SMTP_SECURE=tls also works if 465 is blocked.\n\n";
    exit(1);
}

echo "✓ Email configuration looks complete\n\n";

// Ask for test email
echo "2. Test Email Sending:\n";
echo "Enter an email address to send a test email to:\n";
$testEmail = trim(fgets(STDIN));

if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo "✗ Invalid email address\n";
    exit(1);
}

echo "\nSending test email to: $testEmail\n";

// Send test email
$testSubject = "Test Email from TutSys Daemon";
$testHtml = "<h1>Test Email</h1><p>This is a test email from the TutSys notification daemon.</p><p>If you received this, your email configuration is working correctly!</p>";
$testPlain = "Test Email\n\nThis is a test email from the TutSys notification daemon.\n\nIf you received this, your email configuration is working correctly!";

$result = send_email($testEmail, "Test Recipient", $testSubject, $testHtml, $testPlain);

if ($result['success']) {
    echo "✓ Test email sent successfully!\n";
    echo "  Check your inbox (and spam folder) for the test email.\n";
} else {
    echo "✗ Failed to send test email:\n";
    echo "  " . $result['message'] . "\n\n";
    echo "Common issues:\n";
    echo "  - Wrong SMTP credentials\n";
    echo "  - SMTP_PASSWORD must be the MAILBOX password from hPanel > Emails\n";
    echo "  - Firewall blocking SMTP port\n";
    echo "  - SMTP server not accessible from hosting\n";
}

echo "\n";

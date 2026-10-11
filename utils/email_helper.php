<?php
/**
 * Email Helper Utility
 * 
 * Provides a simple interface for sending emails using PHPMailer.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/env_loader.php';

// Load environment variables
load_env(__DIR__ . '/../.env');


/**
 * Send an email
 * 
 * @param string $to Recipient email address
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param string $plainBody Plain text email body (fallback)
 * @param array  $embedded  Optional inline images, each ['cid', 'data', 'name', 'type'],
 *                          referenced from the HTML as src="cid:<cid>"
 * @return array ['success' => bool, 'message' => string]
 */
function send_email($to, $toName, $subject, $htmlBody, $plainBody = '', array $embedded = []) {
    // Load email configuration
    $config = require __DIR__ . '/../config/email_config.php';
    
    // Validate configuration
    if (empty($config['smtp_username']) || empty($config['smtp_password']) || empty($config['from_email'])) {
        return [
            'success' => false,
            'message' => 'Email configuration is incomplete. Please fill in SMTP credentials in config/email_config.php'
        ];
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        if ($config['debug_mode']) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        }
        
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        
        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to, $toName);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody ?: strip_tags($htmlBody);
        foreach ($embedded as $img) {
            $mail->addStringEmbeddedImage($img['data'], $img['cid'], $img['name'] ?? $img['cid'], 'base64', $img['type'] ?? 'image/png');
        }
        
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Email sent successfully'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Email could not be sent. Error: {$mail->ErrorInfo}"
        ];
    }
}

/**
 * Build lesson notification email HTML
 * 
 * @param array $data Lesson data (student_name, subject_name, tutor_name, start_time, lesson_id)
 * @return string HTML email body
 */
function build_lesson_notification_html($data) {
    $config = require __DIR__ . '/../config/email_config.php';
    $siteUrl = rtrim($config['site_url'], '/');
    $joinUrl = $siteUrl . '/student/api/join_lesson.php?lesson_id=' . $data['lesson_id'];
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 10px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px;">Your Lesson Starts Soon!</h1>
                        </td>
                    </tr>
                    
                    <!-- Body -->
                    <tr>
                        <td style="padding: 30px 30px;">
                            <p style="color: #333333; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
                                Hi <strong>{$data['student_name']}</strong>,
                            </p>
                            <p style="color: #333333; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0;">
                                This is a friendly reminder that your lesson is starting soon:
                            </p>
                            
                            <!-- Lesson Details Box -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8f9fa; border-radius: 6px; margin-bottom: 30px;">
                                <tr>
                                    <td style="padding: 10px;">
                                        <table width="100%" cellpadding="6" cellspacing="0">
                                            <tr>
                                                <td style="color: #666666; font-size: 14px; width: 100px;"><strong>Subject:</strong></td>
                                                <td style="color: #333333; font-size: 14px;">{$data['subject_name']}</td>
                                            </tr>
                                            <tr>
                                                <td style="color: #666666; font-size: 14px;"><strong>Tutor:</strong></td>
                                                <td style="color: #333333; font-size: 14px;">{$data['tutor_name']}</td>
                                            </tr>
                                            <tr>
                                                <td style="color: #666666; font-size: 14px;"><strong>Start Time:</strong></td>
                                                <td style="color: #333333; font-size: 14px;">{$data['start_time']}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Join Button -->
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="text-center" style="padding: 14px 0;">
                                        <a href="{$joinUrl}" 
                                           style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                                  color: #ffffff; text-decoration: none; padding: 15px 40px; 
                                                  border-radius: 6px; font-size: 16px; font-weight: bold; 
                                                  box-shadow: 0 4px 6px rgba(102, 126, 234, 0.3);">
                                            Join Lesson Now
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="color: #666666; font-size: 14px; line-height: 1.6; margin: 12px 0 0 0; text-align: center;">
                                You'll be prompted to log in if you're not already signed in.
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-top: 1px solid #e9ecef;">
                            <p style="color: #666666; font-size: 14px; margin: 0;">
                                Best regards,<br>
                                <strong>The Bloom Team</strong>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Build lesson notification plain text
 * 
 * @param array $data Lesson data (student_name, subject_name, tutor_name, start_time, lesson_id)
 * @return string Plain text email body
 */
function build_lesson_notification_plain($data) {
    $config = require __DIR__ . '/../config/email_config.php';
    $siteUrl = rtrim($config['site_url'], '/');
    $joinUrl = $siteUrl . '/student/api/join_lesson.php?lesson_id=' . $data['lesson_id'];
    
    return <<<TEXT
Hi {$data['student_name']},

This is a friendly reminder that your lesson is starting soon:

Subject: {$data['subject_name']}
Tutor: {$data['tutor_name']}
Start Time: {$data['start_time']}

Click here to join your lesson:
{$joinUrl}

(You'll be prompted to log in if you're not already signed in)

Best regards,
The Bloom Team
TEXT;
}

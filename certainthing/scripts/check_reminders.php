<?php
/**
 * Cron script to check for user reminders
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/email_helper.php';

$users = safe_read_json(USERS_FILE);
$updated = false;
$now = new DateTime();

foreach ($users as &$user) {
    if (!isset($user['email'])) continue;
    
    $tier = $user['tier'] ?? 'free';
    $registration_date = new DateTime($user['created_at']);
    $last_change_date = new DateTime($user['last_tier_change_at'] ?? $user['created_at']);
    $reminders_sent = $user['reminders_sent'] ?? [];

    if ($tier === 'free') {
        $days_since_reg = $now->diff($registration_date)->days;
        
        // 5th day reminder
        if ($days_since_reg >= 5 && !in_array('free_5', $reminders_sent)) {
            $subject = "Your " . APP_NAME . " Journey: Day 5";
            $message = "Hi " . htmlspecialchars($user['email']) . ",<br><br>" .
                       "You've been using our free tier for 5 days. We hope you're enjoying " . APP_NAME . "!<br>" .
                       "Consider upgrading to our paid tier for more features.";
            if (send_email($user['email'], $subject, $message)) {
                $reminders_sent[] = 'free_5';
                $updated = true;
            }
        }
        
        // 6th day reminder
        if ($days_since_reg >= 6 && !in_array('free_6', $reminders_sent)) {
            $subject = "Maximize your productivity with " . APP_NAME;
            $message = "Hi " . htmlspecialchars($user['email']) . ",<br><br>" .
                       "It's day 6 of your free tier. Have you explored all our features yet?<br>" .
                       "Upgrade now to unlock the full potential.";
            if (send_email($user['email'], $subject, $message)) {
                $reminders_sent[] = 'free_6';
                $updated = true;
            }
        }
    } elseif ($tier === 'paid') {
        $days_since_change = $now->diff($last_change_date)->days;

        // 20th day reminder
        if ($days_since_change >= 20 && !in_array('paid_20', $reminders_sent)) {
            $subject = "How is your " . APP_NAME . " experience?";
            $message = "Hi " . htmlspecialchars($user['email']) . ",<br><br>" .
                       "You've been a valued paid member for 20 days now. We'd love to hear your feedback!";
            if (send_email($user['email'], $subject, $message)) {
                $reminders_sent[] = 'paid_20';
                $updated = true;
            }
        }

        // 25th day reminder
        if ($days_since_change >= 25 && !in_array('paid_25', $reminders_sent)) {
            $subject = "Exclusive tips for our power users";
            $message = "Hi " . htmlspecialchars($user['email']) . ",<br><br>" .
                       "25 days as a paid member! Here are some tips to get even more out of " . APP_NAME . ".";
            if (send_email($user['email'], $subject, $message)) {
                $reminders_sent[] = 'paid_25';
                $updated = true;
            }
        }
    }
    
    $user['reminders_sent'] = $reminders_sent;
}

if ($updated) {
    safe_write_json(USERS_FILE, $users);
    echo "[" . date('Y-m-d H:i:s') . "] Reminders sent and users.json updated.\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] No reminders were due.\n";
}

<?php
/**
 * Configuration & Helpers
 */

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session start if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global settings
define('APP_NAME', 'CertainThing');
define('DATA_DIR', __DIR__ . '/../data');
define('SESSIONS_DIR', DATA_DIR . '/sessions');
define('USERS_FILE', DATA_DIR . '/users.json');
define('PROMPTS_DIR', __DIR__ . '/../prompts');
define('OPENAI_KEY_FILE', DATA_DIR . '/openai_api_key.txt');

/**
 * SSE Helper: Send event to client
 */
function send_event($type, $payload = '') {
    if (!is_array($payload)) {
        $payload = ['text' => (string) $payload];
    }

    $payload['type'] = $type;
    echo "data: " . json_encode($payload) . "\n\n";

    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * Process account control for a user (check expiry, send reminders)
 * Returns the updated user array or null if user not found
 */
function process_account_control($user_id) {
    require_once __DIR__ . '/email_helper.php';

    $users = safe_read_json(USERS_FILE);
    $updated = false;
    $result_user = null;

    foreach ($users as &$user) {
        if ($user['id'] !== $user_id) {
            continue;
        }

        $tier = $user['tier'] ?? 'free';
        $status = $user['status'] ?? 'enabled';
        $reminders_sent = $user['reminders_sent'] ?? [];
        $now = new DateTime();

        $registration_date = new DateTime($user['created_at']);
        $last_change_date = new DateTime($user['last_tier_change_at'] ?? $user['created_at']);

        if ($tier === 'free') {
            $days_since_reg = $now->diff($registration_date)->days;

            // Disable if > 7 days since registration
            if ($days_since_reg > 7 && $status === 'enabled') {
                $user['status'] = 'disabled';
                $updated = true;
            }

            // Send reminder on day 5
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

            // Send reminder on day 6
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

            // Disable if > 31 days since last tier change
            if ($days_since_change > 31 && $status === 'enabled') {
                $user['status'] = 'disabled';
                $updated = true;
            }

            // Send reminder on day 20
            if ($days_since_change >= 20 && !in_array('paid_20', $reminders_sent)) {
                $subject = "How is your " . APP_NAME . " experience?";
                $message = "Hi " . htmlspecialchars($user['email']) . ",<br><br>" .
                           "You've been a valued paid member for 20 days now. We'd love to hear your feedback!";
                if (send_email($user['email'], $subject, $message)) {
                    $reminders_sent[] = 'paid_20';
                    $updated = true;
                }
            }

            // Send reminder on day 25
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
        $result_user = $user;
        break;
    }

    if ($updated) {
        safe_write_json(USERS_FILE, $users);
        // Re-read to return fresh data
        $users = safe_read_json(USERS_FILE);
        foreach ($users as $u) {
            if ($u['id'] === $user_id) {
                $result_user = $u;
                break;
            }
        }
    }

    return $result_user;
}

/**
 * Check if user is authenticated
 */
function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Process account control (check expiry, send reminders)
    $user = process_account_control($_SESSION['user_id']);

    if ($user === null) {
        session_destroy();
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    if ($user['status'] !== 'enabled') {
        session_destroy();
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Account disabled']);
        exit;
    }
}

/**
 * Update user tier
 */
function update_user_tier($user_id, $new_tier) {
    $users = safe_read_json(USERS_FILE);
    $updated = false;
    foreach ($users as &$user) {
        if ($user['id'] === $user_id) {
            $current_tier = $user['tier'] ?? 'free';
            if ($current_tier !== $new_tier) {
                $user['tier'] = $new_tier;
                $user['last_tier_change_at'] = date('c');
                $user['reminders_sent'] = []; // Reset reminders on tier change
                $updated = true;
            }
            break;
        }
    }
    if ($updated) {
        return safe_write_json(USERS_FILE, $users);
    }
    return false;
}

/**
 * Update user status
 */
function update_user_status($user_id, $new_status) {
    $users = safe_read_json(USERS_FILE);
    $updated = false;
    foreach ($users as &$user) {
        if ($user['id'] === $user_id) {
            $current_status = $user['status'] ?? 'enabled';
            if ($current_status !== $new_status) {
                $user['status'] = $new_status;
                $updated = true;
            }
            break;
        }
    }
    if ($updated) {
        return safe_write_json(USERS_FILE, $users);
    }
    return false;
}

/**
 * Get currently configured OpenAI API key
 */
function get_openai_api_key() {
    if (file_exists(OPENAI_KEY_FILE)) {
        $fileKey = trim((string) @file_get_contents(OPENAI_KEY_FILE));
        if ($fileKey !== '') {
            return $fileKey;
        }
    }

    return trim((string) getenv('OPENAI_API_KEY'));
}

/**
 * Save OpenAI API key to server-side file
 */
function save_openai_api_key($apiKey) {
    $apiKey = trim((string) $apiKey);

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }

    if ($apiKey === '') {
        if (file_exists(OPENAI_KEY_FILE)) {
            return unlink(OPENAI_KEY_FILE);
        }
        return true;
    }

    $bytes = file_put_contents(OPENAI_KEY_FILE, $apiKey, LOCK_EX);
    if ($bytes === false) {
        return false;
    }

    @chmod(OPENAI_KEY_FILE, 0600);
    return true;
}

/**
 * Safe file read with FLOCK
 */
function safe_read_json($file) {
    if (!file_exists($file)) return [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $size = filesize($file);
    $content = $size > 0 ? fread($fp, $size) : '[]';
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($content, true) ?: [];
}

/**
 * Safe file write with FLOCK
 */
function safe_write_json($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $fp = fopen($file, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

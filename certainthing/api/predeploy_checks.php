<?php
/**
 * Pre-deploy checks: catch PHP/JS syntax errors before generated code is written
 * to a live-viewable deploy folder or saved into an agentic workspace file that
 * later gets copied there verbatim.
 *
 * Real syntax check when the host allows it: writes the content to a temp file and
 * runs `php -l` via shell_exec(). Read-only syntax check — it parses the file
 * and reports, it never executes the content.
 *
 * Many shared hosts disable shell_exec/exec. When the real checker isn't available:
 *   - PHP: skipped (no safe zero-dependency PHP parser exists without executing
 *     the code, and eval() is not an option for untrusted generated content).
 *   - JS: always uses a string/comment-aware bracket-balance heuristic (no
 *     external binary dependency, so behavior is identical across hosts). It
 *     cannot catch everything a real parser would, but it does catch the
 *     unbalanced-brace/unterminated-string class of corruption that has broken
 *     this app's live JS before.
 *
 * Returned shape per file: ['status' => 'ok'|'error'|'warning'|'skipped', 'message' => string|null]
 * - 'error'   => real parser found a definite syntax error. Caller should block the write/deploy.
 * - 'warning' => heuristic fallback found something suspicious. Advisory only, never blocking
 *                (false positives are possible — e.g. braces inside a template literal edge case).
 * - 'skipped' => no checker was available for this file/host. Not an error.
 * - 'ok'      => passed.
 */

function predeploy_shell_exec_available(): bool {
    if (!function_exists('shell_exec')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('shell_exec', $disabled, true);
}

function predeploy_check_php(string $content): array {
    if (!predeploy_shell_exec_available()) {
        return ['status' => 'skipped', 'message' => 'PHP syntax check unavailable on this host (shell_exec disabled).'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'predeploy_php_');
    if ($tmp === false) {
        return ['status' => 'skipped', 'message' => 'Could not create a temp file for the syntax check.'];
    }
    $tmpPhp = $tmp . '.php';
    rename($tmp, $tmpPhp);
    file_put_contents($tmpPhp, $content);
    $out = @shell_exec('php -l ' . escapeshellarg($tmpPhp) . ' 2>&1');
    @unlink($tmpPhp);

    if ($out === null) {
        return ['status' => 'skipped', 'message' => 'PHP syntax check did not run (php binary not found on this host).'];
    }
    if (stripos($out, 'No syntax errors detected') !== false) {
        return ['status' => 'ok', 'message' => null];
    }
    $lines = preg_split('/\r?\n/', trim($out));
    return ['status' => 'error', 'message' => $lines[0] ?? trim($out)];
}

function predeploy_heuristic_js_balance(string $content): array {
    $pairs   = ['(' => ')', '[' => ']', '{' => '}'];
    $closers = array_flip($pairs);
    $stack   = [];
    $len     = strlen($content);
    $inString      = null; // ' " ` or null
    $inLineComment = false;
    $inBlockComment = false;
    $line = 1;

    for ($i = 0; $i < $len; $i++) {
        $ch   = $content[$i];
        $next = $i + 1 < $len ? $content[$i + 1] : '';
        if ($ch === "\n") { $line++; }

        if ($inLineComment) {
            if ($ch === "\n") $inLineComment = false;
            continue;
        }
        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') { $inBlockComment = false; $i++; }
            continue;
        }
        if ($inString !== null) {
            if ($ch === '\\') { $i++; continue; } // skip escaped char
            if ($ch === $inString) { $inString = null; }
            continue;
        }
        if ($ch === '/' && $next === '/') { $inLineComment = true; $i++; continue; }
        if ($ch === '/' && $next === '*') { $inBlockComment = true; $i++; continue; }
        if ($ch === '"' || $ch === "'" || $ch === '`') { $inString = $ch; continue; }

        if (isset($pairs[$ch])) {
            $stack[] = ['ch' => $ch, 'line' => $line];
        } elseif (isset($closers[$ch])) {
            if (empty($stack)) {
                return ['status' => 'warning', 'message' => "Unmatched closing '{$ch}' near line {$line} (heuristic check — advisory)."];
            }
            $top = array_pop($stack);
            if ($closers[$ch] !== $top['ch']) {
                return ['status' => 'warning', 'message' => "Mismatched '{$top['ch']}' opened at line {$top['line']}, closed with '{$ch}' at line {$line} (heuristic check — advisory)."];
            }
        }
    }

    if (!empty($stack)) {
        $top = end($stack);
        return ['status' => 'warning', 'message' => "Unclosed '{$top['ch']}' opened at line {$top['line']} (heuristic check — advisory)."];
    }
    if ($inString !== null) {
        return ['status' => 'warning', 'message' => 'Unterminated string/template literal (heuristic check — advisory).'];
    }
    return ['status' => 'ok', 'message' => null];
}

function predeploy_check_js(string $content): array {
    return predeploy_heuristic_js_balance($content);
}

/**
 * Dispatches by file extension. Non-PHP/JS files (html, css, json, images, ...)
 * always come back 'ok' — this function only ever gates the two languages that
 * hard-fail the whole page/request on a syntax error.
 */
function predeploy_check_file(string $name, string $content): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'php') return predeploy_check_php($content);
    if ($ext === 'js')  return predeploy_check_js($content);
    return ['status' => 'ok', 'message' => null];
}

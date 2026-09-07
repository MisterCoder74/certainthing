<?php
/**
 * Agentic Workspace — shared helpers.
 *
 * Path-safety mirrors deploy/deploy_manager.php's dm_sanitizePath()/dm_isInsideBase()
 * so the sandbox gets the same "no traversal, must resolve inside root" guarantee
 * already proven on the deploy folder. Everything here is pure filesystem logic —
 * no session/auth handling (callers already ran check_auth() via config.php).
 */

/**
 * Strip null bytes and any "." / ".." segments from a user-supplied relative path.
 */
function agentic_sanitize_path(string $path): string {
    $path = str_replace("\0", '', $path);
    $parts = explode('/', str_replace('\\', '/', $path));
    $clean = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') continue;
        $clean[] = $p;
    }
    return implode('/', $clean);
}

/**
 * True only if $target resolves (via realpath) to a path inside $baseDir.
 * Both must already exist on disk — this is a post-resolution check, not a guard
 * against a path that doesn't exist yet.
 */
function agentic_is_inside_base(string $target, string $baseDir): bool {
    $realBase = realpath($baseDir);
    if ($realBase === false) return false;
    $realTarget = realpath($target);
    if ($realTarget === false) return false;
    return str_starts_with($realTarget . '/', $realBase . '/') || $realTarget === $realBase;
}

/**
 * Zip-slip guard for a single archive entry name, applied BEFORE extraction.
 * Rejects: absolute paths, Windows drive letters, and any ".." segment once
 * back-slashes are normalized to forward slashes.
 */
function agentic_zip_entry_is_safe(string $entryName): bool {
    if ($entryName === '') return false;
    if (str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) return false;
    if (preg_match('#^[A-Za-z]:#', $entryName)) return false;
    $normalized = str_replace('\\', '/', $entryName);
    foreach (explode('/', $normalized) as $part) {
        if ($part === '..') return false;
    }
    return true;
}

function agentic_new_id(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Phase E lifecycle: stamps "last_activity_at" on a workspace's .agentic_meta.json,
 * called from every endpoint that actually does something with an existing workspace
 * (open the tree, save/rename/move/delete a file, vibecode, deploy, push, view a served
 * page). $baseDir is the workspace root (already validated by the caller). Silently
 * no-ops if the meta file is missing/unreadable — this is a liveness signal, not a
 * source of truth worth failing a request over.
 */
function agentic_touch_workspace(string $baseDir): void {
    $metaFile = $baseDir . '/.agentic_meta.json';
    $meta = is_file($metaFile) ? safe_read_json($metaFile) : null;
    if (!is_array($meta)) return;
    $meta['last_activity_at'] = date('c');
    safe_write_json($metaFile, $meta);
}

/**
 * Phase E lifecycle: sweeps ONE user's own sandbox area (never touches other users —
 * callers pass this user's own $workspaceRoot/$stagingDir, resolved the same way every
 * other agentic_* endpoint scopes to $user_id). Two independent cleanups:
 *   - confirmed workspaces whose last_activity_at (falling back to created_at, and to
 *     the directory's own mtime if the meta file is somehow missing) is older than
 *     AGENTIC_WORKSPACE_TTL_DAYS get deleted outright — an abandoned sandbox, its
 *     purpose already served.
 *   - staged .zip files (uploaded but never "confirm"ed or "cancel"ed) older than
 *     AGENTIC_STAGING_TTL_HOURS get deleted — these are meant to be a short detour
 *     between upload and the user's confirm/cancel click, not persistent storage.
 * Runs lazily off the "list" action (no cron / scheduled job available on shared
 * hosting) so it self-triggers whenever the user opens the workspace picker, at the
 * cost of at most one skipped sweep if nobody opens it for a while — an acceptable
 * trade-off for a cleanup pass, not a correctness-critical one.
 * Returns the count of workspaces actually deleted (staging purges aren't counted —
 * they're invisible in the UI either way).
 */
function agentic_sweep_expired_workspaces(string $workspaceRoot, string $stagingDir): int {
    $deleted = 0;
    $now = time();

    if (is_dir($workspaceRoot)) {
        $ttlSeconds = AGENTIC_WORKSPACE_TTL_DAYS * 86400;
        foreach (scandir($workspaceRoot) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $dir = $workspaceRoot . '/' . $entry;
            if (!is_dir($dir)) continue;
            $metaFile = $dir . '/.agentic_meta.json';
            $meta = is_file($metaFile) ? safe_read_json($metaFile) : null;
            $stamp = is_array($meta) ? ($meta['last_activity_at'] ?? $meta['created_at'] ?? null) : null;
            $lastActivity = $stamp !== null ? strtotime($stamp) : filemtime($dir);
            if ($lastActivity !== false && ($now - $lastActivity) > $ttlSeconds) {
                agentic_rrmdir($dir);
                $deleted++;
            }
        }
    }

    if (is_dir($stagingDir)) {
        $ttlSeconds = AGENTIC_STAGING_TTL_HOURS * 3600;
        foreach (scandir($stagingDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $stagingDir . '/' . $entry;
            if (!is_file($path)) continue;
            $mtime = filemtime($path);
            if ($mtime !== false && ($now - $mtime) > $ttlSeconds) {
                unlink($path);
            }
        }
    }

    return $deleted;
}

function agentic_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
    }
    rmdir($dir);
}

function agentic_mime_for_ext(string $ext): string {
    $map = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'txt'  => 'text/plain',
        'md'   => 'text/plain',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Recursively builds a { type, name, path, children/size } tree for the file-tree UI
 * (Phase B). $dir is the absolute directory being walked; $baseDir stays fixed at the
 * workspace root so every node's "path" is relative and safe to round-trip back into
 * agentic_sanitize_path() on the next request.
 */
function agentic_build_tree(string $dir, string $baseDir): array {
    $result = [];
    if (!is_dir($dir)) return $result;
    $entries = scandir($dir);
    natcasesort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($entry === '.agentic_meta.json') continue; // internal bookkeeping, hidden from the tree
        $full = $dir . '/' . $entry;
        $rel  = ltrim(substr($full, strlen($baseDir)), '/');
        if (is_dir($full)) {
            $result[] = [
                'type'     => 'folder',
                'name'     => $entry,
                'path'     => $rel,
                'children' => agentic_build_tree($full, $baseDir),
            ];
        } else {
            $result[] = [
                'type' => 'file',
                'name' => $entry,
                'path' => $rel,
                'size' => filesize($full),
            ];
        }
    }
    return $result;
}

/**
 * Resolves a relative reference found in a served page (an <a href>, <link href>,
 * <script src>, ... or a CSS url()) against the workspace-relative directory the
 * CURRENT file lives in, and returns the resulting workspace-relative path.
 *
 * Returns null for anything that must NOT be rewritten: absolute URLs (scheme://,
 * protocol-relative //), data:/mailto:/tel:/javascript:/about: URIs, and bare
 * fragments (#section). A leading "/" is treated as workspace-root-relative (the
 * page author's own site root), not a real filesystem absolute path.
 *
 * Any query string or fragment on the reference itself is dropped — the resulting
 * path is a plain file lookup key for agentic_serve_test.php, not a forwarded
 * request, so "page.php?x=1" resolves to "page.php".
 */
function agentic_resolve_relative_ref(string $ref, string $currentDir): ?string {
    $ref = trim($ref);
    if ($ref === '' || $ref[0] === '#') return null;
    if (preg_match('#^([a-z][a-z0-9+.\-]*:|//)#i', $ref)) return null; // scheme:// or protocol-relative
    if (preg_match('#^(data|mailto|tel|javascript|about):#i', $ref)) return null;

    // Strip query/fragment — this endpoint resolves a file path, not a request.
    $path = preg_replace('~[?#].*$~', '', $ref);
    if ($path === '') return null;

    $rooted = ($path[0] === '/');
    $baseSegs = $rooted ? [] : ($currentDir === '' ? [] : explode('/', $currentDir));
    $refSegs  = explode('/', ltrim($path, '/'));

    $stack = $baseSegs;
    foreach ($refSegs as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { if (!empty($stack)) array_pop($stack); continue; }
        $stack[] = $seg;
    }
    if (empty($stack)) return null;
    return implode('/', $stack);
}

/**
 * Rewrites href/src on <a>/<link>/<script>/<img>/<source>/<iframe> tags, and url(...)
 * inside inline <style> blocks, so relative references in a served sandbox page point
 * back through agentic_serve_test.php instead of resolving against that endpoint's
 * OWN url (which is what the browser does by default — the served page isn't at a
 * URL that mirrors its real location in the workspace, so its own relative CSS/JS/
 * image references would otherwise 404). $currentDir is the workspace-relative
 * directory of the file being served ('' for workspace root).
 */
function agentic_rewrite_relative_refs(string $html, string $scriptUrl, string $workspaceId, string $currentDir, bool $harnessOn): string {
    $buildUrl = function (string $resolved) use ($scriptUrl, $workspaceId, $harnessOn): string {
        return htmlspecialchars(
            $scriptUrl . '?workspace_id=' . urlencode($workspaceId) . '&file=' . urlencode($resolved) . '&harness=' . ($harnessOn ? '1' : '0'),
            ENT_QUOTES
        );
    };

    $rewriteAttr = function (array $m) use ($currentDir, $buildUrl): string {
        $tag = $m[0];
        return preg_replace_callback(
            '/\s(href|src)(\s*=\s*)(["\'])(.*?)\3/i',
            function (array $am) use ($currentDir, $buildUrl): string {
                $resolved = agentic_resolve_relative_ref(html_entity_decode($am[4]), $currentDir);
                if ($resolved === null) return $am[0];
                return ' ' . $am[1] . $am[2] . $am[3] . $buildUrl($resolved) . $am[3];
            },
            $tag
        );
    };
    $html = preg_replace_callback('/<(a|link|script|img|source|iframe)\b[^>]*>/i', $rewriteAttr, $html);

    $html = preg_replace_callback(
        '/<style\b[^>]*>([\s\S]*?)<\/style>/i',
        function (array $sm) use ($currentDir, $buildUrl): string {
            $css = preg_replace_callback(
                '/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
                function (array $um) use ($currentDir, $buildUrl): string {
                    $resolved = agentic_resolve_relative_ref(html_entity_decode($um[2]), $currentDir);
                    if ($resolved === null) return $um[0];
                    return 'url(' . $buildUrl($resolved) . ')';
                },
                $sm[1]
            );
            return '<style>' . $css . '</style>';
        },
        $html
    );

    return $html;
}


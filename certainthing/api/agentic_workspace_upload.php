<?php
/**
 * API: Agentic Workspace — ZIP staging, confirm-extract, cancel, list.
 * Location: ./api/agentic_workspace_upload.php
 *
 * Implements the confirmed workflow (L5 zip_sandbox_workspace_spec):
 *   a) user attaches a .zip
 *   b) app warns extraction is required
 *   c) user confirms or cancels
 *   d) on confirm, the zip is extracted into a per-user temporary workspace
 * Nothing here ever writes into a normal chat session or the production
 * deploy/ path (sandbox_isolation_directive) — this endpoint only ever
 * touches AGENTIC_DIR/{user_id}/...
 *
 * Actions (POST unless noted):
 *   stage    — multipart upload of field "zip"; previews entries, does NOT extract
 *   confirm  — { staging_id } extracts a previously staged zip into a new workspace
 *   cancel   — { staging_id } discards a staged (not yet extracted) zip
 *   list     — (GET) this user's existing workspaces, for a resume UI / Phase E cleanup
 */

ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agentic_helpers.php';
check_auth();

header('Content-Type: application/json');

const AGENTIC_MAX_ZIP_BYTES = 25 * 1024 * 1024; // 25MB — adjust to hosting upload limits
const AGENTIC_MAX_ENTRIES   = 2000;

$user_id       = $_SESSION['user_id'];
$userRoot      = rtrim(AGENTIC_DIR, '/') . '/' . $user_id;
$stagingDir    = $userRoot . '/staging';
$workspaceRoot = $userRoot . '/workspaces';

function agentic_fail(int $code, string $msg): void {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'stage': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['zip'])) {
            agentic_fail(400, 'A .zip file upload is required.');
        }
        $uploaded = $_FILES['zip'];
        if ($uploaded['error'] !== UPLOAD_ERR_OK) {
            agentic_fail(400, 'Upload failed (code ' . $uploaded['error'] . ').');
        }
        if ($uploaded['size'] > AGENTIC_MAX_ZIP_BYTES) {
            agentic_fail(413, 'Zip exceeds the ' . (AGENTIC_MAX_ZIP_BYTES / 1048576) . 'MB limit.');
        }
        $origName = $uploaded['name'] ?? 'upload.zip';
        if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'zip') {
            agentic_fail(400, 'Only .zip files are accepted.');
        }

        if (!is_dir($stagingDir)) mkdir($stagingDir, 0700, true);
        $stagingId  = agentic_new_id();
        $stagedPath = $stagingDir . '/' . $stagingId . '.zip';

        if (!move_uploaded_file($uploaded['tmp_name'], $stagedPath)) {
            agentic_fail(500, 'Could not save the uploaded zip.');
        }

        $zip = new ZipArchive();
        if ($zip->open($stagedPath) !== true) {
            unlink($stagedPath);
            agentic_fail(400, 'That file is not a valid zip archive.');
        }

        if ($zip->numFiles > AGENTIC_MAX_ENTRIES) {
            $zip->close();
            unlink($stagedPath);
            agentic_fail(413, 'Zip has too many entries (' . $zip->numFiles . ', max ' . AGENTIC_MAX_ENTRIES . ').');
        }

        $preview = [];
        $unsafe  = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!agentic_zip_entry_is_safe($name)) {
                $unsafe[] = $name;
                continue;
            }
            if (count($preview) < 500) { // cap preview payload size, not the extraction itself
                $preview[] = $name;
            }
        }
        $entryCount = $zip->numFiles;
        $zip->close();

        if (!empty($unsafe)) {
            unlink($stagedPath);
            agentic_fail(400, 'Zip rejected: unsafe entry path detected ("' . $unsafe[0] . '").');
        }

        ob_end_clean();
        echo json_encode([
            'staging_id'  => $stagingId,
            'source_name' => $origName,
            'entry_count' => $entryCount,
            'preview'     => $preview,
            'truncated'   => $entryCount > count($preview),
            'warning'     => 'This will be extracted into a temporary sandbox workspace, separate from your normal session. Confirm to proceed, or cancel.',
        ]);
        exit;
    }

    case 'confirm': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') agentic_fail(405, 'Method not allowed.');
        $stagingId = $_POST['staging_id'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $stagingId)) agentic_fail(400, 'Invalid staging id.');
        $stagedPath = $stagingDir . '/' . $stagingId . '.zip';
        if (!is_file($stagedPath)) agentic_fail(404, 'Staged upload not found (it may have expired).');

        $workspaceId = $stagingId; // one id carried through stage -> confirm -> workspace
        $targetDir   = $workspaceRoot . '/' . $workspaceId;
        if (is_dir($targetDir)) agentic_fail(409, 'This upload was already extracted.');
        mkdir($targetDir, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($stagedPath) !== true) {
            agentic_rrmdir($targetDir);
            agentic_fail(400, 'Could not reopen the staged zip.');
        }

        // Defense in depth: re-screen every entry right before extracting, not just at stage time.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (!agentic_zip_entry_is_safe($zip->getNameIndex($i))) {
                $zip->close();
                agentic_rrmdir($targetDir);
                agentic_fail(400, 'Zip rejected at extraction time: unsafe entry path.');
            }
        }

        $extracted = $zip->extractTo($targetDir);
        $zip->close();

        if (!$extracted) {
            agentic_rrmdir($targetDir);
            agentic_fail(500, 'Extraction failed.');
        }

        // Defense in depth #2: verify every extracted path actually resolves inside targetDir.
        $realTarget = realpath($targetDir);
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $item) {
            $real = realpath($item->getPathname());
            if ($real === false || strpos($real, $realTarget) !== 0) {
                agentic_rrmdir($targetDir);
                agentic_fail(500, 'Extraction produced an out-of-bounds path; aborted.');
            }
        }

        $createdAt = date('c');
        safe_write_json($targetDir . '/.agentic_meta.json', [
            'workspace_id'      => $workspaceId,
            'source_name'       => 'staged:' . $stagingId,
            'created_at'        => $createdAt,
            'last_activity_at'  => $createdAt, // Phase E: freshly created counts as active
            'user_id'           => $user_id,
        ]);

        unlink($stagedPath); // staging artifact no longer needed once extracted

        ob_end_clean();
        echo json_encode([
            'workspace_id' => $workspaceId,
            'tree'         => agentic_build_tree($targetDir, $targetDir),
        ]);
        exit;
    }

    case 'cancel': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') agentic_fail(405, 'Method not allowed.');
        $stagingId = $_POST['staging_id'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $stagingId)) agentic_fail(400, 'Invalid staging id.');
        $stagedPath = $stagingDir . '/' . $stagingId . '.zip';
        if (is_file($stagedPath)) unlink($stagedPath);
        ob_end_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    case 'list': {
        // GET — this user's existing sandbox workspaces. Phase E: sweep this user's own
        // area first (abandoned workspaces past TTL, stale never-confirmed staging zips)
        // so the list returned below never includes something about to be deleted anyway.
        agentic_sweep_expired_workspaces($workspaceRoot, $stagingDir);

        $workspaces = [];
        if (is_dir($workspaceRoot)) {
            foreach (scandir($workspaceRoot) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $metaFile = $workspaceRoot . '/' . $entry . '/.agentic_meta.json';
                $meta = is_file($metaFile) ? safe_read_json($metaFile) : [];
                $workspaces[] = [
                    'workspace_id'     => $entry,
                    'created_at'       => $meta['created_at'] ?? null,
                    'last_activity_at' => $meta['last_activity_at'] ?? $meta['created_at'] ?? null,
                ];
            }
        }
        ob_end_clean();
        echo json_encode(['workspaces' => $workspaces, 'ttl_days' => AGENTIC_WORKSPACE_TTL_DAYS]);
        exit;
    }

    case 'delete_workspace': {
        // Manual delete, complementing the automatic TTL sweep above — a user shouldn't
        // have to wait out the TTL just to tidy up something they're done with right now.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') agentic_fail(405, 'Method not allowed.');
        $workspaceId = $_POST['workspace_id'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $workspaceId)) agentic_fail(400, 'Invalid workspace id.');
        $targetDir = $workspaceRoot . '/' . $workspaceId;
        if (!is_dir($targetDir) || !agentic_is_inside_base($targetDir, $workspaceRoot)) {
            agentic_fail(404, 'Workspace not found.');
        }
        agentic_rrmdir($targetDir);
        ob_end_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    default:
        agentic_fail(400, 'Unknown action.');
}

<?php
/**
 * API: Agentic Workspace — Test Sandbox harness server.
 * Location: ./api/agentic_serve_test.php
 *
 * Adapted from vd-ai-browser-web's proxy.php $interceptScript pattern (per
 * piano-sandbox-vibecoding.md, Fase 3a) — but where proxy.php fetches a REMOTE
 * page via cURL and injects a link-interceptor, this endpoint serves a file
 * that already lives in the user's OWN sandbox workspace (same domain, so no
 * cURL/CORS concern) and injects a test harness instead.
 *
 * The harness overrides console methods, onerror and fetch inside the served page and
 * reports back to the parent orchestrator via postMessage, and accepts
 * postMessage({type:'sbxAction', selector, action, value}) to drive click/fill
 * — this is the same {type, ...payload} message shape vd-browser.js already
 * uses for detectForms()/fillForms(), reused here for uniformity (Fase 3b).
 *
 * This endpoint is READ-ONLY test infrastructure: it never writes back into
 * the workspace, never touches deploy/, and is only ever reachable by the
 * owning authenticated user (sandbox_isolation_directive).
 *
 * Query params:
 *   workspace_id — the sandbox workspace to serve from
 *   file         — path within that workspace, relative
 *   harness      — "0" to serve the page plain, with no harness injected (Phase B's
 *                  "view" / "get link" actions); omitted or any other value = harness on
 *                  (Test Sandbox default).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agentic_helpers.php';
check_auth();

$user_id      = $_SESSION['user_id'];
$workspace_id = $_GET['workspace_id'] ?? '';
$file         = $_GET['file'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', $workspace_id)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid workspace id.';
    exit;
}

$baseDir = rtrim(AGENTIC_DIR, '/') . '/' . $user_id . '/workspaces/' . $workspace_id;
$rel     = agentic_sanitize_path($file);
$full    = $baseDir . '/' . $rel;

if ($rel === '' || !is_file($full) || !agentic_is_inside_base($full, $baseDir)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Test target not found.';
    exit;
}
agentic_touch_workspace($baseDir); // Phase E: viewing/testing a page counts as activity

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));

// Non-page assets (css/js/images/etc referenced by the tested page): pass through
// untouched, no harness — nothing to inject a <script> into.
if ($ext !== 'php' && $ext !== 'html' && $ext !== 'htm') {
    header('Content-Type: ' . agentic_mime_for_ext($ext));
    header('Cache-Control: no-store');
    readfile($full);
    exit;
}

if ($ext === 'php') {
    // Same-domain execution: run the file in its own directory (so its relative
    // includes/requires resolve normally) and capture the output instead of
    // streaming it directly, exactly as the plan calls for ("niente cURL, solo
    // string injection" — no remote fetch needed since it's local execution).
    $priorCwd = getcwd();
    chdir(dirname($full));
    ob_start();
    try {
        include $full;
    } catch (\Throwable $e) {
        echo '<pre>Sandbox execution error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</pre>';
    }
    $html = ob_get_clean();
    chdir($priorCwd);
} else {
    $html = file_get_contents($full);
}

// ── Relative-URL rewrite ────────────────────────────────────────────────────
// The served page isn't at a URL that mirrors its real location in the workspace,
// so its own relative <link>/<script>/<img>/<a> references (and CSS url() in an
// inline <style>) would otherwise resolve against THIS endpoint's own URL and 404.
// Rewrite them to route back through this same endpoint at the correct file path.
$currentDir = dirname($rel);
if ($currentDir === '.') $currentDir = '';
// Only ever served from this test endpoint, never mixed into the production
// preview or the deploy path. Skippable via harness=0 for a plain preview.
$harnessOn = ($_GET['harness'] ?? '1') !== '0';
$html = agentic_rewrite_relative_refs($html, $_SERVER['SCRIPT_NAME'], $workspace_id, $currentDir, $harnessOn);

// ── Harness injection — adapted from proxy.php's $interceptScript block ────
if (!$harnessOn) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $html;
    exit;
}

$harness = <<<'HARNESS'
<script>(function(){
  ["log","warn","error","info"].forEach(function(m){
    var orig = console[m];
    console[m] = function(){
      window.parent.postMessage({type:"sbxLog", level:m, args:Array.from(arguments).map(String)}, "*");
      orig.apply(console, arguments);
    };
  });
  window.onerror = function(msg, src, line, col, err){
    window.parent.postMessage({type:"sbxError", msg:msg, line:line, col:col}, "*");
  };
  window.addEventListener("unhandledrejection", function(e){
    window.parent.postMessage({type:"sbxError", msg:String(e.reason)}, "*");
  });
  var origFetch = window.fetch;
  window.fetch = function(){
    var url = arguments[0];
    return origFetch.apply(window, arguments).then(function(r){
      window.parent.postMessage({type:"sbxNetwork", url:String(url), status:r.status}, "*");
      return r;
    }).catch(function(e){
      window.parent.postMessage({type:"sbxNetwork", url:String(url), error:String(e)}, "*");
      throw e;
    });
  };
  window.addEventListener("message", function(e){
    var d = e.data;
    if (d && d.type === "sbxAction") {
      var el = document.querySelector(d.selector);
      if (!el) { window.parent.postMessage({type:"sbxActionResult", ok:false, selector:d.selector}, "*"); return; }
      if (d.action === "click") el.click();
      if (d.action === "fill") { el.value = d.value; el.dispatchEvent(new Event("input",{bubbles:true})); }
      window.parent.postMessage({type:"sbxActionResult", ok:true, selector:d.selector}, "*");
    }
  });
})();</script>
HARNESS;

if (preg_match('/<\/body>/i', $html)) {
    $html = preg_replace('/<\/body>/i', $harness . '</body>', $html, 1);
} else {
    $html .= $harness;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
echo $html;

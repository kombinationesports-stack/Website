<?php

/* Idle time allowed, in seconds (3 minutes). */
if (!defined('ADMIN_IDLE_LIMIT')) define('ADMIN_IDLE_LIMIT', 180);

/* Small extra margin on the server side so the "still here" pings
   (sent at most every 10 s) never cause an early logout. */
if (!defined('ADMIN_IDLE_GRACE')) define('ADMIN_IDLE_GRACE', 15);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function adminGuardExpire() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header("Location: login.php?timeout=1");
    exit;
}

function adminGuardIsIdle() {
    $last = $_SESSION['admin_last_activity'] ?? null;
    if ($last === null) return false;
    return (time() - (int)$last) > (ADMIN_IDLE_LIMIT + ADMIN_IDLE_GRACE);
}

/* Adds the browser-side timer just before </body>. Only touches pages
   that actually contain </body> (so downloads / JSON are never changed). */
function adminGuardInjectTimer($buffer) {
    $pos = strripos($buffer, '</body>');
    if ($pos === false) {
        // No </body>? Still inject if it's a full HTML page.
        $pos = strripos($buffer, '</html>');
        if ($pos === false) return $buffer;
    }

    $ms = (int)ADMIN_IDLE_LIMIT * 1000;
    $js = "<!-- admin idle guard active -->
<script>
(function () {
    var LIMIT = $ms, PING_EVERY = 10000;
    var last = Date.now(), lastPing = Date.now();
    function expire() { window.location.href = 'admin_session_guard.php?expire=1'; }
    function ping() {
        lastPing = Date.now();
        fetch('admin_session_guard.php', { method: 'POST', credentials: 'same-origin', keepalive: true })
            .then(function (r) { if (r.status === 401) expire(); })
            .catch(function () {});
    }
    function activity() {
        last = Date.now();
        if (last - lastPing > PING_EVERY) ping();
    }
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (ev) {
        window.addEventListener(ev, activity, { passive: true });
    });
    function check() { if (Date.now() - last >= LIMIT) expire(); }
    setInterval(check, 1000);
    window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
    document.addEventListener('visibilitychange', check);
    window.addEventListener('focus', check);
})();
</script>
";
    return substr($buffer, 0, $pos) . $js . substr($buffer, $pos);
}

/* ── Direct calls to this file (keep-alive ping / expire link) ── */
$adminGuardDirect = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if ($adminGuardDirect) {
    $isAdmin = (($_SESSION['role'] ?? '') === 'admin');

    if (isset($_GET['expire'])) {
        if ($isAdmin) adminGuardExpire();
        header("Location: login.php?timeout=1");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isAdmin || adminGuardIsIdle()) {
            http_response_code(401);
            exit;
        }
        $_SESSION['admin_last_activity'] = time();
        http_response_code(204);
        exit;
    }

    http_response_code(404);
    exit;
}

/* ── Normal include on an admin page ── */

// Huwag i-cache ang admin pages (para hindi lumabas sa Back/Forward)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Hindi naka-login bilang admin? Ibalik sa login page.
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

if (adminGuardIsIdle()) {
    adminGuardExpire();
}
$_SESSION['admin_last_activity'] = time();
ob_start('adminGuardInjectTimer');
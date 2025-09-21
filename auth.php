<?php
// Start session with hardened cookie params if not already started
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    // set conservative cookie params before starting session
    // Власна назва сесії щоб уникати колізій з іншими проектами на сервері
    if (!headers_sent()) {
        @session_name('certreg_s');
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_admin_logged(): bool {
    return !empty($_SESSION['admin_id']);
}

function is_admin(): bool {
    return ($_SESSION['admin_role'] ?? '') === 'admin';
}

function is_operator(): bool {
    return ($_SESSION['admin_role'] ?? '') === 'operator';
}

/** Ensure any authenticated user (admin or operator). */
function require_login() {
    if (!is_admin_logged()) {
        header('Location: /admin.php');
        exit;
    }
}

/** Ensure admin role specifically. */
function require_admin() {
    if (!is_admin_logged()) {
        header('Location: /admin.php');
        exit;
    }
    if (!is_admin()) {
        // Non-admin trying to access admin-only page → send to operator landing
        header('Location: /issue_token.php');
        exit;
    }
}

/** Ensure operator (not necessarily excluding admin). */
function require_operator() {
    if (!is_admin_logged()) {
        header('Location: /admin.php');
        exit;
    }
    if (!is_operator()) {
        // If admin hits operator-only area, allow or redirect? For now allow admin.
        return; // no-op
    }
}

function login_admin(string $u, string $p): bool {
    // Lazy-load DB only when performing login to avoid requiring DB on simple header includes
    global $pdo;
    if (!($pdo instanceof PDO)) {
        require_once __DIR__.'/db.php';
    }
    if (!($pdo instanceof PDO)) {
        // DB still not initialized – fail gracefully without fatal
        error_log('DB not initialized in login_admin');
        return false;
    }
    // Detect presence of is_active column once (cached in static variable)
    static $hasActive = null;
    if ($hasActive === null) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM `creds` LIKE 'is_active'");
            $hasActive = $chk && $chk->rowCount() === 1;
        } catch (Throwable $e) { $hasActive = false; }
    }
    if ($hasActive) {
        $st = $pdo->prepare("SELECT id, passhash, role FROM creds WHERE username=? AND is_active=1");
        $st->execute([$u]);
    } else {
        $st = $pdo->prepare("SELECT id, passhash, role FROM creds WHERE username=?");
        $st->execute([$u]);
    }
    $row = $st->fetch();
    if ($row && password_verify($p, $row['passhash'])) {
        // Check if we should rehash (upgrade to Argon2id if supported)
        $rehashNeeded = password_needs_rehash($row['passhash'],
            defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
            defined('PASSWORD_ARGON2ID') ? [
                'memory_cost' => 1<<17, // 131072 KB (~128MB)
                'time_cost'   => 3,
                'threads'     => 1,
            ] : []
        );
        if($rehashNeeded){
            try {
                $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
                $opts = $algo === PASSWORD_ARGON2ID ? [
                    'memory_cost' => 1<<17,
                    'time_cost'   => 3,
                    'threads'     => 1,
                ] : ['cost'=>12];
                $newHash = password_hash($p, $algo, $opts);
                $up = $pdo->prepare('UPDATE creds SET passhash=? WHERE id=? LIMIT 1');
                $up->execute([$newHash, (int)$row['id']]);
            } catch(Throwable $e){ /* ignore upgrade failure; continue login */ }
        }
        // prevent session fixation
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_user'] = $u;
        $_SESSION['admin_role'] = $row['role'];
        return true;
    }
    return false;
}

function logout_admin() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000,
          $params["path"], $params["domain"], $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// === CSRF ===
function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sess = $_SESSION['csrf'] ?? '';
        // Primary: form field
        $sent = $_POST['_csrf'] ?? '';
        // Fallback for JSON / fetch requests: custom header X-CSRF-Token
        if($sent === '') {
            $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        if ($sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
            http_response_code(403);
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
            $wantsJson = (stripos($accept,'application/json') !== false) || (stripos($ctype,'application/json') !== false);
            if($wantsJson){
                if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error'=>'csrf']);
                exit;
            }
            exit('Помилка безпеки (CSRF). Оновіть сторінку та повторіть.');
        }
    }
}



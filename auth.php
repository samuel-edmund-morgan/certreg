<?php
session_start();
require_once __DIR__.'/db.php';

function is_admin_logged(): bool {
    return !empty($_SESSION['admin_id']);
}

function require_admin() {
    if (!is_admin_logged()) {
        header('Location: /admin.php');
        exit;
    }
}

function login_admin(string $u, string $p): bool {
    global $pdo;
    $st = $pdo->prepare("SELECT id, passhash FROM creds WHERE username=?");
    $st->execute([$u]);
    $row = $st->fetch();
    if ($row && password_verify($p, $row['passhash'])) {
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_user'] = $u;
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
        $sent = $_POST['_csrf'] ?? '';
        $sess = $_SESSION['csrf'] ?? '';
        if ($sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
            http_response_code(403);
            exit('Помилка безпеки (CSRF). Оновіть сторінку та повторіть.');
        }
    }
}



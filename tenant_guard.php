<?php
/**
 * RiceValy tenant/session guard.
 *
 * Every protected POS page must derive shop_id from the authenticated session.
 * Never accept shop_id from GET/POST and never fall back to shop 1.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('tenantShopId')) {
    function tenantShopId(): int
    {
        return isset($_SESSION['shop_id']) ? (int) $_SESSION['shop_id'] : 0;
    }
}

if (!function_exists('tenantUserId')) {
    function tenantUserId(): int
    {
        if (isset($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }
        return isset($_SESSION['id']) ? (int) $_SESSION['id'] : 0;
    }
}

if (!function_exists('requireTenantSession')) {
    function requireTenantSession(bool $jsonResponse = false): void
    {
        if (tenantUserId() > 0 && tenantShopId() > 0) {
            return;
        }

        if ($jsonResponse) {
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => 'Your session has expired. Please log in again.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!headers_sent()) {
            header('Location: /pos/login.php');
        }
        exit;
    }
}

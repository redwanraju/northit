<?php
/**
 * RiceValy website-admin security guard.
 *
 * Protects /pos/site administrative pages without making the public checkout private.
 * Website administration is intentionally scoped to shop 1.
 */
require_once __DIR__ . '/../tenant_guard.php';

if (!function_exists('siteAdminRequestWantsJson')) {
    function siteAdminRequestWantsJson(): bool
    {
        $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $actionPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']));
        return $xhr || $actionPost || strpos($accept, 'application/json') !== false;
    }
}

if (!function_exists('siteAdminSecurityFail')) {
    function siteAdminSecurityFail(int $status, string $message, bool $json): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
        if ($json) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'status' => 'error',
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><title>Access Denied</title></head>'
           . '<body style="font-family:Arial,sans-serif;padding:40px">'
           . '<h2>Access Denied</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
           . '<p><a href="/pos/index.php">Back to POS Dashboard</a></p></body></html>';
        exit;
    }
}

if (!function_exists('requireSiteAdmin')) {
    function requireSiteAdmin(int $requiredShopId = 1): void
    {
        $json = siteAdminRequestWantsJson();
        requireTenantSession($json);

        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $shopId = tenantShopId();
        if ($role !== 'admin' || $shopId !== $requiredShopId) {
            siteAdminSecurityFail(403, 'Admin access is required for website management.', $json);
        }
    }
}

if (!function_exists('siteAdminCsrfToken')) {
    function siteAdminCsrfToken(): string
    {
        if (empty($_SESSION['rv_site_admin_csrf']) || !is_string($_SESSION['rv_site_admin_csrf'])) {
            $_SESSION['rv_site_admin_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['rv_site_admin_csrf'];
    }
}

if (!function_exists('siteAdminRequireCsrf')) {
    function siteAdminRequireCsrf(): void
    {
        $expected = siteAdminCsrfToken();
        $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            siteAdminSecurityFail(419, 'Invalid or expired security token. Reload the page and try again.', true);
        }
    }
}

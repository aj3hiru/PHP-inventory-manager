<?php
// Shared helpers for the store panel: slugs, CSRF tokens, bill and receipt numbers.

function generateSlug($text) {
    $slug = strtolower($text);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

if (!function_exists('csrfToken')) {
    function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrfValid')) {
    function csrfValid(): bool {
        $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return $sent !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
    }
}

if (!function_exists('requireCsrf')) {
    function requireCsrf(bool $json = false): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (csrfValid()) return;
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token. Please refresh the page and try again.']);
        } else {
            echo 'Security error: invalid or missing CSRF token. Please go back, refresh the page, and try again.';
        }
        exit;
    }
}

if (!function_exists('csrfGetValid')) {
    // GET-based token check for simple links (e.g. the comment delete
    // link), where a full POST form isn't practical.
    function csrfGetValid(): bool {
        $sent = $_GET['token'] ?? '';
        return $sent !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
    }
}

if (!function_exists('generateOrderNumber')) {
    /**
     * Generates the next order number using the business's configured
     * prefix + a zero-padded sequential counter (e.g. "PJ0000001").
     * Must be called from inside an active transaction ($pdo->beginTransaction())
     * — it locks the settings row (FOR UPDATE) so concurrent sales never
     * collide on the same number.
     */
    function generateOrderNumber(PDO $pdo): string {
        $row = $pdo->query("SELECT id FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // No settings row yet — fall back to a safe default rather than erroring.
            $pdo->exec("INSERT INTO ecom_business_settings (business_name, invoice_title, order_id_prefix, order_sequence_next) VALUES ('My Business','Tax Invoice','ORD',1)");
            $settings_id = (int)$pdo->lastInsertId();
        } else {
            $settings_id = (int)$row['id'];
        }

        $stmt = $pdo->prepare("SELECT order_id_prefix, order_sequence_next FROM ecom_business_settings WHERE id = ? FOR UPDATE");
        $stmt->execute([$settings_id]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);

        $prefix = $s['order_id_prefix'] !== '' ? $s['order_id_prefix'] : 'ORD';
        $next   = (int)$s['order_sequence_next'];

        $pdo->prepare("UPDATE ecom_business_settings SET order_sequence_next = ? WHERE id = ?")
            ->execute([$next + 1, $settings_id]);

        return $prefix . str_pad((string)$next, 7, '0', STR_PAD_LEFT);
    }
}
if (!function_exists('generateReceiptNumber')) {
    /**
     * Generates the next payment receipt number (e.g. "RCPT0000001").
     * Must be called from inside an active transaction — it locks the
     * settings row (FOR UPDATE) so concurrent payments never collide.
     */
    function generateReceiptNumber(PDO $pdo): string {
        $row = $pdo->query("SELECT id FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->exec("INSERT INTO ecom_business_settings (business_name, invoice_title, order_id_prefix, order_sequence_next, receipt_sequence_next) VALUES ('My Business','Tax Invoice','ORD',1,1)");
            $settings_id = (int)$pdo->lastInsertId();
        } else {
            $settings_id = (int)$row['id'];
        }

        $stmt = $pdo->prepare("SELECT receipt_sequence_next FROM ecom_business_settings WHERE id = ? FOR UPDATE");
        $stmt->execute([$settings_id]);
        $next = (int)$stmt->fetchColumn();

        $pdo->prepare("UPDATE ecom_business_settings SET receipt_sequence_next = ? WHERE id = ?")
            ->execute([$next + 1, $settings_id]);

        return 'RCPT' . str_pad((string)$next, 7, '0', STR_PAD_LEFT);
    }
}

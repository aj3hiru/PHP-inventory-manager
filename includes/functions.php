<?php
// functions.php - Helper Functions

/**
 * Whether the storefront should hide out-of-stock products, per the
 * "Hide out-of-stock products" toggle in Admin -> Homepage Settings ->
 * General. Reads ecom_home_settings once; false (show them, greyed out
 * with an "Out of stock" button) if the setting has never been saved.
 */
function shouldHideOutOfStock(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM ecom_home_settings WHERE setting_key = 'hide_out_of_stock'");
        $stmt->execute();
        $cached = ($stmt->fetchColumn() === '1');
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * SQL fragment to AND onto a WHERE clause so a product listing skips
 * out-of-stock physical products when that admin toggle is on. Always
 * keeps digital/service products (they don't track stock) and any product
 * with stock left. Returns '' (no-op) when the toggle is off.
 */
function outOfStockFilterSql(PDO $pdo): string {
    if (!shouldHideOutOfStock($pdo)) return '';
    return " AND (product_type != 'physical' OR stock_qty > 0) ";
}

/**
 * All size/unit variants for one product (e.g. "250 g" / "500 g" / "1 Kg"),
 * each with its own price, sale price and stock. Empty array = the product
 * uses its own single price/stock (no variants configured).
 */
function getProductVariants(PDO $pdo, int $product_id): array {
    $stmt = $pdo->prepare("SELECT * FROM ecom_product_variants WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$product_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Works out what price/stock to *display* for a product that may or may not
 * have variants: the variant marked is_default (falling back to the first
 * one) when variants exist, otherwise the product's own price/sale_price/
 * stock_qty. Returns ['price','sale','stock','variant_id'].
 */
function resolveDisplayPricing(array $product, array $variants): array {
    if (!empty($variants)) {
        $chosen = null;
        foreach ($variants as $v) {
            if (!empty($v['is_default'])) { $chosen = $v; break; }
        }
        if (!$chosen) $chosen = $variants[0];

        $price = (float)$chosen['price'];
        $sale  = (!empty($chosen['sale_price']) && (float)$chosen['sale_price'] > 0 && (float)$chosen['sale_price'] < $price) ? (float)$chosen['sale_price'] : null;

        return [
            'price'      => $price,
            'sale'       => $sale,
            'stock'      => $chosen['stock_qty'],
            'variant_id' => (int)$chosen['id'],
        ];
    }

    $price = (float)$product['price'];
    $sale  = (!empty($product['sale_price']) && (float)$product['sale_price'] > 0 && (float)$product['sale_price'] < $price) ? (float)$product['sale_price'] : null;

    return [
        'price'      => $price,
        'sale'       => $sale,
        'stock'      => $product['stock_qty'] ?? null,
        'variant_id' => 0,
    ];
}

function generateSlug($text) {
    $slug = strtolower($text);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

function postUrl($slug, $id) {
    return "/{$slug}-{$id}";
}

function tagUrl($slug, $id) {
    return "/tag/{$slug}-{$id}";
}

function storyUrl($slug, $id) {
    return "/story/{$slug}-{$id}";
}

function formatViews($views) {
    if ($views >= 1000000) {
        return round($views / 1000000, 1) . 'M';
    } elseif ($views >= 1000) {
        return round($views / 1000, 1) . 'K';
    }
    return $views;
}

// Fetch comments (with pagination and total)
function getCommentTree($pdo, $post_id, $offset = 0, $limit = 5) {
    $stmt = $pdo->prepare("SELECT c.id, c.post_id, c.parent_id, c.name, c.content, c.date, p.name AS parent_name, COUNT(*) OVER() AS total_count FROM comments c LEFT JOIN comments p ON c.parent_id = p.id WHERE c.post_id = ? ORDER BY c.date ASC LIMIT ?, ?");
    $stmt->bindValue(1, $post_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$comments) return [[], 0];
    $total = (int)$comments[0]['total_count'];
    $tree = [];
    $map = [];
    foreach ($comments as $c) {
        $c['children'] = [];
        unset($c['total_count']); 
        $map[$c['id']] = $c;
        if ($c['parent_id'] && isset($map[$c['parent_id']])) {
            $map[$c['parent_id']]['children'][] = &$map[$c['id']];
        } else {
            $tree[] = &$map[$c['id']];
        }
    }
    return [$tree, $total];
}

function renderComments($comments, $depth=0) {
    foreach ($comments as $comment) {
        $replyClass = $depth > 0 ? 'blog-comment-item blog-comment-reply' : 'blog-comment-item';
        $date = date('M j, Y \a\t g:i a', strtotime($comment['date']));
        echo "<div class='$replyClass' data-comment-id='{$comment['id']}'>";
        echo "<div class='blog-comment-header'>";
        echo "<span class='blog-comment-author'>".htmlspecialchars($comment['name'])."</span>";
        echo "<span class='blog-comment-date'>$date</span>";
        echo "</div>";
        if ($comment['parent_id'] && $comment['parent_name']) {
            echo "<div style='color:#6c757d;font-size:0.75rem;margin-bottom:5px;'>Replying to <strong>".htmlspecialchars($comment['parent_name'])."</strong></div>";
        }
        echo "<div class='blog-comment-content'>".nl2br(htmlspecialchars($comment['content']))."</div>";
        echo "<div class='blog-comment-actions'>";
       echo "<button class='blog-comment-reply-btn' data-id='{$comment['id']}' data-name='".htmlspecialchars($comment['name'], ENT_QUOTES)."'>Reply</button>"; 
        echo "</div>";
        echo "</div>";
        if (!empty($comment['children'])) {
            renderComments($comment['children'], $depth+1);
        }
    }
}

// TOC Generation
function generateTOC($c){
    if(!$c||!is_string($c))return['toc'=>'','content'=>$c];
    $i=[];$l=2;$t='<div class="toc"><h2>Table of Contents</h2><ol>';
    $c=preg_replace_callback('/<h([2-6])>(.*?)<\/h\1>/i',function($a)use(&$t,&$i,&$l){
        $lev=(int)$a[1];$hPlain=trim(strip_tags($a[2]));$hHTML=trim($a[2]);if(!$hPlain)return $a[0];
        $id=trim(preg_replace('/[^a-zA-Z0-9]+/','-',$hPlain),'-');
        $i[$id]=($i[$id]??0)+1;$uid=$i[$id]>1?"$id-{$i[$id]}":$id;
        while($lev<$l){$t.='</li></ol>'; $l--;}
        while($lev>$l){$t.='<ol>'; $l++;}
        if($lev==$l && $l>2)$t.='</li>';
        $t.="<li><a href='#$uid'>".htmlspecialchars($hPlain)."</a>";
        return "<h$lev id='$uid'>$hHTML</h$lev>";
    },$c);
    while($l>2){$t.='</li></ol>'; $l--;}
    $t.='</li></ol></div>';
    return['toc'=>$t,'content'=>$c];
}

// ────────────────────────────────────────
// CSRF helpers — added for the Comments Manager moderation workflow
// (approve / unapprove / delete / reply). Every function below is wrapped
// in function_exists() so this block is purely additive and can never
// collide with anything already defined in this file.
// ───────────────────────────────────────
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
?>
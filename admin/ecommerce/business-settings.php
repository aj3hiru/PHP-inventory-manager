<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) { exit('Access Denied'); }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_payment'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

define('LOGO_UPLOAD_URL', 'uploads/ecommerce/business/');
define('LOGO_UPLOAD_ABS', DROOT_PATH . '/uploads/ecommerce/business/');

$SOCIAL_PLATFORMS = [
    'facebook'  => ['label' => 'Facebook',  'icon' => 'fab fa-facebook-f',  'color' => '#1877f2'],
    'instagram' => ['label' => 'Instagram', 'icon' => 'fab fa-instagram',   'color' => '#c13584'],
    'youtube'   => ['label' => 'YouTube',   'icon' => 'fab fa-youtube',     'color' => '#ff0000'],
    'x'         => ['label' => 'X (Twitter)','icon'=> 'fab fa-x-twitter',   'color' => '#000000'],
    'linkedin'  => ['label' => 'LinkedIn',  'icon' => 'fab fa-linkedin-in', 'color' => '#0a66c2'],
    'whatsapp'  => ['label' => 'WhatsApp',  'icon' => 'fab fa-whatsapp',    'color' => '#25d366'],
    'telegram'  => ['label' => 'Telegram',  'icon' => 'fab fa-telegram',    'color' => '#26a5e4'],
    'pinterest' => ['label' => 'Pinterest', 'icon' => 'fab fa-pinterest-p', 'color' => '#e60023'],
    'other'     => ['label' => 'Other',     'icon' => 'fas fa-link',        'color' => '#6b7280'],
];

$notifications = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business_name       = trim($_POST['business_name'] ?? '');
    $tagline             = trim($_POST['tagline'] ?? '');
    $seo_description     = trim($_POST['seo_description'] ?? '');
    $address             = trim($_POST['address'] ?? '');
    $location            = trim($_POST['location'] ?? '');
    $email               = trim($_POST['email'] ?? '');
    $website_url         = trim($_POST['website_url'] ?? '');
    $business_hours      = trim($_POST['business_hours'] ?? '');
    $gstin               = strtoupper(trim($_POST['gstin'] ?? ''));
    $pan_number          = strtoupper(trim($_POST['pan_number'] ?? ''));
    $fssai_number        = trim($_POST['fssai_number'] ?? '');
    $state               = trim($_POST['state'] ?? '');
    $show_gstin_on_invoice   = isset($_POST['show_gstin_on_invoice']) ? 1 : 0;
    $show_pan_on_invoice     = isset($_POST['show_pan_on_invoice']) ? 1 : 0;
    $show_fssai_on_invoice   = isset($_POST['show_fssai_on_invoice']) ? 1 : 0;
    $show_address_on_invoice = isset($_POST['show_address_on_invoice']) ? 1 : 0;
    $show_location_on_invoice= isset($_POST['show_location_on_invoice']) ? 1 : 0;
    $site_header_display = in_array($_POST['site_header_display'] ?? '', ['title','logo','both'], true) ? $_POST['site_header_display'] : 'both';
    $invoice_display     = in_array($_POST['invoice_display'] ?? '', ['logo','name','both'], true) ? $_POST['invoice_display'] : 'both';
    $invoice_title       = trim($_POST['invoice_title'] ?? 'Tax Invoice');
    $invoice_footer_note = trim($_POST['invoice_footer_note'] ?? '');
    $return_policy       = trim($_POST['return_policy'] ?? '');
    $printer_format      = in_array($_POST['printer_format'] ?? '', ['a4', 'thermal_58', 'thermal_80'], true) ? $_POST['printer_format'] : 'a4';
    $valid_fkeys = array_map(fn($i) => 'F' . $i, range(2, 12));
    $pos_print_mode         = in_array($_POST['pos_print_mode'] ?? '', ['thermal', 'a4', 'both'], true) ? $_POST['pos_print_mode'] : 'both';
    $shortcut_complete_sale = in_array($_POST['shortcut_complete_sale'] ?? '', $valid_fkeys, true) ? $_POST['shortcut_complete_sale'] : 'F2';
    $shortcut_print         = in_array($_POST['shortcut_print'] ?? '', $valid_fkeys, true) ? $_POST['shortcut_print'] : 'F3';
    $shortcut_new_sale      = in_array($_POST['shortcut_new_sale'] ?? '', $valid_fkeys, true) ? $_POST['shortcut_new_sale'] : 'F4';
    $barcode_footer_text = trim($_POST['barcode_footer_text'] ?? '');
    $order_id_prefix     = strtoupper(trim($_POST['order_id_prefix'] ?? 'ORD'));
    $logo_display_width  = max(40, min(400, (int)($_POST['logo_display_width'] ?? 150)));

    // Multiple contact numbers
    $contact_numbers_clean = array_values(array_filter(array_map('trim', $_POST['contact_numbers'] ?? [])));
    $contact_numbers_json = json_encode($contact_numbers_clean);
    $phone = $contact_numbers_clean[0] ?? '';

    // Which numbers are chosen to print on the invoice
    $invoice_numbers_clean = array_values(array_intersect($contact_numbers_clean, $_POST['invoice_numbers'] ?? []));
    $invoice_numbers_json = json_encode($invoice_numbers_clean);

    // Social media — dynamic list
    $social_platforms_in = $_POST['social_platform'] ?? [];
    $social_urls_in      = $_POST['social_url'] ?? [];
    $social_list = [];
    foreach ($social_platforms_in as $i => $plat) {
        $url = trim($social_urls_in[$i] ?? '');
        if ($url === '') continue;
        $social_list[] = ['platform' => $plat, 'url' => $url];
    }
    $social_media_json = json_encode($social_list);

    $logo_path = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        if (!file_exists(LOGO_UPLOAD_ABS)) mkdir(LOGO_UPLOAD_ABS, 0777, true);
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $filename = 'logo-' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], LOGO_UPLOAD_ABS . $filename)) {
            $logo_path = LOGO_UPLOAD_URL . $filename;
        }
    }

    $existing_id = (int) $pdo->query("SELECT id FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetchColumn();

    $fields = [
        'business_name' => $business_name, 'tagline' => $tagline, 'seo_description' => $seo_description,
        'address' => $address, 'location' => $location, 'phone' => $phone, 'contact_numbers' => $contact_numbers_json,
        'invoice_contact_numbers' => $invoice_numbers_json,
        'email' => $email, 'website_url' => $website_url, 'business_hours' => $business_hours,
        'gstin' => $gstin, 'pan_number' => $pan_number, 'fssai_number' => $fssai_number, 'state' => $state,
        'show_gstin_on_invoice' => $show_gstin_on_invoice, 'show_pan_on_invoice' => $show_pan_on_invoice,
        'show_fssai_on_invoice' => $show_fssai_on_invoice,
        'show_address_on_invoice' => $show_address_on_invoice, 'show_location_on_invoice' => $show_location_on_invoice,
        'site_header_display' => $site_header_display, 'invoice_display' => $invoice_display,
        'invoice_title' => $invoice_title, 'invoice_footer_note' => $invoice_footer_note, 'return_policy' => $return_policy,
        'printer_format' => $printer_format, 'barcode_footer_text' => $barcode_footer_text, 'order_id_prefix' => $order_id_prefix,
        'pos_print_mode' => $pos_print_mode, 'shortcut_complete_sale' => $shortcut_complete_sale, 'shortcut_print' => $shortcut_print, 'shortcut_new_sale' => $shortcut_new_sale,
        'logo_display_width' => $logo_display_width, 'social_media_json' => $social_media_json,
    ];
    if ($logo_path) $fields['logo'] = $logo_path;

    $set_sql = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($fields)));
    $pdo->prepare("UPDATE ecom_business_settings SET $set_sql WHERE id=?")
        ->execute([...array_values($fields), $existing_id]);

    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_business_settings_update', 'Updated business profile settings', ?, ?)")
        ->execute([$_SESSION['user_id'], $log_ip, $log_ua]);

    header("Location: /admin/ecommerce/business-settings.php?success=1");
    exit;
}

if (isset($_GET['success'])) $notifications[] = ['type' => 'success', 'message' => 'Business profile saved successfully!'];

$biz = $pdo->query("SELECT * FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$contact_numbers = !empty($biz['contact_numbers']) ? (json_decode($biz['contact_numbers'], true) ?: []) : (!empty($biz['phone']) ? [$biz['phone']] : ['']);
if (empty($contact_numbers)) $contact_numbers = [''];
$invoice_numbers = !empty($biz['invoice_contact_numbers']) ? (json_decode($biz['invoice_contact_numbers'], true) ?: []) : $contact_numbers;
$social_list = !empty($biz['social_media_json']) ? (json_decode($biz['social_media_json'], true) ?: []) : [];

// Website URL defaults to the site's real current address if nothing saved yet
$website_url_value = $biz['website_url'] ?? '';
if ($website_url_value === '') $website_url_value = SITE_URL;

$page_title = 'Business Setting';
$page_subtitle = 'Your business profile, SEO details, and everything that appears on invoices and your storefront';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.social-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; }
.social-row .social-icon { width: 22px; text-align: center; flex-shrink: 0; font-size: 1rem; }
.social-row select { max-width: 150px; flex-shrink: 0; }
.contact-number-row, .invoice-number-row { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center; }
#logoPreviewBox { display: flex; align-items: center; justify-content: center; background: var(--gray-50); border: 1px dashed var(--gray-300); border-radius: 0.5rem; padding: 1rem; margin-bottom: 0.75rem; min-height: 100px; }
#logoPreviewBox img { max-width: 100%; }
.display-mode-options { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.display-mode-options label { flex: 1; min-width: 130px; border: 1px solid var(--gray-200); border-radius: 0.5rem; padding: 0.6rem 0.75rem; cursor: pointer; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; }
.display-mode-options input:checked + span { font-weight: 700; color: var(--primary); }
.display-mode-options label:has(input:checked) { border-color: var(--primary); background: var(--primary-lighter); }
/* Live thermal preview */
.thermal-preview-wrap { background: #ddd; padding: 14px; border-radius: 0.5rem; display: flex; justify-content: center; }
.thermal-preview { width: 220px; background: #fff; padding: 10px; font-family: 'Courier New', monospace; font-size: 10.5px; color: #000; }
.thermal-preview .t-center { text-align: center; }
.thermal-preview img { max-width: 100px; margin: 0 auto 4px; display: block; }
.thermal-preview .t-line { border-top: 1px dashed #000; margin: 5px 0; }
.thermal-preview .biz-name { font-weight: 700; font-size: 12px; }
.thermal-preview .text-end { text-align: right; }
.thermal-preview table.inv-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
.thermal-preview table.inv-table th { text-align: left; border-bottom: 1px dashed #000; padding: 2px 0; }
.thermal-preview table.inv-table td { padding: 2px 0; vertical-align: top; }
.thermal-preview .payment-info-box { border: 1px solid #000; padding: 3px 5px; margin: 5px 0; }
.thermal-preview .pi-title { font-weight: 700; font-size: 10.5px; margin-bottom: 2px; }
.thermal-preview .pi-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 10.5px; }
.thermal-preview .inv-totals div { display: flex; justify-content: space-between; font-size: 9.5px; padding: 1px 0; }
.thermal-preview .inv-totals .grand { font-weight: 700; font-size: 11px; border-top: 1px dashed #000; margin-top: 3px; padding-top: 3px; }

/* Premium outlined checkboxes — border only, no solid filled background */
.form-check-input {
    border: 1.5px solid var(--gray-300, #d1d5db) !important;
    background-color: #fff !important;
}
.form-check-input:checked {
    background-color: #fff !important;
    border-color: var(--primary, #7c3aed) !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='none' stroke='%237c3aed' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M2 4l1.5 1.5L6 2.5'/%3e%3c/svg%3e") !important;
}
.form-check-input:focus {
    border-color: var(--primary, #7c3aed) !important;
    box-shadow: 0 0 0 0.2rem var(--primary-lighter, #f5f3ff) !important;
}

/* ── Settings tabs ────────────────────────────────────────────────────────── */
.settings-tabs { display: flex; gap: 0.35rem; background: #fff; border: 1px solid var(--gray-200); border-radius: 0.65rem; padding: 0.4rem; margin-bottom: 1.25rem; overflow-x: auto; }
.settings-tab-btn {
    display: flex; align-items: center; gap: 0.5rem; white-space: nowrap;
    padding: 0.55rem 1rem; border-radius: 0.5rem; border: none; background: none;
    font-size: 0.875rem; font-weight: 600; color: var(--gray-500); cursor: pointer;
    transition: background 0.15s, color 0.15s;
}
.settings-tab-btn:hover { background: var(--gray-50); color: var(--gray-700); }
.settings-tab-btn.active { background: var(--primary); color: #fff; }
.settings-tab-btn i { font-size: 0.8rem; }
.settings-tab-pane { display: none; }
.settings-tab-pane.active { display: block; }

/* Tighter card rhythm so the page reads calmer with many fields */
.gd-card { margin-bottom: 1.25rem; }
.gd-card .form-label { font-size: 0.825rem; font-weight: 600; color: var(--gray-600); margin-bottom: 0.35rem; }
.gd-card h5 { font-size: 1rem; }
.settings-section-hint { font-size: 0.8rem; color: var(--gray-400); margin: -0.5rem 0 1rem; }

/* Sticky Save bar spanning the full width, always reachable regardless of active tab */
.settings-save-bar { position: sticky; bottom: 0; background: #fff; border-top: 1px solid var(--gray-200); padding: 0.85rem 1.25rem; margin: 1.5rem -1.25rem -1.25rem; border-radius: 0 0 0.65rem 0.65rem; display: flex; justify-content: flex-end; z-index: 5; }
</style>
</head>
<body>

<div class="admin-container">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <span class="sitename-mob"><?= htmlspecialchars($site_name) ?></span>
                <div class="page-heading-mini">
                    <h1><?= htmlspecialchars($page_title) ?></h1>
                    <p><?= htmlspecialchars($page_subtitle) ?></p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <form method="POST" enctype="multipart/form-data" id="bizForm">
                <div class="row">
                    <div class="col-lg-7">

                        <!-- TAB NAVIGATION -->
                        <div class="settings-tabs">
                            <button type="button" class="settings-tab-btn active" data-tab="general"><i class="fas fa-building"></i> General</button>
                            <button type="button" class="settings-tab-btn" data-tab="tax"><i class="fas fa-file-invoice-dollar"></i> Tax &amp; Legal</button>
                            <button type="button" class="settings-tab-btn" data-tab="invoice"><i class="fas fa-file-invoice"></i> Invoice</button>
                            <button type="button" class="settings-tab-btn" data-tab="printer"><i class="fas fa-keyboard"></i> Printer &amp; POS</button>
                            <button type="button" class="settings-tab-btn" data-tab="orders"><i class="fas fa-barcode"></i> Barcode &amp; Orders</button>
                        </div>

                        <!-- ══════════════ TAB: GENERAL ══════════════ -->
                        <div class="settings-tab-pane active" data-pane="general">

                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-building text-primary"></i> Business Identity</h5>
                                    <div class="mb-3">
                                        <label class="form-label">Business Name</label>
                                        <input type="text" name="business_name" id="fBizName" class="form-control" value="<?= htmlspecialchars($biz['business_name'] ?? '') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Business Tagline</label>
                                        <input type="text" name="tagline" class="form-control" placeholder="A short line about what you do" value="<?= htmlspecialchars($biz['tagline'] ?? '') ?>">
                                    </div>
                                    <div class="mb-1">
                                        <label class="form-label">Business SEO Description</label>
                                        <textarea name="seo_description" class="form-control" rows="2" maxlength="300" placeholder="Shown in Google search results and when your storefront is shared online"><?= htmlspecialchars($biz['seo_description'] ?? '') ?></textarea>
                                        <div class="form-text">Keep it under 160 characters for the best results in search results.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-address-card text-primary"></i> Contact Information</h5>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business Email</label>
                                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($biz['email'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Website URL</label>
                                            <input type="url" name="website_url" class="form-control" value="<?= htmlspecialchars($website_url_value) ?>">
                                            <div class="form-text">Defaults to your live site's own address.</div>
                                        </div>
                                    </div>

                                    <label class="form-label">Business Contact Number(s)</label>
                                    <div id="contactNumbersWrap">
                                        <?php foreach ($contact_numbers as $i => $num): ?>
                                        <div class="contact-number-row">
                                            <input type="text" name="contact_numbers[]" class="form-control contact-num-input" placeholder="Phone number" value="<?= htmlspecialchars($num) ?>">
                                            <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm mb-3" onclick="addContactNumber()"><i class="fas fa-plus"></i> Add Another Number</button>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business Location</label>
                                            <input type="text" name="location" id="fLocation" class="form-control" placeholder="e.g. Purnia, Bihar" value="<?= htmlspecialchars($biz['location'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business Hours</label>
                                            <input type="text" name="business_hours" class="form-control" placeholder="e.g. Mon–Sat, 10 AM – 8 PM" value="<?= htmlspecialchars($biz['business_hours'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="mb-1">
                                        <label class="form-label">Business Address</label>
                                        <textarea name="address" id="fAddress" class="form-control" rows="2"><?= htmlspecialchars($biz['address'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-share-alt text-primary"></i> Social Media Accounts</h5>
                                    <div id="socialWrap">
                                        <?php foreach ($social_list as $s):
                                            $plat = $SOCIAL_PLATFORMS[$s['platform']] ?? $SOCIAL_PLATFORMS['other'];
                                        ?>
                                        <div class="social-row">
                                            <i class="<?= $plat['icon'] ?> social-icon" style="color:<?= $plat['color'] ?>;"></i>
                                            <select name="social_platform[]" class="form-select form-select-sm">
                                                <?php foreach ($SOCIAL_PLATFORMS as $key => $p): ?>
                                                <option value="<?= $key ?>" <?= $s['platform'] === $key ? 'selected' : '' ?>><?= $p['label'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="url" name="social_url[]" class="form-control form-control-sm" placeholder="Profile URL" value="<?= htmlspecialchars($s['url']) ?>">
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addSocialRow()"><i class="fas fa-plus"></i> Add Another Account</button>
                                </div>
                            </div>

                        </div>

                        <!-- ══════════════ TAB: TAX & LEGAL ══════════════ -->
                        <div class="settings-tab-pane" data-pane="tax">
                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-file-invoice-dollar text-primary"></i> GST &amp; Tax Details</h5>
                                    <p class="settings-section-hint">Just the values here — whether each one prints on your invoice is controlled in the <strong>Invoice &amp; Print Display</strong> panel on the right.</p>
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">GSTIN</label>
                                            <input type="text" name="gstin" id="fGstin" class="form-control text-uppercase invoice-preview-trigger" placeholder="e.g. 27ABCDE1234F1Z5" value="<?= htmlspecialchars($biz['gstin'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">PAN Number</label>
                                            <input type="text" name="pan_number" id="fPan" class="form-control text-uppercase invoice-preview-trigger" placeholder="e.g. ABCDE1234F" value="<?= htmlspecialchars($biz['pan_number'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="mb-1">
                                        <label class="form-label">State (for GST)</label>
                                        <input type="text" name="state" class="form-control" placeholder="e.g. Maharashtra" value="<?= htmlspecialchars($biz['state'] ?? '') ?>">
                                    </div>
                                    <div class="mb-1">
                                        <label class="form-label">FSSAI License No. <small class="text-muted">(optional — for food businesses)</small></label>
                                        <input type="text" name="fssai_number" id="fFssai" class="form-control invoice-preview-trigger" value="<?= htmlspecialchars($biz['fssai_number'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ══════════════ TAB: INVOICE ══════════════ -->
                        <div class="settings-tab-pane" data-pane="invoice">
                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-file-invoice text-primary"></i> Invoice Format</h5>
                                    <div class="mb-3">
                                        <label class="form-label">Invoice Title</label>
                                        <select name="invoice_title" class="form-select">
                                            <?php foreach (['Tax Invoice', 'Bill of Supply', 'Cash Memo', 'Retail Invoice'] as $t): ?>
                                            <option value="<?= $t ?>" <?= ($biz['invoice_title'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">"Tax Invoice" is standard when GSTIN is set. Use "Bill of Supply" if you're not GST-registered.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Invoice Footer Note</label>
                                        <textarea name="invoice_footer_note" class="form-control" rows="2" placeholder="e.g. Goods once sold will not be taken back."><?= htmlspecialchars($biz['invoice_footer_note'] ?? '') ?></textarea>
                                    </div>
                                    <div class="mb-1">
                                        <label class="form-label">Return / Refund Policy <small class="text-muted">(shown on your storefront)</small></label>
                                        <textarea name="return_policy" class="form-control" rows="3" placeholder="e.g. Returns accepted within 7 days with original packaging."><?= htmlspecialchars($biz['return_policy'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ══════════════ TAB: PRINTER & POS ══════════════ -->
                        <div class="settings-tab-pane" data-pane="printer">
                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-receipt text-primary"></i> Printer Format</h5>
                                    <div class="mb-1">
                                        <label class="form-label">Bill Printer Type</label>
                                        <select name="printer_format" class="form-select">
                                            <option value="a4" <?= ($biz['printer_format'] ?? 'a4') === 'a4' ? 'selected' : '' ?>>A4 / Regular Printer</option>
                                            <option value="thermal_58" <?= ($biz['printer_format'] ?? '') === 'thermal_58' ? 'selected' : '' ?>>Thermal — 58mm Roll</option>
                                            <option value="thermal_80" <?= ($biz['printer_format'] ?? '') === 'thermal_80' ? 'selected' : '' ?>>Thermal — 80mm Roll</option>
                                        </select>
                                        <div class="form-text">Controls the default print layout used from Billing/POS.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-keyboard text-primary"></i> POS Print &amp; Shortcuts</h5>

                                    <div class="mb-3">
                                        <label class="form-label">When a sale is completed at Billing / POS</label>
                                        <select name="pos_print_mode" class="form-select">
                                            <option value="thermal" <?= ($biz['pos_print_mode'] ?? 'both') === 'thermal' ? 'selected' : '' ?>>Print directly on Thermal (58/80mm, using the Bill Printer Type above)</option>
                                            <option value="a4" <?= ($biz['pos_print_mode'] ?? 'both') === 'a4' ? 'selected' : '' ?>>Print directly on A4</option>
                                            <option value="both" <?= ($biz['pos_print_mode'] ?? 'both') === 'both' ? 'selected' : '' ?>>Open the invoice and let me choose (current behaviour)</option>
                                        </select>
                                        <div class="form-text">This only controls what happens right after a POS sale — the Printer Format setting above still controls the thermal roll width.</div>
                                    </div>

                                    <hr>

                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Complete Sale</label>
                                            <select name="shortcut_complete_sale" class="form-select">
                                                <?php for ($i = 2; $i <= 12; $i++): $fk = 'F' . $i; ?>
                                                <option value="<?= $fk ?>" <?= ($biz['shortcut_complete_sale'] ?? 'F2') === $fk ? 'selected' : '' ?>><?= $fk ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Print Last Bill</label>
                                            <select name="shortcut_print" class="form-select">
                                                <?php for ($i = 2; $i <= 12; $i++): $fk = 'F' . $i; ?>
                                                <option value="<?= $fk ?>" <?= ($biz['shortcut_print'] ?? 'F3') === $fk ? 'selected' : '' ?>><?= $fk ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Start New Sale</label>
                                            <select name="shortcut_new_sale" class="form-select">
                                                <?php for ($i = 2; $i <= 12; $i++): $fk = 'F' . $i; ?>
                                                <option value="<?= $fk ?>" <?= ($biz['shortcut_new_sale'] ?? 'F4') === $fk ? 'selected' : '' ?>><?= $fk ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-text">These keys work on the Billing / POS screen only — pick keys that don't clash if your browser uses them for something else.</div>
                                </div>
                            </div>
                        </div>

                        <!-- ══════════════ TAB: BARCODE & ORDERS ══════════════ -->
                        <div class="settings-tab-pane" data-pane="orders">
                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-barcode text-primary"></i> Barcode Label</h5>
                                    <div class="mb-1">
                                        <label class="form-label">Custom Text on Labels</label>
                                        <input type="text" name="barcode_footer_text" class="form-control" placeholder="e.g. Your Shop Name" value="<?= htmlspecialchars($biz['barcode_footer_text'] ?? '') ?>">
                                        <div class="form-text">Printed under the barcode on every label — e.g. your shop's name or a tagline.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="gd-card">
                                <div class="gd-card-body">
                                    <h5 class="mb-3"><i class="fas fa-hashtag text-primary"></i> Order ID Format</h5>
                                    <div class="mb-1">
                                        <label class="form-label">Order ID Prefix</label>
                                        <input type="text" name="order_id_prefix" class="form-control text-uppercase" maxlength="10" placeholder="e.g. PJ" value="<?= htmlspecialchars($biz['order_id_prefix'] ?? 'ORD') ?>">
                                        <div class="form-text">
                                            Next order will be numbered
                                            <strong><?= htmlspecialchars($biz['order_id_prefix'] ?? 'ORD') ?><?= str_pad((string)($biz['order_sequence_next'] ?? 1), 7, '0', STR_PAD_LEFT) ?></strong>
                                            — applies to both Billing/POS and online orders.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="settings-save-bar">
                            <button type="submit" class="btn btn-primary btn-lg px-4"><i class="fas fa-save"></i> Save Settings</button>
                        </div>

                    </div>

                    <div class="col-lg-5">

                        <!-- LOGO & WEBSITE HEADER -->
                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-3"><i class="fas fa-image text-primary"></i> Logo &amp; Branding</h5>
                                <div id="logoPreviewBox">
                                    <?php if (!empty($biz['logo'])): ?>
                                        <img id="logoPreviewImg" src="/<?= htmlspecialchars($biz['logo']) ?>" style="width:<?= (int)($biz['logo_display_width'] ?? 150) ?>px;">
                                    <?php else: ?>
                                        <span id="logoPreviewImg" class="text-muted"><i class="fas fa-image fa-2x"></i><br>No logo uploaded</span>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="logo" id="logoFileInput" class="form-control mb-3" accept="image/*">
                                <label class="form-label d-flex justify-content-between">
                                    <span>Logo Display Size</span>
                                    <span><span id="logoWidthLabel"><?= (int)($biz['logo_display_width'] ?? 150) ?></span>px wide</span>
                                </label>
                                <input type="range" name="logo_display_width" id="logoWidthSlider" class="form-range mb-3" min="40" max="400" step="10" value="<?= (int)($biz['logo_display_width'] ?? 150) ?>">

                                <label class="form-label">Website Header Display</label>
                                <div class="display-mode-options mb-1">
                                    <?php $shd = $biz['site_header_display'] ?? 'both'; ?>
                                    <label><input type="radio" name="site_header_display" value="title" <?= $shd === 'title' ? 'checked' : '' ?>> <span>Title Only</span></label>
                                    <label><input type="radio" name="site_header_display" value="logo" <?= $shd === 'logo' ? 'checked' : '' ?>> <span>Logo Only</span></label>
                                    <label><input type="radio" name="site_header_display" value="both" <?= $shd === 'both' ? 'checked' : '' ?>> <span>Logo + Title</span></label>
                                </div>
                            </div>
                        </div>

                        <!-- INVOICE / PRINT DISPLAY -->
                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-3"><i class="fas fa-print text-primary"></i> Invoice &amp; Print Display</h5>

                                <label class="form-label">Invoice Header Display</label>
                                <div class="display-mode-options mb-3">
                                    <?php $ivd = $biz['invoice_display'] ?? 'both'; ?>
                                    <label><input type="radio" name="invoice_display" class="invoice-preview-trigger" value="logo" <?= $ivd === 'logo' ? 'checked' : '' ?>> <span>Logo Only</span></label>
                                    <label><input type="radio" name="invoice_display" class="invoice-preview-trigger" value="name" <?= $ivd === 'name' ? 'checked' : '' ?>> <span>Name Only</span></label>
                                    <label><input type="radio" name="invoice_display" class="invoice-preview-trigger" value="both" <?= $ivd === 'both' ? 'checked' : '' ?>> <span>Logo + Name</span></label>
                                </div>

                                <div class="form-check mb-1">
                                    <input class="form-check-input invoice-preview-trigger" type="checkbox" name="show_address_on_invoice" id="showAddrCheck" <?= !isset($biz['show_address_on_invoice']) || (int)$biz['show_address_on_invoice'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="showAddrCheck">Show Address on invoice</label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input invoice-preview-trigger" type="checkbox" name="show_location_on_invoice" id="showLocCheck" <?= !isset($biz['show_location_on_invoice']) || (int)$biz['show_location_on_invoice'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="showLocCheck">Show Location on invoice</label>
                                </div>

                                <label class="form-label">Contact Number(s) to Print</label>
                                <div id="invoiceNumbersWrap" class="mb-3">
                                    <?php foreach ($contact_numbers as $num): if (!$num) continue; ?>
                                    <div class="form-check">
                                        <input class="form-check-input invoice-preview-trigger" type="checkbox" name="invoice_numbers[]" value="<?= htmlspecialchars($num) ?>" id="invnum-<?= md5($num) ?>" <?= in_array($num, $invoice_numbers, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="invnum-<?= md5($num) ?>"><?= htmlspecialchars($num) ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <label class="form-label d-block">GST / Tax Details to Print</label>
                                <div class="form-check mb-1">
                                    <input class="form-check-input invoice-preview-trigger" type="checkbox" name="show_fssai_on_invoice" id="showFssaiCheck" <?= !isset($biz['show_fssai_on_invoice']) || (int)$biz['show_fssai_on_invoice'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="showFssaiCheck">Show FSSAI No.</label>
                                </div>
                                <div class="form-check mb-1">
                                    <input class="form-check-input invoice-preview-trigger" type="checkbox" name="show_gstin_on_invoice" id="showGstinCheck" <?= !isset($biz['show_gstin_on_invoice']) || (int)$biz['show_gstin_on_invoice'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="showGstinCheck">Show GSTIN</label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input invoice-preview-trigger" type="checkbox" name="show_pan_on_invoice" id="showPanCheck" <?= !isset($biz['show_pan_on_invoice']) || (int)$biz['show_pan_on_invoice'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="showPanCheck">Show PAN</label>
                                </div>

                                <label class="form-label">Complete Live Preview <small class="text-muted">(exactly how a real invoice will look)</small></label>
                                <div class="thermal-preview-wrap">
                                    <div class="thermal-preview" id="thermalPreview"></div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </form>

        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
const SOCIAL_PLATFORMS = <?= json_encode($SOCIAL_PLATFORMS) ?>;

function addContactNumber() {
    const wrap = document.getElementById('contactNumbersWrap');
    const row = document.createElement('div');
    row.className = 'contact-number-row';
    row.innerHTML = '<input type="text" name="contact_numbers[]" class="form-control contact-num-input" placeholder="Phone number">' +
        '<button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>';
    wrap.appendChild(row);
}

function addSocialRow() {
    const wrap = document.getElementById('socialWrap');
    const opts = Object.keys(SOCIAL_PLATFORMS).map(k => `<option value="${k}">${SOCIAL_PLATFORMS[k].label}</option>`).join('');
    const row = document.createElement('div');
    row.className = 'social-row';
    row.innerHTML = `<i class="fas fa-link social-icon"></i>
        <select name="social_platform[]" class="form-select form-select-sm">${opts}</select>
        <input type="url" name="social_url[]" class="form-control form-control-sm" placeholder="Profile URL">
        <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>`;
    wrap.appendChild(row);
}

// ── Settings tabs ─────────────────────────────────────────────────────────────
document.querySelectorAll('.settings-tab-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.settings-tab-pane').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.querySelector('[data-pane="' + this.dataset.tab + '"]').classList.add('active');
    });
});

// Live logo preview + size slider
const logoFileInput = document.getElementById('logoFileInput');
const logoPreviewBox = document.getElementById('logoPreviewBox');
const logoWidthSlider = document.getElementById('logoWidthSlider');
const logoWidthLabel = document.getElementById('logoWidthLabel');
let logoDataUrl = null;

logoFileInput.addEventListener('change', function (e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (ev) {
        logoDataUrl = ev.target.result;
        logoPreviewBox.innerHTML = '<img id="logoPreviewImg" src="' + logoDataUrl + '" style="width:' + logoWidthSlider.value + 'px;">';
        updateThermalPreview();
    };
    reader.readAsDataURL(file);
});

logoWidthSlider.addEventListener('input', function () {
    logoWidthLabel.textContent = this.value;
    const img = document.getElementById('logoPreviewImg');
    if (img && img.tagName === 'IMG') img.style.width = this.value + 'px';
});

// ── Live thermal preview ─────────────────────────────────────────────────────
const ORDER_PREFIX = <?= json_encode($biz['order_id_prefix'] ?? 'ORD') ?>;

function updateThermalPreview() {
    const preview = document.getElementById('thermalPreview');
    const bizName = document.getElementById('fBizName').value || 'Your Business Name';
    const invoiceDisplay = document.querySelector('input[name="invoice_display"]:checked')?.value || 'both';
    const existingImg = document.querySelector('#logoPreviewImg');
    const logoSrc = logoDataUrl || (existingImg && existingImg.tagName === 'IMG' ? existingImg.src : null);
    const esc = s => (s || '').replace(/</g, '');

    let html = '<div class="t-center">';
    if ((invoiceDisplay === 'logo' || invoiceDisplay === 'both') && logoSrc) {
        html += `<img src="${logoSrc}">`;
    }
    if (invoiceDisplay === 'name' || invoiceDisplay === 'both' || !logoSrc) {
        html += `<div class="biz-name">${esc(bizName)}</div>`;
    }
    html += '</div>';

    // Same order as the real invoice: address, location, then numbers, then tax IDs
    if (document.getElementById('showAddrCheck').checked) {
        const addr = document.getElementById('fAddress').value;
        if (addr) html += `<div>${esc(addr)}</div>`;
    }
    if (document.getElementById('showLocCheck').checked) {
        const loc = document.getElementById('fLocation').value;
        if (loc) html += `<div>${esc(loc)}</div>`;
    }
    const checkedNumbers = Array.from(document.querySelectorAll('#invoiceNumbersWrap input:checked')).map(el => el.value);
    if (checkedNumbers.length) html += `<div>Tel. : ${esc(checkedNumbers.join(', '))}</div>`;

    if (document.getElementById('showFssaiCheck').checked && document.getElementById('fFssai').value) {
        html += `<div>FSSAI License No. : ${esc(document.getElementById('fFssai').value)}</div>`;
    }
    const gstinChecked = document.getElementById('showGstinCheck').checked && document.getElementById('fGstin').value;
    if (gstinChecked) {
        html += `<div>GSTIN : ${esc(document.getElementById('fGstin').value)}</div>`;
    }
    if (document.getElementById('showPanCheck').checked && document.getElementById('fPan').value) {
        html += `<div>PAN : ${esc(document.getElementById('fPan').value)}</div>`;
    }
    html += '<div class="t-line"></div>';

    // ── Demo order details, so the whole receipt can be previewed end to end ──
    const now = new Date();
    const demoDate = now.toLocaleDateString('en-GB') + '  ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
    html += `<div>Invoice No. : ${esc(ORDER_PREFIX)}0230001</div>`;
    html += `<div>Date : ${demoDate}</div>`;
    html += '<div class="t-line"></div>';

    const demoItems = [
        { name: 'Demo Product A', rate: 150.00, qty: 2 },
        { name: 'Demo Product B', rate: 89.50, qty: 1 },
    ];
    html += '<table class="inv-table"><thead><tr><th>Item Name :</th><th class="text-end">Rate</th><th class="text-end">Qty.</th><th class="text-end">Amount</th></tr></thead><tbody>';
    let subtotal = 0;
    demoItems.forEach(it => {
        const amt = it.rate * it.qty;
        subtotal += amt;
        html += `<tr><td>${it.name}</td><td class="text-end">${it.rate.toFixed(2)}</td><td class="text-end">${it.qty}</td><td class="text-end">${amt.toFixed(2)}</td></tr>`;
    });
    html += '</tbody></table><div class="t-line"></div>';

    const totalQty = demoItems.reduce((s, it) => s + it.qty, 0);
    html += `<div style="display:flex;justify-content:space-between;"><span>Items :  ${demoItems.length.toFixed(2)}</span><span>Qty. :  ${totalQty}</span></div>`;

    let gst = 0;
    if (gstinChecked) gst = subtotal * 0.18;
    const grand = subtotal + gst;

    html += '<div class="inv-totals">';
    html += `<div><span>Sub Total :</span><span>${subtotal.toFixed(2)}</span></div>`;
    if (gstinChecked) {
        html += `<div><span>CGST :</span><span>${(gst / 2).toFixed(2)}</span></div>`;
        html += `<div><span>SGST :</span><span>${(gst / 2).toFixed(2)}</span></div>`;
    }
    html += `<div class="grand"><span>Total :</span><span>${grand.toFixed(2)}</span></div>`;
    html += `<div><span>Cash Paid :</span><span>₹${grand.toFixed(2)}</span></div>`;
    html += '</div>';

    html += '<div class="t-line"></div><div class="t-center">Thank you, visit again!</div>';

    preview.innerHTML = html;
}

document.querySelectorAll('#fBizName, #fAddress, #fLocation, #fGstin, #fPan, #fFssai').forEach(el => el.addEventListener('input', updateThermalPreview));
document.querySelectorAll('.invoice-preview-trigger').forEach(el => el.addEventListener('change', updateThermalPreview));
updateThermalPreview();
</script>
</body>
</html>

<?php
// ============================================================
// SECTION 1: GLOBAL PATH CONSTANTS
// ============================================================
define('DROOT_PATH',      $_SERVER['DOCUMENT_ROOT']);        // /var/www/html
define('UPLOAD_DIR_URL', 'uploads/');                       // DB path  (relative, no leading slash)
define('UPLOAD_DIR_ABS', DROOT_PATH . '/uploads/');          // Disk path (absolute)

// ============================================================
// SECTION 2: BOOTSTRAP
// ============================================================
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';


// ============================================================
// SECTION 3: AUTH CHECK
// ============================================================
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['admin', 'editor', 'author'])
) {
    exit('Access Denied');
}

$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
$username = $_SESSION['username'];

// ============================================================
// SECTION 3b: CSRF TOKEN (generated here, verified inside SECTION 9
// at the point of actual form submission — kept scoped so it does not
// interfere with the legacy image-upload POST in SECTION 8)
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// SECTION 4: DETECT EDIT MODE
// ============================================================
$is_edit   = isset($_GET['id']) && is_numeric($_GET['id']);
$post_id   = $is_edit ? (int)$_GET['id'] : null;
$post_data = null;

// ============================================================
// SECTION 5: FETCH POST DATA (edit mode)
// ============================================================
if ($is_edit) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   pm_keywords.meta_value AS keywords,
                   pm_desc.meta_value     AS description,
                   pm_fbdesc.meta_value   AS fb_description
            FROM posts p
            LEFT JOIN post_meta pm_keywords ON p.id = pm_keywords.post_id AND pm_keywords.meta_key = 'keywords'
            LEFT JOIN post_meta pm_desc     ON p.id = pm_desc.post_id     AND pm_desc.meta_key     = 'description'
            LEFT JOIN post_meta pm_fbdesc   ON p.id = pm_fbdesc.post_id   AND pm_fbdesc.meta_key   = 'fb_description'
            WHERE p.id = ?
        ");
        $stmt->execute([$post_id]);
        $post_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post_data) {
            die('Post not found');
        }
        if ($_SESSION['role'] !== 'admin') {
            $stmt_own = $pdo->prepare("
                SELECT 1 FROM authors WHERE id = ? AND user_id = ?
            ");
            $stmt_own->execute([$post_data['author_id'], $_SESSION['user_id']]);
            if (!$stmt_own->fetch()) {
                die('Access Denied');
            }
        }
    } catch (PDOException $e) {
        die('Error loading post: ' . $e->getMessage());
    }
}

// ============================================================
// SECTION 6: FETCH ADDITIONAL CATEGORIES (edit mode)
// ============================================================
$additional_category_ids = [];
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT category_id FROM post_categories WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $additional_category_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'category_id');
}

// ============================================================
// SECTION 7: FETCH ALL TAGS + SELECTED TAGS (edit mode)
// ============================================================
$all_tags        = $pdo->query("SELECT * FROM tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$selected_tag_ids = [];
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT tag_id FROM post_tag WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $selected_tag_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'tag_id');
}

// ============================================================
// SECTION 8: HANDLE LEGACY IMAGE UPLOAD (Quill/TinyMCE toolbar image)
// ============================================================
if (isset($_FILES['image']) && !isset($_POST['title'])) {
    if (!file_exists(UPLOAD_DIR_ABS)) {
        mkdir(UPLOAD_DIR_ABS, 0777, true);
    }

    $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $file_name      = uniqid('img_') . '.' . $file_extension;
    $db_path        = UPLOAD_DIR_URL . $file_name;   // saved in DB
    $disk_path      = UPLOAD_DIR_ABS . $file_name;   // written to disk

    if (move_uploaded_file($_FILES['image']['tmp_name'], $disk_path)) {
        $stmt = $pdo->prepare("INSERT INTO media (file_path, file_type, uploaded_at) VALUES (?, 'image', NOW())");
        $stmt->execute([$db_path]);
        echo json_encode(['location' => $db_path]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

// ============================================================
// SECTION 9: HANDLE FORM SUBMISSION (create + update)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {

    // --- CSRF check (only for the real post-save submission) ---
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('Security error: invalid CSRF token. Please refresh the page and try again.');
    }

    try {
        $pdo->beginTransaction();

        // --- 9a. Sanitize inputs ---
        $title                   = trim($_POST['title']);
        $content                 = $_POST['content'];
        $faq_json                = !empty($_POST['faq_json'])        ? $_POST['faq_json']              : null;
        $last_date               = !empty($_POST['last_date'])       ? $_POST['last_date']             : null;
        $author_id               = $_POST['author_id'];
        $state_id                = !empty($_POST['state_id'])        ? (int)$_POST['state_id']         : null;
        $category_id             = !empty($_POST['category_id'])     ? (int)$_POST['category_id']      : null;
        $additional_category_ids = !empty($_POST['additional_category_ids'])
                                   ? array_map('intval', $_POST['additional_category_ids'])
                                   : [];
        $additional_category_ids = array_diff($additional_category_ids, [$category_id]);
        $slug                    = !empty($_POST['slug'])            ? $_POST['slug']                  : generateSlug($title);
        $status                  = $_POST['status']                  ?? 'draft';
        $meta_keywords           = $_POST['meta_keywords']           ?? '';
        $meta_description        = $_POST['meta_description']        ?? '';
        $fb_description          = $_POST['fb_description']          ?? '';
        $edit_post_id            = $_POST['post_id']                 ?? null;
        $featured_image_id       = $_POST['existing_featured_image_id'] ?? null;

        // --- 9b. Scheduling ---
        $publish_at = null;
        if ($status === 'scheduled' && !empty($_POST['publish_at'])) {
            $publish_at = date('Y-m-d H:i:s', strtotime($_POST['publish_at']));
        }

        // --- 9c. Logging variables ---
        $log_ip = $_SERVER['REMOTE_ADDR']     ?? '';
        $log_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // --------------------------------------------------------
        // 9d. Banner upload (multi-size WebP responsive set)
        // --------------------------------------------------------
        if (
            isset($_FILES['banner']['name'], $_FILES['banner']['tmp_name'])
            && is_array($_FILES['banner']['name'])
            && array_filter($_FILES['banner']['name'])
        ) {
            if (!file_exists(UPLOAD_DIR_ABS)) {
                mkdir(UPLOAD_DIR_ABS, 0777, true);
            }

            // Delete old banner files from disk + DB row
            if ($featured_image_id) {
                $stmt = $pdo->prepare("SELECT file_path, responsive_set FROM media WHERE id = ?");
                $stmt->execute([$featured_image_id]);
                if ($old = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($old['file_path']) && file_exists(DROOT_PATH . '/' . $old['file_path'])) {
                        @unlink(DROOT_PATH . '/' . $old['file_path']);
                    }
                    if (!empty($old['responsive_set'])) {
                        foreach (json_decode($old['responsive_set'], true) ?: [] as $v) {
                            if (!empty($v) && file_exists(DROOT_PATH . '/' . $v)) {
                                @unlink(DROOT_PATH . '/' . $v);
                            }
                        }
                    }
                    $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$featured_image_id]);
                }
            }

            // Upload new responsive set
            $banner_versions = [];
            foreach ($_FILES['banner']['name'] as $i => $name) {
                if ($_FILES['banner']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp       = $_FILES['banner']['tmp_name'][$i];
                    $filename  = $slug . '-' . uniqid() . '.webp';
                    $db_path   = UPLOAD_DIR_URL . $filename;   // stored in DB
                    $disk_path = UPLOAD_DIR_ABS . $filename;   // written to disk

                    if (move_uploaded_file($tmp, $disk_path)) {
                        if      (strpos($name, '_xl') !== false) $banner_versions['xl']           = $db_path;
                        elseif  (strpos($name, '_lg') !== false) $banner_versions['lg']           = $db_path;
                        elseif  (strpos($name, '_md') !== false) $banner_versions['md']           = $db_path;
                        elseif  (strpos($name, '_sm') !== false) $banner_versions['sm']           = $db_path;
                        else                                     $banner_versions['auto_' . $i]   = $db_path;
                    }
                }
            }

            if (!empty($banner_versions)) {
                $main_banner = $banner_versions['xl'] ?? reset($banner_versions);
                $stmt = $pdo->prepare("
                    INSERT INTO media (file_path, responsive_set, file_type, alt_text, uploaded_at)
                    VALUES (?, ?, 'banner', ?, NOW())
                ");
                $stmt->execute([$main_banner, json_encode($banner_versions), $title . ' banner']);
                $featured_image_id = $pdo->lastInsertId();
            }
        }

        // --------------------------------------------------------
        // 9e. Banner selected from Media Library (existing media id, no new upload)
        // --------------------------------------------------------
        // Handled implicitly: existing_featured_image_id already carries the
        // media-library-picked id through when no new file was uploaded above.

        // --------------------------------------------------------
        // 9f. Process base64 inline images from TinyMCE content
        // --------------------------------------------------------
        $content = preg_replace_callback(
            '/<img[^>]+src=["\']data:image\/([a-zA-Z]+);base64,([^"\']+)["\']([^>]*)>/i',
            function ($matches) {
                $image_type  = $matches[1];
                $base64      = $matches[2];
                $extra_attrs = $matches[3];

                $image_data = base64_decode($base64, true);
                if ($image_data === false) return $matches[0];

                if (!file_exists(UPLOAD_DIR_ABS)) {
                    mkdir(UPLOAD_DIR_ABS, 0777, true);
                }

                $file      = uniqid('img_') . '.' . $image_type;
                $db_path   = UPLOAD_DIR_URL . $file;   // stored in DB & src attr
                $disk_path = UPLOAD_DIR_ABS . $file;   // written to disk

                if (file_put_contents($disk_path, $image_data)) {
                    $alt_text = '';
                    if (preg_match('/alt=["\']([^"\']*)["\']/', $extra_attrs, $alt_matches)) {
                        $alt_text = $alt_matches[1];
                    }
                    try {
                        $stmt = $GLOBALS['pdo']->prepare("
                            INSERT INTO media (file_path, file_type, alt_text, uploaded_at)
                            VALUES (?, 'image', ?, NOW())
                        ");
                        $stmt->execute([$db_path, $alt_text]);
                    } catch (PDOException $e) {
                        error_log('Failed to insert media: ' . $e->getMessage());
                    }
                    return '<img src="' . $db_path . '"' . $extra_attrs . '>';
                }

                return $matches[0];
            },
            $content
        );

        // Normalize featured_image_id
        if (empty($featured_image_id) || !is_numeric($featured_image_id)) {
            $featured_image_id = null;
        } else {
            $featured_image_id = (int)$featured_image_id;
        }

        // --------------------------------------------------------
        // 9g. Save post (create or update)
        // --------------------------------------------------------
        $current_post_id = null;

        if ($edit_post_id) {
            // UPDATE
            $stmt = $pdo->prepare("
                UPDATE posts
                SET title = ?, content = ?, faq_json = ?, last_date = ?, author_id = ?,
                    state_id = ?, category_id = ?, slug = ?, status = ?, publish_at = ?,
                    featured_image_id = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $title, $content, $faq_json, $last_date, $author_id,
                $state_id, $category_id, $slug, $status, $publish_at,
                $featured_image_id, $edit_post_id
            ]);
            $current_post_id = $edit_post_id;

            // Delete cache file on update
            $cache_file = __DIR__ . '/cache/post_' . $edit_post_id . '.html';
            if (file_exists($cache_file)) {
                @unlink($cache_file);
            }

            // Clear old meta
            $pdo->prepare("DELETE FROM post_meta WHERE post_id = ?")->execute([$current_post_id]);

        } else {
            // CREATE
            $stmt = $pdo->prepare("
                INSERT INTO posts (title, content, faq_json, last_date, author_id, state_id,
                                   category_id, slug, status, publish_at, featured_image_id, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $title, $content, $faq_json, $last_date, $author_id,
                $state_id, $category_id, $slug, $status, $publish_at, $featured_image_id
            ]);
            $current_post_id = $pdo->lastInsertId();

            // Link orphaned base64 images to this post
            $stmt = $pdo->prepare("UPDATE media SET post_id = ? WHERE post_id IS NULL AND file_path LIKE 'uploads/img_%'");
            $stmt->execute([$current_post_id]);
        }

        // --------------------------------------------------------
        // 9h. Save meta (keywords, description, fb_description)
        // --------------------------------------------------------
        if (!empty($meta_keywords)) {
            $stmt = $pdo->prepare("INSERT INTO post_meta (post_id, meta_key, meta_value) VALUES (?, 'keywords', ?)");
            $stmt->execute([$current_post_id, $meta_keywords]);
        }
        if (!empty($meta_description)) {
            $stmt = $pdo->prepare("INSERT INTO post_meta (post_id, meta_key, meta_value) VALUES (?, 'description', ?)");
            $stmt->execute([$current_post_id, $meta_description]);
        }
        if (!empty($fb_description)) {
            $stmt = $pdo->prepare("INSERT INTO post_meta (post_id, meta_key, meta_value) VALUES (?, 'fb_description', ?)");
            $stmt->execute([$current_post_id, $fb_description]);
        }

        // --------------------------------------------------------
        // 9i. Save categories (main + additional)
        // --------------------------------------------------------
        try {
            $pdo->prepare("DELETE FROM post_categories WHERE post_id = ?")->execute([$current_post_id]);

            if ($category_id) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");
                $stmt->execute([$current_post_id, $category_id]);
            }
            if (!empty($additional_category_ids)) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");
                foreach ($additional_category_ids as $cat_id) {
                    $stmt->execute([$current_post_id, $cat_id]);
                }
            }
        } catch (Exception $e) {
            error_log('Failed to save post categories: ' . $e->getMessage());
        }

        // --------------------------------------------------------
        // 9j. Save tags (create new tag if not exists)
        // --------------------------------------------------------
        try {
            $pdo->prepare("DELETE FROM post_tag WHERE post_id = ?")->execute([$current_post_id]);

            if (!empty($_POST['tags']) && is_array($_POST['tags'])) {
                $tags_input      = array_unique($_POST['tags']);
                $stmt_check_slug = $pdo->prepare("SELECT id FROM tags WHERE slug = ? LIMIT 1");
                $stmt_insert_tag = $pdo->prepare("INSERT INTO tags (name, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                $stmt_link_tag   = $pdo->prepare("INSERT IGNORE INTO post_tag (post_id, tag_id) VALUES (?, ?)");

                foreach ($tags_input as $input_val) {
                    $tag_id_to_link = null;

                    if (is_numeric($input_val)) {
                        $tag_id_to_link = (int)$input_val;
                    } else {
                        $tag_name = trim($input_val);
                        if (empty($tag_name)) continue;

                        $tag_slug = generateSlug($tag_name);
                        $stmt_check_slug->execute([$tag_slug]);
                        $existing_tag = $stmt_check_slug->fetch(PDO::FETCH_ASSOC);

                        if ($existing_tag) {
                            $tag_id_to_link = $existing_tag['id'];
                        } else {
                            try {
                                $stmt_insert_tag->execute([$tag_name, $tag_slug]);
                                $tag_id_to_link = $pdo->lastInsertId();
                            } catch (Exception $e) {
                                error_log("Error creating new tag '$tag_name': " . $e->getMessage());
                                continue;
                            }
                        }
                    }

                    if ($tag_id_to_link) {
                        $stmt_link_tag->execute([$current_post_id, $tag_id_to_link]);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Failed to process tags: ' . $e->getMessage());
        }

        // --------------------------------------------------------
        // 9k. Commit transaction
        // --------------------------------------------------------
        $pdo->commit();

        // --------------------------------------------------------
        // 9l. Activity log
        // --------------------------------------------------------
        try {
            $post_id_for_url = $edit_post_id ?? $current_post_id;
            $log_action_type = $edit_post_id ? 'post_edit'   : 'post_create';
            $log_verb        = $edit_post_id ? 'Updated'     : 'Created';
            $log_desc        = $log_verb . ' Post: ' . mb_strimwidth($title, 0, 50, '...') . ' (ID: ' . $post_id_for_url . ')';

            $stmt_log = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt_log->execute([$_SESSION['user_id'], $log_action_type, $log_desc, $log_ip, $log_ua]);
        } catch (Exception $e) {
            error_log('Activity log failed: ' . $e->getMessage());
        }

        // --------------------------------------------------------
        // 9m. Redirect after success
        // --------------------------------------------------------
        header('Location: /admin/dashboard.php?success=' . ($edit_post_id ? 'updated' : 'created'));
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Database error: ' . $e->getMessage());
        $form_error = 'Failed to save post: ' . htmlspecialchars($e->getMessage());
    }
}

// ============================================================
// SECTION 10: FETCH FORM DATA (authors, categories, states, templates)
// ============================================================
$stmt = $pdo->prepare("SELECT * FROM authors WHERE user_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$states     = $pdo->query("SELECT id, state_name FROM states ORDER BY state_name")->fetchAll(PDO::FETCH_ASSOC);
$templates  = $pdo->query("SELECT * FROM table_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// SECTION 11: PAGE VARIABLES
// ============================================================
$seo_robots = 'noindex, nofollow, noarchive, nosnippet';

// Featured image / banner data (for the Featured Image panel preview)
$banner_data = null;
if ($is_edit && !empty($post_data['featured_image_id'])) {
    $stmt = $pdo->prepare("SELECT file_path, responsive_set FROM media WHERE id = ?");
    $stmt->execute([$post_data['featured_image_id']]);
    $banner_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Default (not-yet-configurable) FB comment-copy template.
// TODO: move to a settings table once app-wide config storage exists.
$fb_comment_copy_text = "Just watched Part 2... wasn't expecting that ending! Here's the link \xF0\x9F\x91\x89 ";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title><?= $is_edit ? 'Edit' : 'Create' ?> Post - <?= htmlspecialchars($site_name) ?></title>
<link rel="icon" type="image/png" sizes="16x16" href="/assets/icons/favicon-16x16.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'" crossorigin="anonymous">

<!-- TinyMCE -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/8.8.2/tinymce.min.js" defer></script>

<!-- Cropper -->
<link href="/assets/css/cropper.min.css" rel="stylesheet">
<script src="/assets/js/cropper.min.js"></script>

<!-- Choices.js (additional categories) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<!-- Tom Select (tags) -->
<link href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.default.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>

<!-- Global Popup actions -->
<script src="/assets/js/gp.js"></script>

<script>
!function () {
    let e = localStorage.dm === "1", t = !1, n = !1,
        s = () => new Promise((e, r) => {
            let o = document.createElement("script");
            o.src = "/assets/js/darkreader.min.js";
            o.onload = () => { t = !0; e(); };
            o.onerror = r;
            document.head.appendChild(o);
        }),
        a = () => DarkReader.enable({ brightness: 100, contrast: 100, sepia: 10 }),
        d = () => DarkReader.disable(),
        i = () => {
            document.querySelectorAll(".dark-mode-toggle i").forEach(o => {
                o.classList.add("rotate");
                if (e) { o.classList.remove("fa-moon"); o.classList.add("fa-sun"); }
                else { o.classList.remove("fa-sun"); o.classList.add("fa-moon"); }
                setTimeout(() => o.classList.remove("rotate"), 400);
            });
        };
    e && (t ? a() : s().then(a));
    document.addEventListener("DOMContentLoaded", () => {
        i();
        document.querySelectorAll(".dark-mode-toggle").forEach(o => o.onclick = r);
    });
    async function r(o) {
        o.preventDefault();
        if (n) return;
        n = !0;
        let c = document.querySelectorAll(".dark-mode-toggle");
        c.forEach(e => e.classList.add("loading"));
        try {
            t || await s();
            e = !e;
            localStorage.dm = e ? "1" : "0";
            e ? a() : d();
            i();
        } finally {
            setTimeout(() => { c.forEach(e => e.classList.remove("loading")); n = !1; }, 600);
        }
    }
}();
</script>

<style>
:root {
    --primary: #7c3aed;
    --primary-dark: #6d28d9;
    --primary-light: #ede9fe;
    --primary-lighter: #f5f3ff;
    --success: #10b981;
    --success-light: #ecfdf5;
    --warning: #f59e0b;
    --warning-light: #fef3c7;
    --danger: #ef4444;
    --danger-light: #fef2f2;
    --info: #3b82f6;
    --info-light: #eff6ff;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --radius: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    --sidebar-width: 280px;
}
* { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--gray-50); color: var(--gray-800); line-height: 1.5; }

.admin-container { display: grid; grid-template-columns: 1fr; min-height: 100vh; }
@media (min-width: 1024px) { .admin-container { grid-template-columns: var(--sidebar-width) 1fr; } }

.sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar-width); background: white; border-right: 1px solid var(--gray-200); z-index: 1000; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4,0,0.2,1); overflow-y: auto; display: flex; flex-direction: column; }
.sidebar.open { transform: translateX(0); }
@media (min-width: 1024px) { .sidebar { position: sticky; transform: translateX(0); height: 100vh; top: 0; } }
.sidebar-header { padding: 1.5rem; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; }
.brand { display: flex; align-items: center; gap: 0.65rem; font-size: 1.15rem; font-weight: 800; color: var(--primary); text-decoration: none; }
.brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
.close-sidebar { width: 36px; height: 36px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--gray-600); transition: all 0.2s; }
.close-sidebar:hover { background: var(--gray-200); }
@media (min-width: 1024px) { .close-sidebar { display: none; } }
.sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
.nav-section { margin-bottom: 1.5rem; padding: 0 1rem; }
.nav-title { font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gray-400); padding: 0 1rem; margin-bottom: 0.75rem; }
.nav-link { display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem 1rem; border-radius: var(--radius); color: var(--gray-600); text-decoration: none; font-size: 0.9375rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.25rem; }
.nav-link:hover { background: var(--gray-50); color: var(--gray-900); }
.nav-link.active { background: var(--primary-lighter); color: var(--primary); font-weight: 600; }
.nav-link i { width: 24px; text-align: center; font-size: 1.125rem; }
.sidebar-footer { padding: 1rem; border-top: 1px solid var(--gray-100); }
.user-card { display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem; background: var(--gray-50); border-radius: var(--radius-lg); }
.user-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; }
.user-info { flex: 1; min-width: 0; }
.user-name { font-weight: 600; color: var(--gray-900); font-size: 0.9375rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 0.75rem; color: var(--gray-500); }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; visibility: hidden; transition: all 0.3s; }
.sidebar-overlay.active { opacity: 1; visibility: visible; }
@media (min-width: 1024px) { .sidebar-overlay { display: none; } }

.dark-mode-toggle i { transition: transform .4s ease, opacity .3s ease; }
.dark-mode-toggle i.rotate { transform: rotate(180deg); }

.main-content { min-width: 0; }
.top-nav { position: sticky; top: 0; background: white; border-bottom: 1px solid var(--gray-200); padding: 0.450rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; z-index: 100; flex-wrap: wrap; }
.nav-left { display: flex; align-items: center; gap: 1rem; }
.menu-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-700); cursor: pointer; transition: all 0.2s; }
.menu-toggle:hover { background: var(--gray-200); }
.sitename-mob { display: block; font-size: 1.15rem; font-weight: 800; color: var(--primary); }
@media (min-width: 1024px) { .menu-toggle { display: none; } }
.page-heading { display: none; }
@media (min-width: 768px) { .page-heading { display: block; } .sitename-mob { display: none; } .page-heading h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); } .page-heading p { font-size: 0.875rem; color: var(--gray-500); } }
.nav-right { display: flex; align-items: center; gap: 0.75rem; }
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; transition: all 0.2s; }
.icon-btn:hover { background: var(--gray-100); }

.content-wrapper { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.625rem 1.25rem; border-radius: var(--radius); font-size: 0.9375rem; font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; white-space: nowrap; font-family: inherit; }
.btn-sm { padding: 0.4rem 0.875rem; font-size: 0.8125rem; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(124,58,237,0.25); }
.btn-secondary { background: var(--gray-100); color: var(--gray-700); }
.btn-secondary:hover { background: var(--gray-200); }
.btn-warning { background: var(--warning-light); color: #92400e; }
.btn-warning:hover { background: #fde68a; }
.btn:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-outline { background: white; border: 1px solid var(--primary); color: var(--primary); }
.btn-outline:hover { background: var(--primary-light); }
.btn-outline.is-disabled { opacity: .5; cursor: not-allowed; pointer-events: none; border-color: var(--gray-300); color: var(--gray-400); }

.error-message { background: var(--danger-light); color: #991b1b; border: 1px solid #fecaca; padding: 1rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1.25rem; font-size: 0.9375rem; }

/* --- Header inline toast (replaces floating popup for success/error) --- */
.header-toast {
    box-sizing: border-box; display: flex; align-items: center; gap: 0.5rem;
    background: white; border: 1px solid var(--gray-200); border-left: 3px solid var(--success);
    box-shadow: var(--shadow-sm); border-radius: var(--radius);
    padding: 0.5rem 0.875rem; font-size: 0.8125rem; line-height: 1.3;
    color: var(--gray-800); max-width: 340px;
    animation: toastIn .2s ease;
}
.header-toast.toast-error { border-left-color: var(--danger); }
.header-toast .toast-icon { flex: 0 0 auto; font-size: 0.9375rem; }
.header-toast.toast-success .toast-icon { color: var(--success); }
.header-toast.toast-error .toast-icon { color: var(--danger); }
.header-toast .toast-text { flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.header-toast .toast-close { flex: 0 0 auto; background: none; border: none; color: var(--gray-400); cursor: pointer; font-size: 1rem; line-height: 1; padding: 0; transition: color .15s; }
.header-toast .toast-close:hover { color: var(--gray-700); }
.header-toast.toast-hide { animation: toastOut .2s ease forwards; }
@keyframes toastIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
@keyframes toastOut { from { opacity: 1; transform: translateY(0); } to { opacity: 0; transform: translateY(-4px); } }

/* --- Editor page grid --- */
.editor-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; align-items: start; }
@media (min-width: 1024px) { .editor-grid { grid-template-columns: minmax(0, 1fr) 320px; } }

.editor-col-main, .editor-col-side { display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
.editor-col-side { position: relative; }
@media (min-width: 1024px) { .editor-col-side { position: sticky; top: calc(0.725rem + 60px + 1.5rem); } }

.card { background: white; border: 1px solid var(--gray-100); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); }
.card-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.card-header h2 { font-size: 0.9375rem; font-weight: 700; color: var(--gray-900); }
.card-header p { font-size: 0.75rem; color: var(--gray-500); margin-top: 0.125rem; }
.card-body { padding: 1.25rem; }

/* --- WordPress-style title + permalink block --- */
.title-block { padding: 1rem 1.25rem 1.25rem; }
.post-title-input {
    width: 100%; padding: 0.5rem 0.125rem;
    font-size: 1.5rem; font-weight: 700;
    border: none; border-bottom: 2px solid transparent; border-radius: 0;
    outline: none; font-family: inherit; color: var(--gray-900);
    background: transparent; line-height: 1.4;
    transition: border-color 0.15s;
}
.post-title-input:focus { border-bottom-color: var(--primary); }
.post-title-input::placeholder { color: var(--gray-400); font-weight: 700; }
.title-block .editor-char-counter { text-align: left; margin-top: 0.2rem; }

.permalink-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; color: var(--gray-500); flex-wrap: wrap; margin-top: 0.5rem; }
.permalink-row strong { color: var(--gray-700); font-weight: 600; }
.permalink-input {
    border: 1px solid var(--gray-200); font-size: 0.8125rem;
    padding: 0.3rem 0.6rem; font-family: inherit;
    background: white; min-width: 200px; flex: 1; max-width: 380px;
    border-radius: var(--radius); color: var(--gray-800); outline: none;
    transition: border-color 0.15s;
}
.permalink-input:focus { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-light); }

.chapter-badge-row { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.65rem; }
.badge-grey {
    background: var(--gray-100); color: var(--gray-600);
    padding: 0.25rem 0.625rem; font-size: 0.75rem; font-weight: 600;
    border-radius: 9999px; border: 1px solid var(--gray-200);
    display: inline-flex; align-items: center; gap: 0.375rem;
}
.badge-blue {
    background: var(--info-light); color: #1e4ed8;
    padding: 0.25rem 0.625rem; font-size: 0.75rem; font-weight: 600;
    border-radius: 9999px; border: 1px solid #bfdbfe;
    display: inline-flex; align-items: center; gap: 0.375rem;
}

/* --- Field Groups --- */
.editor-field-group { margin-bottom: 1.125rem; }
.editor-field-group:last-child { margin-bottom: 0; }
.required { color: var(--danger); font-weight: bold; margin-left: 4px; }
.lb-cmt { font-size: 0.8rem; margin-left: 4px; color: var(--gray-400); font-weight: 400; }

form label { font-size: 0.8125rem; font-weight: 600; color: var(--gray-700); letter-spacing: 0.2px; display: block; margin-bottom: 0.4rem; }

form input[type="text"],
form input[type="email"],
form input[type="date"],
form input[type="datetime-local"] {
    width: 100%; padding: 0.65rem 0.875rem; border: 1.5px solid var(--gray-200); border-radius: var(--radius); font-size: 0.9375rem; font-family: inherit; color: var(--gray-800); background: white; transition: all 0.2s ease; box-sizing: border-box;
}
form input[type="text"]:focus, form input[type="email"]:focus, form input[type="date"]:focus, form input[type="datetime-local"]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
form input[type="text"]:hover:not(:focus), form input[type="email"]:hover:not(:focus) { border-color: var(--gray-300); }
form input::placeholder { color: var(--gray-400); font-weight: 400; }

form textarea {
    width: 100%; padding: 0.65rem 0.875rem; border: 1.5px solid var(--gray-200); border-radius: var(--radius); font-size: 0.9375rem; font-family: inherit; color: var(--gray-800); background: white; resize: vertical; min-height: 90px; transition: all 0.2s ease; line-height: 1.6; box-sizing: border-box;
}
form textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
form textarea::placeholder { color: var(--gray-400); }

form select {
    width: 100%; padding: 0.65rem 0.875rem; border: 1.5px solid var(--gray-200); border-radius: var(--radius); font-size: 0.9375rem; font-family: inherit; color: var(--gray-800);
    background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 9L1 4h10z'/%3E%3C/svg%3E") no-repeat right 0.875rem center;
    background-size: 11px; appearance: none; cursor: pointer; transition: all 0.2s ease; box-sizing: border-box;
}
form select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
form select:hover:not(:focus) { border-color: var(--gray-300); }

.choices__inner { border-radius: var(--radius) !important; padding: 0.4rem 0.6rem !important; background: white; border: 1.5px solid var(--gray-200) !important; }
.choices__list--dropdown, .choices.is-open .choices__inner { border-color: var(--primary) !important; }
.ts-wrapper .ts-control { border-radius: var(--radius) !important; border: 1.5px solid var(--gray-200) !important; padding: 0.4rem 0.6rem !important; }
.ts-wrapper.focus .ts-control { border-color: var(--primary) !important; box-shadow: 0 0 0 3px var(--primary-light); }

form input[type="file"] {
    width: 100%; padding: 0.75rem 0.875rem; border: 1.5px dashed var(--gray-300); border-radius: var(--radius); font-size: 0.875rem; cursor: pointer; background: var(--gray-50); color: var(--gray-600); transition: all 0.2s ease; box-sizing: border-box;
}
form input[type="file"]:hover { border-color: var(--primary); background: var(--primary-lighter); }
form input[type="file"]:focus { outline: none; border-color: var(--primary); }

/* --- TinyMCE Editor --- */
.editor-container { border: 1.5px solid var(--gray-200); border-radius: var(--radius); overflow: hidden; position: relative; }
.tox-tinymce { border: none !important; border-radius: var(--radius) !important; }
.tox .tox-toolbar__primary { background: var(--gray-50) !important; }

#editor-tabs { position: absolute; top: 8px; right: 8px; z-index: 30; display: flex; gap: 3px; }
.editor-tab-btn {
    font-size: 0.75rem; font-weight: 600; padding: 4px 10px;
    border: 1px solid var(--gray-200); background: white; color: var(--gray-500);
    cursor: pointer; font-family: inherit; border-radius: 6px;
    box-shadow: 0 1px 2px rgba(0,0,0,.06); transition: all 0.12s;
}
.editor-tab-btn.active { background: var(--primary); border-color: var(--primary); color: white; }
.editor-tab-btn:not(.active):hover { background: var(--gray-100); color: var(--gray-800); border-color: var(--gray-300); }

#content-html-editor {
    width: 100%; min-height: 400px;
    font-family: Consolas, Monaco, 'Courier New', monospace;
    font-size: 13px; padding: 12px 14px;
    border: none; resize: vertical; outline: none; box-sizing: border-box;
    color: var(--gray-800); background: white; line-height: 1.7; display: block;
}
#html-quicktags { background: var(--gray-50); border-bottom: 1px solid var(--gray-200); padding: 6px 8px; display: flex; flex-wrap: wrap; gap: 3px; }
.qt-btn {
    font-size: 12px; padding: 3px 8px; border: 1px solid var(--primary);
    background: var(--gray-50); color: var(--primary); cursor: pointer;
    border-radius: var(--radius); line-height: 1.4; font-family: inherit; transition: all 0.1s;
}
.qt-btn:hover { background: var(--primary); color: white; }
#word-count-row { background: var(--gray-50); border-top: 1px solid var(--gray-200); padding: 6px 12px; font-size: 0.75rem; color: var(--gray-400); }

/* --- Table Template Section --- */
.editor-table-section { margin-top: 1rem; }
.editor-table-insert-btn { padding: 0.65rem 1.25rem; background: var(--gray-800); color: white; border-radius: var(--radius); border: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.5rem; }
.editor-table-insert-btn:hover { background: var(--gray-900); }

/* --- Modals (shared wp-style overlay) --- */
.wp-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 10000; overflow-y: auto; padding: 20px; justify-content: center; align-items: flex-start; }
.wp-modal-overlay.open { display: flex; }
.wp-modal { background: white; border-radius: var(--radius-lg); width: 100%; max-width: 980px; margin: 30px auto; box-shadow: var(--shadow-lg); max-height: calc(100vh - 80px); display: flex; flex-direction: column; }
.wp-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 0.875rem 1.25rem; border-bottom: 1px solid var(--gray-200); background: var(--gray-50); flex-shrink: 0; border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
.wp-modal-head h2 { margin: 0; font-size: 1.0625rem; font-weight: 700; font-family: inherit; color: var(--gray-900); }
.wp-modal-close { background: var(--gray-100); border: none; font-size: 1rem; color: var(--gray-500); cursor: pointer; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all 0.2s ease; }
.wp-modal-close:hover { background: var(--gray-200); color: var(--gray-800); }
.wp-modal-body { padding: 1.25rem; flex: 1; min-height: 0; overflow-y: auto; }
.wp-modal-foot { padding: 0.875rem 1.25rem; border-top: 1px solid var(--gray-100); background: var(--gray-50); text-align: right; border-radius: 0 0 var(--radius-lg) var(--radius-lg); flex-shrink: 0; }

/* --- Table Template modal contents --- */
.tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.875rem; }
.tpl-card { border: 1px solid var(--gray-200); background: white; cursor: pointer; transition: border-color .15s; border-radius: var(--radius); overflow: hidden; }
.tpl-card:hover { border-color: var(--primary); box-shadow: var(--shadow-md); }
.tpl-preview { padding: 10px; background: var(--gray-50); border-bottom: 1px solid var(--gray-100); min-height: 90px; overflow: hidden; display: flex; align-items: center; justify-content: center; }
.tpl-preview table { width: 100%; border-collapse: collapse; }
.tpl-preview th { background: var(--primary); color: white; padding: 4px 6px; text-align: left; font-size: 10px; }
.tpl-preview td { border: 1px solid var(--gray-200); padding: 4px 6px; font-size: 10px; }
.tpl-info { padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; }
.tpl-info h4 { margin: 0; font-size: 0.8125rem; font-family: inherit; color: var(--gray-800); }
.tpl-insert-btn { background: var(--primary); color: white; border: none; padding: 4px 12px; font-size: 0.75rem; cursor: pointer; font-family: inherit; border-radius: var(--radius); transition: background 0.15s; }
.tpl-insert-btn:hover { background: var(--primary-dark); }
.no-templates { text-align: center; padding: 3rem; color: var(--gray-400); grid-column: 1 / -1; }

/* --- FAQ modal contents --- */
.faq-row { margin-bottom: 0.875rem; background: white; border: 1px solid var(--gray-200); padding: 0.875rem; border-radius: var(--radius); box-shadow: 0 1px 2px rgba(0,0,0,.03); }
.faq-row-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.65rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--gray-100); }
.faq-row-num { font-size: 0.75rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: .03em; }
.faq-row label { font-size: 0.75rem; font-weight: 500; color: var(--gray-700); display: block; margin-bottom: 0.3rem; }
.faq-row .faq-rm { display: flex; align-items: center; gap: 4px; background: none; border: none; color: var(--gray-400); cursor: pointer; font-size: 0.75rem; font-weight: 600; font-family: inherit; padding: 3px 7px; border-radius: var(--radius); transition: background .15s, color .15s; }
.faq-row .faq-rm:hover { background: var(--danger-light); color: var(--danger); }
.faq-row input.faq-q, .faq-row textarea.faq-a { width: 100%; box-sizing: border-box; padding: 0.5rem 0.75rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: 0.875rem; font-family: inherit; color: var(--gray-800); background: white; outline: none; transition: border-color .15s; }
.faq-row input.faq-q:focus, .faq-row textarea.faq-a:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
.faq-row textarea.faq-a { min-height: 70px; resize: vertical; }
#add-faq-row { width: 100%; background: var(--gray-50); border: 1px dashed var(--gray-300); padding: 10px; font-size: 0.8125rem; cursor: pointer; font-family: inherit; color: var(--gray-700); border-radius: var(--radius); }
#add-faq-row:hover { background: white; border-color: var(--primary); color: var(--primary); }
#faq-preview-list { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.5rem; }
.faq-prev-item { font-size: 0.75rem; padding: 5px 0; border-top: 1px solid var(--gray-100); color: var(--gray-500); }
.faq-prev-item strong { display: block; color: var(--gray-700); font-size: 0.8125rem; margin-bottom: 1px; }
.faq-prev-empty { text-align: center; padding: 1rem; color: var(--gray-400); border: 2px dashed var(--gray-200); border-radius: var(--radius); font-size: 0.8125rem; }

/* --- Cropper Modal --- */
#cropperModal h3 { color: var(--gray-900); font-size: 1.1rem; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--gray-100); font-weight: 700; }

/* --- Link Button Modal --- */
.lbb-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10001; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
.lbb-modal-box { background: white; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); width: 480px; max-width: 95%; padding: 1.25rem; font-family: inherit; }
.lbb-modal-title { margin: 0 0 0.9rem; font-size: 1.0625rem; font-weight: 700; color: var(--gray-900); border-bottom: 1px solid var(--gray-100); padding-bottom: 0.6rem; }
.lbb-grid { display: grid; gap: 0.75rem; }
.lbb-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.lbb-label { display: block; margin-bottom: 0.25rem; font-weight: 600; color: var(--gray-700); font-size: 0.8125rem; }
.lbb-input, .lbb-select { width: 100%; padding: 0.5rem 0.7rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: 0.875rem; box-sizing: border-box; transition: border-color 0.2s; }
.lbb-input:focus, .lbb-select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-light); }
.lbb-color-wrap { display: flex; align-items: center; gap: 0.5rem; }
.lbb-color-picker { width: 38px; height: 30px; padding: 0; border: 1px solid var(--gray-200); border-radius: 4px; cursor: pointer; background: none; }
.lbb-color-val { font-family: monospace; font-size: 0.75rem; color: var(--gray-500); }
.lbb-preview-box { padding: 0.9rem; background: var(--gray-50); border-radius: var(--radius); border: 1px solid var(--gray-100); margin-top: 0.25rem; }
.lbb-preview-label { font-weight: 600; font-size: 0.75rem; color: var(--gray-600); margin-bottom: 0.5rem; }
.lbb-preview-area { display: flex; width: 100%; min-height: 46px; text-align: center; align-items: center; }
.lbb-footer { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1.1rem; }
.lbb-btn { padding: 0.5rem 1.1rem; border-radius: var(--radius); font-size: 0.8125rem; font-weight: 600; cursor: pointer; border: 1px solid var(--gray-200); background: white; color: var(--gray-700); font-family: inherit; }
.lbb-btn-cancel:hover { background: var(--gray-50); }
.lbb-btn-save { background: var(--primary); color: white; border-color: var(--primary); }
.lbb-btn-save:hover { background: var(--primary-dark); }

/* ============================================================
   WordPress-style collapsible sidebar panels ("meta-panel")
   ============================================================ */
.meta-panel { background: white; border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }
.meta-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.875rem 1.125rem; border-bottom: 1px solid var(--gray-100);
    font-size: 0.9375rem; font-weight: 700; color: var(--gray-900);
    cursor: pointer; user-select: none;
}
.meta-panel-header .toggle-icon { color: var(--gray-400); flex-shrink: 0; transition: transform 0.15s; width: 16px; height: 16px; }
.meta-panel.closed .meta-panel-header { border-bottom-color: transparent; }
.meta-panel.closed .meta-panel-header .toggle-icon { transform: rotate(-90deg); }
.meta-panel.closed .meta-panel-body { display: none; }
.meta-panel-body { padding: 1.125rem; display: flex; flex-direction: column; gap: 0.9rem; }
.meta-panel-body:empty { display: none; }
.req-star { color: var(--danger); }
.field-hint { font-size: 0.75rem; color: var(--gray-400); font-style: italic; margin-top: 0.125rem; display: block; }

/* --- Publish panel (WordPress-style inline-edit rows) --- */
.wp-pub-btn-row { display: flex; gap: 0.5rem; }
.wp-pub-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; color: var(--gray-700); line-height: 1.3; }
.wp-pub-row .wp-pub-icon { width: 17px; height: 17px; color: var(--gray-500); flex-shrink: 0; }
.wp-pub-row strong { color: var(--gray-900); font-weight: 700; }
.wp-pub-editlink { margin-left: auto; color: var(--primary); font-size: 0.8125rem; font-weight: 600; cursor: pointer; text-decoration: underline; flex-shrink: 0; }
.wp-pub-editbox { display: none; flex-direction: column; gap: 0.5rem; background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius); padding: 0.65rem; }
.wp-pub-editactions { display: flex; gap: 0.5rem; justify-content: flex-end; }
.wp-pub-ok { background: var(--primary); color: white; border: none; padding: 0.35rem 0.85rem; border-radius: var(--radius); font-size: 0.75rem; font-weight: 600; cursor: pointer; font-family: inherit; }
.wp-pub-ok:hover { background: var(--primary-dark); }
.wp-pub-cancel { background: none; border: none; color: var(--gray-500); font-size: 0.75rem; cursor: pointer; font-family: inherit; }
.wp-pub-cancel:hover { color: var(--gray-700); }

/* --- Featured image panel --- */
.feat-img-preview { width: 100%; height: auto; display: block; border-radius: var(--radius); margin-bottom: 0.625rem; object-fit: cover; border: 1px solid var(--gray-200); cursor: pointer; transition: border-color 0.15s, opacity 0.15s; }
.feat-img-preview:hover { border-color: var(--primary); opacity: .92; }
.feat-img-placeholder { width: 100%; aspect-ratio: 16/9; background: var(--gray-50); border: 2px dashed var(--gray-300); border-radius: var(--radius); display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--gray-400); gap: 0.5rem; margin-bottom: 0.625rem; cursor: pointer; transition: border-color 0.15s; }
.feat-img-placeholder:hover { border-color: var(--primary); color: var(--primary); }
.feat-img-placeholder svg { width: 30px; height: 30px; opacity: .55; }
.feat-img-placeholder span { font-size: 0.75rem; }
.feat-img-actions { display: flex; flex-direction: column; gap: 0.4rem; }
.upload-label { display: block; background: var(--gray-100); color: var(--gray-700); text-align: center; padding: 0.5rem 0.75rem; font-size: 0.8125rem; cursor: pointer; border: 1px solid var(--gray-200); border-radius: var(--radius); font-weight: 600; margin: 0; font-family: inherit; transition: background 0.15s, border-color 0.15s; }
.upload-label:hover { background: var(--gray-200); }
.feat-img-actions input[type=file] { display: none; }
.existing-banner-preview { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 8px; }
.existing-banner-preview .banner-thumb { position: relative; border: 1px solid var(--gray-200); border-radius: var(--radius); overflow: hidden; background: var(--gray-50); }
.existing-banner-preview .banner-thumb img { width: 100%; height: auto; display: block; object-fit: cover; }
.existing-banner-preview .banner-thumb .banner-label { position: absolute; top: 6px; left: 6px; background: rgba(0,0,0,0.65); color: white; padding: 2px 7px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }

/* --- Copy link pills --- */
.post-url-row { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
.copy-link-btn {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.25rem 0.625rem; font-size: 0.75rem; font-weight: 600; line-height: 1;
    white-space: nowrap; color: var(--gray-500); background: white;
    border: 1px solid var(--gray-200); border-radius: 9999px; cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
}
.copy-link-btn:hover { color: var(--primary); border-color: var(--primary); }
.copy-link-btn .cp-icon { flex-shrink: 0; }
.copy-link-btn .cp-icon-copied { display: none; }
.copy-link-btn.is-copied { color: var(--success); border-color: var(--success); background: var(--success-light); }
.copy-link-btn.is-copied .cp-icon-default { display: none; }
.copy-link-btn.is-copied .cp-icon-copied { display: inline-flex; }
.copy-link-btn.is-disabled { opacity: .5; pointer-events: none; cursor: not-allowed; color: var(--gray-400); background: var(--gray-50); }

/* --- Char Counter & Help Text --- */
.editor-char-counter { font-size: 0.75rem; color: var(--gray-400); margin-top: 0.3rem; text-align: right; }
.editor-char-counter.warning { color: var(--warning); }
.editor-char-counter.danger { color: var(--danger); }
.editor-help-text { font-size: 0.75rem; color: var(--gray-500); margin-top: 0.3rem; }

/* --- Preview --- */
.preview { margin-top: 1rem; border-top: 1px dashed var(--gray-200); padding-top: 1rem; }
.preview:empty { display: none; }

/* ============================================================
   Media Library modal
   ============================================================ */
.mlb-overlay { position: fixed; inset: 0; background: rgba(17,24,39,.55); z-index: 10002; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.mlb-box { background: white; border-radius: var(--radius-lg); width: 100%; max-width: 900px; height: 80vh; max-height: 640px; display: flex; flex-direction: column; box-shadow: var(--shadow-lg); overflow: hidden; }
.mlb-head { padding: .9rem 1.25rem; border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.mlb-head h3 { margin: 0; font-size: 1rem; font-weight: 700; color: var(--gray-900); display: flex; align-items: center; gap: .5rem; }
.mlb-head h3 i { color: var(--primary); }
.mlb-x { background: none; border: none; font-size: 1.4rem; line-height: 1; cursor: pointer; color: var(--gray-500); padding: .15rem .5rem; border-radius: 6px; transition: background .15s, color .15s; }
.mlb-x:hover { background: var(--danger-light); color: var(--danger); }
.mlb-main { flex: 1; display: flex; overflow: hidden; min-height: 0; }
.mlb-col-grid { flex: 1; display: flex; flex-direction: column; min-width: 0; border-right: 1px solid var(--gray-200); }
.mlb-col-detail { width: 260px; flex-shrink: 0; overflow-y: auto; background: var(--gray-50); }
@media (max-width: 720px) { .mlb-main { flex-direction: column; } .mlb-col-detail { width: 100%; border-top: 1px solid var(--gray-200); } }
.mlb-toolbar { display: flex; gap: .6rem; padding: .85rem 1rem; flex-shrink: 0; flex-wrap: wrap; }
.mlb-search { flex: 1; display: flex; align-items: center; gap: .5rem; background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius); padding: .5rem .75rem; min-width: 160px; }
.mlb-search i { color: var(--gray-400); font-size: .8rem; }
.mlb-search input { border: none; outline: none; background: transparent; font-size: .85rem; width: 100%; color: var(--gray-800); }
.mlb-upload-btn { display: flex; align-items: center; gap: .4rem; background: var(--primary); color: white; padding: .5rem 1rem; border-radius: var(--radius); font-size: .8125rem; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background .15s; }
.mlb-upload-btn:hover { background: var(--primary-dark); }
.mlb-upload-progress { padding: 0 1rem .75rem; flex-shrink: 0; }
.mlb-upload-bar { height: 6px; background: var(--gray-200); border-radius: 3px; overflow: hidden; }
.mlb-upload-bar > div { height: 100%; width: 0%; background: var(--primary); transition: width .2s; }
.mlb-grid { flex: 1; overflow-y: auto; padding: 0 1rem 1rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); row-gap: .6rem; column-gap: .6rem; align-content: start; }
.mlb-item { aspect-ratio: 1; border-radius: var(--radius); overflow: hidden; cursor: pointer; position: relative; background: var(--gray-100); border: 2px solid transparent; transition: border-color .15s; }
.mlb-item:hover { border-color: var(--primary-light); }
.mlb-item.selected { border-color: var(--primary); }
.mlb-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mlb-item .mlb-item-check { position: absolute; top: 4px; right: 4px; width: 20px; height: 20px; border-radius: 50%; background: var(--primary); color: white; display: none; align-items: center; justify-content: center; font-size: 11px; }
.mlb-item.selected .mlb-item-check { display: flex; }
.mlb-empty { padding: 2.5rem 1rem; text-align: center; color: var(--gray-400); font-size: 0.875rem; }
.mlb-pager { display: flex; justify-content: center; gap: .4rem; padding: .75rem; flex-shrink: 0; }
.mlb-pager button { border: 1px solid var(--gray-200); background: white; color: var(--gray-700); padding: .3rem .7rem; border-radius: var(--radius); font-size: .8rem; cursor: pointer; }
.mlb-pager button.active { background: var(--primary); color: white; border-color: var(--primary); }
.mlb-pager button:disabled { opacity: .4; cursor: not-allowed; }
.mlb-no-sel { padding: 1.5rem 1rem; text-align: center; color: var(--gray-400); font-size: 0.8125rem; }
.mlb-detail { padding: 1rem; }
.mlb-detail img { width: 100%; border-radius: var(--radius); border: 1px solid var(--gray-200); margin-bottom: .75rem; }
.mlb-field { margin-bottom: .75rem; }
.mlb-field label { display: block; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--gray-500); margin-bottom: .25rem; }
.mlb-field .mlb-field-val { font-size: .8125rem; color: var(--gray-700); word-break: break-all; }
.mlb-field input { width: 100%; padding: .4rem .6rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: .8125rem; font-family: inherit; }
.mlb-url-row { display: flex; gap: .35rem; }
.mlb-url-row input { flex: 1; min-width: 0; }
.mlb-save-status { font-size: .7rem; color: var(--success); }
.mlb-detail-actions { display: flex; gap: .5rem; margin-top: .5rem; }
.mlb-btn-download { flex: 1; text-align: center; padding: .4rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: .75rem; color: var(--gray-700); background: white; }
.mlb-btn-del { padding: .4rem .6rem; border: 1px solid var(--danger-light); background: var(--danger-light); color: var(--danger); border-radius: var(--radius); cursor: pointer; }
.mlb-foot { padding: .75rem 1rem; border-top: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; flex-wrap: wrap; gap: .5rem; }
.mlb-btn-cancel { padding: .5rem 1rem; border: 1px solid var(--gray-200); background: white; color: var(--gray-700); border-radius: var(--radius); cursor: pointer; font-size: .8125rem; }
.mlb-btn-use { padding: .5rem 1.25rem; border: none; background: var(--primary); color: white; border-radius: var(--radius); cursor: pointer; font-size: .8125rem; font-weight: 600; }
.mlb-btn-use:disabled { opacity: .5; cursor: not-allowed; }

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUp { from { transform: translateY(40px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 768px) {
    form button[type="submit"], #preview-btn { width: 100%; }
    .tpl-grid { grid-template-columns: 1fr; }
    .wp-modal { margin: 10px auto; max-height: calc(100vh - 40px); }
    .wp-modal-head { padding: 1rem 1.25rem; }
    .wp-modal-body { padding: 1rem; }
    .post-title-input { font-size: 1.2rem; }
    .permalink-row { flex-direction: column; align-items: flex-start; }
    .permalink-input { min-width: 0; width: 100%; max-width: 100%; }
    .top-nav .header-toast { width: 100%; max-width: none; }
}
</style>
</head>
<body>

<div class="admin-container">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sitename-mob"><?= htmlspecialchars($site_name) ?></span>
                <div class="page-heading">
                    <h1><?= $is_edit ? 'Edit Post' : 'Create New Post' ?></h1>
                    <p><?= $is_edit ? 'Update an existing blog post' : 'Write and publish a new blog post' ?></p>
                </div>
            </div>

            <div class="nav-right" id="header-toast-slot">
                <button type="button" class="btn btn-secondary btn-sm" onclick="goBackHome()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

        <?php if (!empty($form_error)): ?>
            <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?= $form_error ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="post-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <?php if ($is_edit): ?>
                <input type="hidden" name="post_id" value="<?= $post_id ?>">
            <?php endif; ?>
            <input type="hidden" name="existing_featured_image_id" id="existing_featured_image_id" value="<?= $post_data['featured_image_id'] ?? '' ?>">
            <input type="hidden" name="faq_json" id="faq_json_input" value="<?= $is_edit ? htmlspecialchars($post_data['faq_json'] ?? '[]') : '[]' ?>">

            <div class="editor-grid">

                <!-- ============================================================ -->
                <!-- MAIN COLUMN                                                   -->
                <!-- ============================================================ -->
                <div class="editor-col-main">

                    <!-- Title + Permalink -->
                    <div class="card">
                        <div class="title-block">
                            <input
                                type="text" name="title" id="post-title" class="post-title-input"
                                placeholder="Add title"
                                value="<?= $is_edit ? htmlspecialchars($post_data['title']) : '' ?>"
                                required maxlength="200" autocomplete="off"
                            >
                            <div class="editor-char-counter" id="title-counter">0 / 200</div>

                            <div class="permalink-row">
                                <strong>Permalink:</strong>
                                <span><?= rtrim($site_url, '/') ?>/</span>
                                <input type="text" name="slug" id="post-slug" class="permalink-input"
                                       value="<?= $is_edit ? htmlspecialchars($post_data['slug']) : '' ?>"
                                       placeholder="auto-generated-from-title" autocomplete="off">
                                <div class="post-url-row" id="post-url-row" <?= $is_edit ? '' : 'style="display:none;"' ?>>
                                    <button type="button" class="copy-link-btn" id="copy-link-btn" onclick="copyPostLink()">
                                        <svg class="cp-icon cp-icon-default" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        <svg class="cp-icon cp-icon-copied" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                        <span class="cp-label">Copy link</span>
                                    </button>
                                    <button type="button" class="copy-link-btn" id="copy-fb-comment-btn" onclick="copyFbComment()" title="Copy a ready-made Facebook comment with this post's link">
                                        <i class="fab fa-facebook" style="font-size:11px;"></i>
                                        <span class="cp-label">Copy FB comment</span>
                                    </button>
                                </div>
                            </div>

                            <div class="chapter-badge-row" id="chapter-badge-row" style="display:none;">
                                <span class="badge-blue" id="chapter-badge">
                                    <i class="fas fa-layer-group"></i> <span id="chapter-count-text">1 chapter</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Content Editor -->
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2>Content <span class="required">*</span></h2>
                                <p>Write the full post body</p>
                            </div>
                            <div id="editor-tabs">
                                <button type="button" class="editor-tab-btn active" data-tab="visual" onclick="switchEditorTab('visual')">Visual</button>
                                <button type="button" class="editor-tab-btn" data-tab="text" onclick="switchEditorTab('text')">Text</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="editor-container">
                                <textarea name="content" id="content"><?= $is_edit ? $post_data['content'] : '' ?></textarea>

                                <div id="html-editor-wrap" style="display:none;">
                                    <div id="html-quicktags"></div>
                                    <textarea id="content-html-editor" spellcheck="false"></textarea>
                                    <div id="word-count-row"><span id="word-count-text">0 words</span></div>
                                </div>
                            </div>

                            <div class="editor-table-section">
                                <button type="button" class="editor-table-insert-btn" onclick="openTemplateModal()">
                                    <i class="fas fa-table"></i> Insert Table Template
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ Section -->
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2>FAQ Section</h2>
                                <p>Optional Q&amp;A block shown on the post</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openFaqModal()">
                                <i class="fas fa-pen"></i> Manage FAQs
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="faq-summary-preview">
                                <div class="faq-prev-empty">No FAQs added yet. Click "Manage FAQs" to add some.</div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- END MAIN COLUMN -->
                <!-- ============================================================ -->
                <!-- SIDEBAR COLUMN                                                -->
                <!-- ============================================================ -->
                <div class="editor-col-side">

                    <!-- ===== Publish panel ===== -->
                    <div class="meta-panel" id="panel-publish">
                        <div class="meta-panel-header" onclick="togglePanel('panel-publish')">
                            <span><i class="fas fa-paper-plane" style="color:var(--primary); margin-right:6px;"></i>Publish</span>
                            <svg class="toggle-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </div>
                        <div class="meta-panel-body">

                            <!-- Status (WP inline-edit) -->
                            <div class="wp-pub-row">
                                <svg class="wp-pub-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                                Status: <strong id="status-display-text"><?= $is_edit ? ucfirst($post_data['status']) : 'Draft' ?></strong>
                                <a class="wp-pub-editlink" onclick="toggleEditBox('status')">Edit</a>
                            </div>
                            <div class="wp-pub-editbox" id="status-editbox">
                                <select id="status-select" name="status" onchange="onStatusChange()">
                                    <option value="draft" <?= (!$is_edit || $post_data['status'] === 'draft') ? 'selected' : '' ?>>Draft</option>
                                    <option value="published" <?= ($is_edit && $post_data['status'] === 'published') ? 'selected' : '' ?>>Published</option>
                                    <option value="scheduled" <?= ($is_edit && $post_data['status'] === 'scheduled') ? 'selected' : '' ?>>Scheduled</option>
                                    <option value="archived" <?= ($is_edit && $post_data['status'] === 'archived') ? 'selected' : '' ?>>Archived</option>
                                </select>
                                <div id="schedule-field-wrap" style="display:<?= ($is_edit && $post_data['status'] === 'scheduled') ? 'block' : 'none' ?>;">
                                    <label style="margin-top:.4rem;">Publish on</label>
                                    <input type="datetime-local" name="publish_at" id="publish_at"
                                           value="<?= ($is_edit && !empty($post_data['publish_at'])) ? date('Y-m-d\TH:i', strtotime($post_data['publish_at'])) : '' ?>">
                                </div>
                                <div class="wp-pub-editactions">
                                    <a class="wp-pub-cancel" onclick="toggleEditBox('status')">Cancel</a>
                                    <button type="button" class="wp-pub-ok" onclick="confirmStatus()">OK</button>
                                </div>
                            </div>

                            <!-- Last updated date (WP inline-edit) -->
                            <div class="wp-pub-row">
                                <svg class="wp-pub-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
                                Last date: <strong id="lastdate-display-text"><?= ($is_edit && !empty($post_data['last_date'])) ? date('M j, Y', strtotime($post_data['last_date'])) : 'Not set' ?></strong>
                                <a class="wp-pub-editlink" onclick="toggleEditBox('lastdate')">Edit</a>
                            </div>
                            <div class="wp-pub-editbox" id="lastdate-editbox">
                                <input type="date" name="last_date" id="last_date"
                                       value="<?= ($is_edit && !empty($post_data['last_date'])) ? date('Y-m-d', strtotime($post_data['last_date'])) : '' ?>">
                                <div class="wp-pub-editactions">
                                    <a class="wp-pub-cancel" onclick="toggleEditBox('lastdate')">Cancel</a>
                                    <button type="button" class="wp-pub-ok" onclick="confirmLastDate()">OK</button>
                                </div>
                            </div>

                            <!-- Author (WP inline-edit) -->
                            <div class="wp-pub-row">
                                <svg class="wp-pub-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                                Author: <strong id="author-display-text">
                                    <?php
                                    $authorLabel = 'Select author';
                                    if ($is_edit) {
                                        foreach ($authors as $a) {
                                            if ($a['id'] == $post_data['author_id']) { $authorLabel = $a['name']; break; }
                                        }
                                    }
                                    echo htmlspecialchars($authorLabel);
                                    ?>
                                </strong>
                                <a class="wp-pub-editlink" onclick="toggleEditBox('author')">Edit</a>
                            </div>
                            <div class="wp-pub-editbox" id="author-editbox">
                                <select name="author_id" id="author-select" required>
                                    <option value="">Select an author</option>
                                    <?php foreach ($authors as $a): ?>
                                        <option value="<?= $a['id'] ?>" <?= ($is_edit && $post_data['author_id'] == $a['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($a['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="wp-pub-editactions">
                                    <a class="wp-pub-cancel" onclick="toggleEditBox('author')">Cancel</a>
                                    <button type="button" class="wp-pub-ok" onclick="confirmAuthor()">OK</button>
                                </div>
                            </div>

                            <div class="wp-pub-btn-row" style="margin-top:.25rem;">
                                <button type="button" id="preview-btn" class="btn btn-secondary btn-sm" style="flex:1;" onclick="previewPost()">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                                <button type="submit" id="submit-btn" class="btn btn-primary btn-sm" style="flex:1;">
                                    <i class="fas fa-check"></i> <?= $is_edit ? 'Update' : 'Publish' ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ===== Featured Image panel ===== -->
                    <div class="meta-panel" id="panel-featured">
                        <div class="meta-panel-header" onclick="togglePanel('panel-featured')">
                            <span><i class="fas fa-image" style="color:var(--primary); margin-right:6px;"></i>Featured Image</span>
                            <svg class="toggle-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </div>
                        <div class="meta-panel-body">
                            <div id="featured-image-preview-wrap">
                                <?php if ($banner_data): ?>
                                    <img src="/<?= htmlspecialchars($banner_data['file_path']) ?>" class="feat-img-preview" id="featured-image-preview" onclick="openMediaLibrary()">
                                <?php else: ?>
                                    <div class="feat-img-placeholder" id="featured-image-placeholder" onclick="openMediaLibrary()">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                        <span>Set featured image</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div id="banner-preview-area"></div>
                            <div class="feat-img-actions">
                                <label for="banner" class="upload-label">
                                    <i class="fas fa-cloud-upload-alt"></i> Upload &amp; Crop <span class="lb-cmt">(4 responsive sizes)</span>
                                </label>
                                <input type="file" id="banner" name="banner[]" accept="image/*">
                                <label class="upload-label" onclick="openMediaLibrary(); return false;">
                                    <i class="fas fa-photo-video"></i> Choose from Media Library
                                </label>
                            </div>
                            <div class="editor-help-text">Upload &amp; Crop generates 4 sizes (xl/lg/md/sm) for this specific image. Media Library lets you reuse an already-uploaded image as-is.</div>
                        </div>
                    </div>

                    <!-- ===== Organize panel ===== -->
                    <div class="meta-panel" id="panel-organize">
                        <div class="meta-panel-header" onclick="togglePanel('panel-organize')">
                            <span><i class="fas fa-folder-open" style="color:var(--primary); margin-right:6px;"></i>Organize</span>
                            <svg class="toggle-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </div>
                        <div class="meta-panel-body">
                            <div class="editor-field-group">
                                <label for="category_id">Main Category <span class="required">*</span></label>
                                <select name="category_id" id="category_id" required>
                                    <option value="">Select category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= ($is_edit && $post_data['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="editor-field-group">
                                <label for="additional_category_ids">Additional Categories <span class="lb-cmt">(optional)</span></label>
                                <select name="additional_category_ids[]" id="additional_category_ids" multiple>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $additional_category_ids) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="editor-field-group">
                                <label for="state_id">State <span class="lb-cmt">(optional)</span></label>
                                <select name="state_id" id="state_id">
                                    <option value="">Select state</option>
                                    <?php foreach ($states as $st): ?>
                                        <option value="<?= $st['id'] ?>" <?= ($is_edit && $post_data['state_id'] == $st['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($st['state_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="editor-field-group">
                                <label for="tags_select">Tags</label>
                                <select name="tags[]" id="tags_select" multiple placeholder="Type to add tags...">
                                    <?php foreach ($all_tags as $tag): ?>
                                        <option value="<?= $tag['id'] ?>" <?= in_array($tag['id'], $selected_tag_ids) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tag['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="editor-help-text">Press Enter to create a new tag</div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== SEO panel ===== -->
                    <div class="meta-panel closed" id="panel-seo">
                        <div class="meta-panel-header" onclick="togglePanel('panel-seo')">
                            <span><i class="fas fa-search" style="color:var(--primary); margin-right:6px;"></i>SEO</span>
                            <svg class="toggle-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </div>
                        <div class="meta-panel-body">
                            <div class="editor-field-group">
                                <label for="meta_keywords">Meta Keywords</label>
                                <input type="text" name="meta_keywords" id="meta_keywords"
                                       value="<?= $is_edit ? htmlspecialchars($post_data['keywords'] ?? '') : '' ?>"
                                       placeholder="keyword1, keyword2, keyword3">
                            </div>
                            <div class="editor-field-group">
                                <label for="meta_description">Meta Description</label>
                                <textarea name="meta_description" id="meta_description" maxlength="160"
                                          placeholder="Short description for search engines..."><?= $is_edit ? htmlspecialchars($post_data['description'] ?? '') : '' ?></textarea>
                                <div class="editor-char-counter" id="meta-desc-counter">0 / 160</div>
                            </div>
                            <div class="editor-field-group">
                                <label for="fb_description">Facebook Description <span class="lb-cmt">(optional)</span></label>
                                <textarea name="fb_description" id="fb_description"
                                          placeholder="Custom description shown when shared on Facebook..."><?= $is_edit ? htmlspecialchars($post_data['fb_description'] ?? '') : '' ?></textarea>
                                <div class="editor-help-text">Leave blank to use Meta Description above.</div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- END SIDEBAR COLUMN -->

            </div>
            <!-- END editor-grid -->
        </form>

        </div>
    </main>
</div>
<!-- ============================================================ -->
<!-- MODAL: Table Template Picker                                 -->
<!-- ============================================================ -->
<div class="wp-modal-overlay" id="templateModal">
    <div class="wp-modal">
        <div class="wp-modal-head">
            <h2><i class="fas fa-table"></i> Insert Table Template</h2>
            <button type="button" class="wp-modal-close" onclick="closeTemplateModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="wp-modal-body">
            <div class="tpl-grid" id="tpl-grid">
                <?php if (empty($templates)): ?>
                    <div class="no-templates">
                        <i class="fas fa-table" style="font-size:2rem; opacity:.3; display:block; margin-bottom:.5rem;"></i>
                        No table templates available yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($templates as $tpl): ?>
                        <div class="tpl-card" onclick="insertTemplate(<?= (int)$tpl['id'] ?>)">
                            <div class="tpl-preview"><?= $tpl['preview_html'] ?? '<span style="font-size:11px;color:var(--gray-400);">No preview</span>' ?></div>
                            <div class="tpl-info">
                                <h4><?= htmlspecialchars($tpl['name']) ?></h4>
                                <button type="button" class="tpl-insert-btn">Insert</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Hidden store of raw template HTML for JS to read from -->
<script type="application/json" id="tpl-data">
<?= json_encode(array_map(function ($t) {
    return ['id' => (int)$t['id'], 'html' => $t['html_content'] ?? ''];
}, $templates)) ?>
</script>

<!-- ============================================================ -->
<!-- MODAL: FAQ Manager                                            -->
<!-- ============================================================ -->
<div class="wp-modal-overlay" id="faqModal">
    <div class="wp-modal">
        <div class="wp-modal-head">
            <h2><i class="fas fa-question-circle"></i> Manage FAQs</h2>
            <button type="button" class="wp-modal-close" onclick="closeFaqModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="wp-modal-body">
            <div id="faq-rows-container"></div>
            <button type="button" id="add-faq-row" onclick="addFaqRow()">
                <i class="fas fa-plus"></i> Add Question
            </button>
        </div>
        <div class="wp-modal-foot">
            <button type="button" class="btn btn-primary" onclick="saveFaqModal()">
                <i class="fas fa-check"></i> Save FAQs
            </button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: Image Cropper                                          -->
<!-- ============================================================ -->
<div class="wp-modal-overlay" id="cropperModal">
    <div class="wp-modal" style="max-width: 700px;">
        <div class="wp-modal-head">
            <h2><i class="fas fa-crop-alt"></i> Crop Banner Image</h2>
            <button type="button" class="wp-modal-close" onclick="closeCropperModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="wp-modal-body">
            <div style="max-height: 60vh;">
                <img id="cropper-image" style="max-width: 100%; display:block;">
            </div>
        </div>
        <div class="wp-modal-foot" style="display:flex; justify-content:space-between; align-items:center;">
            <span class="editor-help-text" style="margin:0;">Crop area locked to 16:9</span>
            <div style="display:flex; gap:.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeCropperModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="cropAndUploadBanner()">
                    <i class="fas fa-check"></i> Crop &amp; Use
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: Link Button Builder (TinyMCE plugin popup)             -->
<!-- ============================================================ -->
<div class="lbb-modal-overlay" id="lbb-overlay" style="display:none;">
    <div class="lbb-modal-box">
        <h3 class="lbb-modal-title">Insert Link Button</h3>
        <div class="lbb-grid">
            <div>
                <label class="lbb-label">Button Text</label>
                <input type="text" id="lbb-text" class="lbb-input" placeholder="e.g. Download Now" value="Click Here">
            </div>
            <div>
                <label class="lbb-label">Link URL</label>
                <input type="text" id="lbb-url" class="lbb-input" placeholder="https://example.com">
            </div>
            <div class="lbb-row">
                <div>
                    <label class="lbb-label">Background Color</label>
                    <div class="lbb-color-wrap">
                        <input type="color" id="lbb-bgcolor" class="lbb-color-picker" value="#7c3aed">
                        <span class="lbb-color-val" id="lbb-bgcolor-val">#7c3aed</span>
                    </div>
                </div>
                <div>
                    <label class="lbb-label">Text Color</label>
                    <div class="lbb-color-wrap">
                        <input type="color" id="lbb-textcolor" class="lbb-color-picker" value="#ffffff">
                        <span class="lbb-color-val" id="lbb-textcolor-val">#ffffff</span>
                    </div>
                </div>
            </div>
            <div class="lbb-row">
                <div>
                    <label class="lbb-label">Padding</label>
                    <select id="lbb-padding" class="lbb-select">
                        <option value="8px 16px">Small</option>
                        <option value="12px 24px" selected>Medium</option>
                        <option value="16px 32px">Large</option>
                    </select>
                </div>
                <div>
                    <label class="lbb-label">Border Radius</label>
                    <select id="lbb-radius" class="lbb-select">
                        <option value="0px">Square</option>
                        <option value="6px" selected>Rounded</option>
                        <option value="9999px">Pill</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="lbb-label">Alignment</label>
                <select id="lbb-align" class="lbb-select">
                    <option value="left">Left</option>
                    <option value="center" selected>Center</option>
                    <option value="right">Right</option>
                </select>
            </div>
            <div class="lbb-preview-box">
                <div class="lbb-preview-label">Preview</div>
                <div class="lbb-preview-area" id="lbb-preview-area">
                    <a id="lbb-preview-btn" href="#" onclick="return false;" style="display:inline-block; text-decoration:none; font-weight:600; font-family:inherit;">Click Here</a>
                </div>
            </div>
        </div>
        <div class="lbb-footer">
            <button type="button" class="lbb-btn lbb-btn-cancel" onclick="closeLinkButtonPopup()">Cancel</button>
            <button type="button" class="lbb-btn lbb-btn-save" onclick="insertLinkButton()">Insert Button</button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: Media Library                                          -->
<!-- ============================================================ -->
<div class="mlb-overlay" id="mlb-overlay" style="display:none;">
    <div class="mlb-box">
        <div class="mlb-head">
            <h3><i class="fas fa-photo-video"></i> Media Library</h3>
            <button type="button" class="mlb-x" onclick="closeMediaLibrary()">&times;</button>
        </div>

        <div class="mlb-toolbar">
            <div class="mlb-search">
                <i class="fas fa-search"></i>
                <input type="text" id="mlb-search-input" placeholder="Search media...">
            </div>
            <label class="mlb-upload-btn">
                <i class="fas fa-upload"></i> Upload New
                <input type="file" id="mlb-upload-input" accept="image/*" style="display:none;">
            </label>
        </div>

        <div class="mlb-upload-progress" id="mlb-upload-progress" style="display:none;">
            <div class="mlb-upload-bar"><div id="mlb-upload-bar-fill"></div></div>
        </div>

        <div class="mlb-main">
            <div class="mlb-col-grid">
                <div class="mlb-grid" id="mlb-grid">
                    <div class="mlb-empty">Loading media&hellip;</div>
                </div>
                <div class="mlb-pager" id="mlb-pager"></div>
            </div>
            <div class="mlb-col-detail" id="mlb-col-detail">
                <div class="mlb-no-sel">Select an image to see details</div>
            </div>
        </div>

        <div class="mlb-foot">
            <span class="editor-help-text" style="margin:0;" id="mlb-status-text">&nbsp;</span>
            <div style="display:flex; gap:.5rem;">
                <button type="button" class="mlb-btn-cancel" onclick="closeMediaLibrary()">Cancel</button>
                <button type="button" class="mlb-btn-use" id="mlb-use-btn" disabled onclick="useMediaLibrarySelection()">Use as Featured Image</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container used for non-header floating messages (drafts etc.) -->
<div id="toast-container" style="position: fixed; bottom: 20px; right: 20px; z-index: 99999; display:flex; flex-direction:column; gap:8px;"></div>

<script>
const ADMIN_URL   = "/admin";
const SITE_URL    = <?= json_encode(rtrim($site_url, '/')) ?>;
const IS_EDIT     = <?= $is_edit ? 'true' : 'false' ?>;
const POST_ID     = <?= $is_edit ? (int)$post_id : 'null' ?>;
const POST_SLUG_INITIAL = <?= json_encode($is_edit ? $post_data['slug'] : '') ?>;

// ============================================================
// Sidebar toggle (shared shell behaviour)
// ============================================================
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
});
let touchStartX = 0, touchEndX = 0;
const sidebarEl = document.getElementById('sidebar');
sidebarEl.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, false);
sidebarEl.addEventListener('touchend', e => {
    touchEndX = e.changedTouches[0].screenX;
    if (touchStartX - touchEndX > 100) toggleSidebar();
}, false);

// ============================================================
// Navigation helper (ported from original post-manager.php)
// ============================================================
function goBackHome() {
    if (history.length > 1) {
        history.back();
    } else {
        window.location.href = '/manage.php';
    }
}

// ============================================================
// Header-inline toast (top-nav success/error banner)
// ============================================================
function showHeaderToast(message, type = 'success', autoHideMs = 5000) {
    const slot = document.getElementById('header-toast-slot');
    const existing = slot.querySelector('.header-toast');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.className = 'header-toast toast-' + type;
    el.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} toast-icon"></i>
        <span class="toast-text">${message}</span>
        <button type="button" class="toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    slot.insertBefore(el, slot.firstChild);

    if (autoHideMs) {
        setTimeout(() => {
            if (!el.isConnected) return;
            el.classList.add('toast-hide');
            setTimeout(() => el.remove(), 200);
        }, autoHideMs);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // NOTE: on successful save, EduMint redirects to /admin/dashboard.php?success=...
    // (same as the original post-manager.php), where the existing dashboard already
    // shows its own success alert. Nothing to do here on this page after a save.
});

// ============================================================
// Collapsible meta-panels (WordPress-style sidebar accordions)
// ============================================================
function togglePanel(id) {
    document.getElementById(id).classList.toggle('closed');
}

// ============================================================
// Publish box: WordPress-style inline "Edit" rows
// ============================================================
function toggleEditBox(key) {
    const box = document.getElementById(key + '-editbox');
    const isOpen = box.style.display === 'flex';
    document.querySelectorAll('.wp-pub-editbox').forEach(b => b.style.display = 'none');
    box.style.display = isOpen ? 'none' : 'flex';
}

function confirmStatus() {
    const sel = document.getElementById('status-select');
    document.getElementById('status-display-text').textContent =
        sel.options[sel.selectedIndex].text;
    toggleEditBox('status');
}

function onStatusChange() {
    const status = document.getElementById('status-select').value;
    document.getElementById('schedule-field-wrap').style.display = (status === 'scheduled') ? 'block' : 'none';
}

function confirmLastDate() {
    const val = document.getElementById('last_date').value;
    document.getElementById('lastdate-display-text').textContent =
        val ? new Date(val + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Not set';
    toggleEditBox('lastdate');
}

function confirmAuthor() {
    const sel = document.getElementById('author-select');
    document.getElementById('author-display-text').textContent =
        sel.value ? sel.options[sel.selectedIndex].text : 'Select author';
    toggleEditBox('author');
}

// ============================================================
// Title + Permalink + char counter
// ============================================================
const titleInput  = document.getElementById('post-title');
const slugInput   = document.getElementById('post-slug');
const titleCounter = document.getElementById('title-counter');

function slugify(str) {
    return str.toLowerCase().trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

let userEditedSlug = slugInput.value.trim() !== '';

titleInput.addEventListener('input', () => {
    titleCounter.textContent = titleInput.value.length + ' / 200';
    titleCounter.classList.toggle('warning', titleInput.value.length > 160);
    titleCounter.classList.toggle('danger', titleInput.value.length > 195);

    if (!userEditedSlug) {
        slugInput.value = slugify(titleInput.value);
    }
    updateCopyLinkState();
});

slugInput.addEventListener('input', () => {
    userEditedSlug = slugInput.value.trim() !== '';
    updateCopyLinkState();
});
slugInput.addEventListener('blur', () => {
    if (slugInput.value) slugInput.value = slugify(slugInput.value);
    updateCopyLinkState();
});

titleCounter.textContent = titleInput.value.length + ' / 200';

function currentPostUrl() {
    const slug = slugInput.value.trim();
    if (!slug) return '';
    return SITE_URL + '/' + slug;
}

function updateCopyLinkState() {
    const row = document.getElementById('post-url-row');
    const hasSlug = slugInput.value.trim() !== '';
    row.style.display = hasSlug ? 'flex' : 'none';
    document.getElementById('copy-link-btn').classList.toggle('is-disabled', !hasSlug);
    document.getElementById('copy-fb-comment-btn').classList.toggle('is-disabled', !hasSlug);
}
updateCopyLinkState();

function flashCopied(btn) {
    btn.classList.add('is-copied');
    setTimeout(() => btn.classList.remove('is-copied'), 1500);
}

function copyPostLink() {
    const url = currentPostUrl();
    if (!url) return;
    navigator.clipboard.writeText(url).then(() => flashCopied(document.getElementById('copy-link-btn')));
}

function copyFbComment() {
    const url = currentPostUrl();
    if (!url) return;
    const template = <?= json_encode($fb_comment_copy_text) ?>;
    navigator.clipboard.writeText(template + url).then(() => flashCopied(document.getElementById('copy-fb-comment-btn')));
}

// ============================================================
// Meta description char counter
// ============================================================
const metaDescEl = document.getElementById('meta_description');
const metaDescCounter = document.getElementById('meta-desc-counter');
function updateMetaDescCounter() {
    const len = metaDescEl.value.length;
    metaDescCounter.textContent = len + ' / 160';
    metaDescCounter.classList.toggle('warning', len > 140);
    metaDescCounter.classList.toggle('danger', len > 160);
}
metaDescEl.addEventListener('input', updateMetaDescCounter);
updateMetaDescCounter();

// ============================================================
// Chapter counter badge — counts H1 tags in the editor content
// ============================================================
function updateChapterBadge() {
    let html = '';
    if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
        html = tinymce.get('content').getContent();
    } else {
        html = document.getElementById('content').value;
    }
    const matches = html.match(/<h1[\s>]/gi);
    const count = matches ? matches.length : 0;

    const row = document.getElementById('chapter-badge-row');
    const text = document.getElementById('chapter-count-text');
    if (count > 1) {
        row.style.display = 'flex';
        text.textContent = count + ' chapters detected';
    } else {
        row.style.display = 'none';
    }
}
</script>
<script>
// ============================================================
// TinyMCE initialization
// ============================================================
let editorReady = false;

document.addEventListener('DOMContentLoaded', () => {
    if (typeof tinymce === 'undefined') {
        // tinymce loaded with `defer`; poll briefly until available
        const waitForTiny = setInterval(() => {
            if (typeof tinymce !== 'undefined') {
                clearInterval(waitForTiny);
                initTinyMCE();
            }
        }, 100);
    } else {
        initTinyMCE();
    }
});

function initTinyMCE() {
    tinymce.init({
        selector: '#content',
        height: 460,
        menubar: false,
        branding: false,
        plugins: 'lists link image table code searchreplace wordcount autolink',
        toolbar: 'undo redo | blocks | bold italic underline | forecolor backcolor | ' +
                 'alignleft aligncenter alignright | bullist numlist | link image linkbutton | table | code',
        content_style: `
            body { font-family: 'Inter', sans-serif; font-size: 15px; line-height: 1.7; color: #1f2937; padding: 12px; }
            h1 { font-size: 1.6em; font-weight: 700; margin: 1em 0 .5em; }
            h2 { font-size: 1.35em; font-weight: 700; margin: 1em 0 .5em; }
            table { border-collapse: collapse; width: 100%; }
            table td, table th { border: 1px solid #e5e7eb; padding: 6px 10px; }
        `,
        setup: function (editor) {
            editor.ui.registry.addButton('linkbutton', {
                icon: 'link',
                tooltip: 'Insert Link Button',
                onAction: () => openLinkButtonPopup(editor)
            });
            editor.on('change keyup undo redo', () => {
                updateChapterBadge();
            });
            editor.on('init', () => {
                editorReady = true;
                updateChapterBadge();
            });
        }
    });
}

// ============================================================
// Visual / Text (HTML source) tab switching
// ============================================================
let currentTab = 'visual';

function switchEditorTab(tab) {
    if (tab === currentTab) return;

    document.querySelectorAll('.editor-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));

    const visualWrap = tinymce.get('content')?.getContainer();
    const htmlWrap    = document.getElementById('html-editor-wrap');

    if (tab === 'text') {
        // Push current visual content into the HTML textarea
        const html = tinymce.get('content').getContent();
        const htmlTextarea = document.getElementById('content-html-editor');
        htmlTextarea.value = html;
        updateWordCount(html);
        if (visualWrap) visualWrap.style.display = 'none';
        htmlWrap.style.display = 'block';
    } else {
        // Push HTML textarea content back into TinyMCE
        const html = document.getElementById('content-html-editor').value;
        tinymce.get('content').setContent(html);
        if (visualWrap) visualWrap.style.display = '';
        htmlWrap.style.display = 'none';
        updateChapterBadge();
    }
    currentTab = tab;
}

function updateWordCount(html) {
    const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    const words = text ? text.split(' ').length : 0;
    document.getElementById('word-count-text').textContent = words + ' words';
}

document.getElementById('content-html-editor').addEventListener('input', (e) => {
    updateWordCount(e.target.value);
});

// Simple HTML quicktag buttons for the Text tab
const QUICKTAGS = [
    { label: 'b',      before: '<strong>', after: '</strong>' },
    { label: 'i',      before: '<em>', after: '</em>' },
    { label: 'link',   before: '<a href="">', after: '</a>' },
    { label: 'b-quote',before: '<blockquote>', after: '</blockquote>' },
    { label: 'img',    before: '<img src="" alt="">', after: '' },
    { label: 'ul',     before: '<ul>\n  <li>', after: '</li>\n</ul>' },
    { label: 'li',     before: '<li>', after: '</li>' },
    { label: 'code',   before: '<code>', after: '</code>' },
    { label: 'more',   before: '<!--more-->', after: '' }
];

(function buildQuicktags() {
    const wrap = document.getElementById('html-quicktags');
    QUICKTAGS.forEach(tag => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'qt-btn';
        btn.textContent = tag.label;
        btn.onclick = () => insertQuicktag(tag.before, tag.after);
        wrap.appendChild(btn);
    });
})();

function insertQuicktag(before, after) {
    const ta = document.getElementById('content-html-editor');
    const start = ta.selectionStart, end = ta.selectionEnd;
    const selected = ta.value.substring(start, end);
    const insertion = before + selected + after;
    ta.value = ta.value.substring(0, start) + insertion + ta.value.substring(end);
    ta.focus();
    ta.selectionStart = start + before.length;
    ta.selectionEnd = start + before.length + selected.length;
    updateWordCount(ta.value);
}

// ============================================================
// Link Button Builder (TinyMCE custom plugin popup)
// ============================================================
let lbbActiveEditor = null;

function openLinkButtonPopup(editor) {
    lbbActiveEditor = editor;
    document.getElementById('lbb-overlay').style.display = 'flex';
    updateLbbPreview();
}
function closeLinkButtonPopup() {
    document.getElementById('lbb-overlay').style.display = 'none';
}

['lbb-text', 'lbb-url', 'lbb-padding', 'lbb-radius', 'lbb-align'].forEach(id => {
    document.getElementById(id).addEventListener('input', updateLbbPreview);
    document.getElementById(id).addEventListener('change', updateLbbPreview);
});
document.getElementById('lbb-bgcolor').addEventListener('input', (e) => {
    document.getElementById('lbb-bgcolor-val').textContent = e.target.value;
    updateLbbPreview();
});
document.getElementById('lbb-textcolor').addEventListener('input', (e) => {
    document.getElementById('lbb-textcolor-val').textContent = e.target.value;
    updateLbbPreview();
});

function updateLbbPreview() {
    const btn = document.getElementById('lbb-preview-btn');
    const area = document.getElementById('lbb-preview-area');
    btn.textContent = document.getElementById('lbb-text').value || 'Click Here';
    btn.style.background = document.getElementById('lbb-bgcolor').value;
    btn.style.color = document.getElementById('lbb-textcolor').value;
    btn.style.padding = document.getElementById('lbb-padding').value;
    btn.style.borderRadius = document.getElementById('lbb-radius').value;
    area.style.justifyContent = { left: 'flex-start', center: 'center', right: 'flex-end' }[document.getElementById('lbb-align').value];
}

function insertLinkButton() {
    if (!lbbActiveEditor) return;
    const text   = document.getElementById('lbb-text').value || 'Click Here';
    const url    = document.getElementById('lbb-url').value || '#';
    const bg     = document.getElementById('lbb-bgcolor').value;
    const color  = document.getElementById('lbb-textcolor').value;
    const pad    = document.getElementById('lbb-padding').value;
    const radius = document.getElementById('lbb-radius').value;
    const align  = document.getElementById('lbb-align').value;

    const html = `<p style="text-align:${align};"><a href="${url}" style="display:inline-block; background:${bg}; color:${color}; padding:${pad}; border-radius:${radius}; text-decoration:none; font-weight:600;" target="_blank" rel="noopener">${text}</a></p>`;

    lbbActiveEditor.insertContent(html);
    closeLinkButtonPopup();
}
</script>
<script>
// ============================================================
// FAQ Manager
// ============================================================
let faqData = [];
try {
    faqData = JSON.parse(document.getElementById('faq_json_input').value || '[]');
} catch (e) { faqData = []; }

function openFaqModal() {
    renderFaqRows();
    document.getElementById('faqModal').classList.add('open');
}
function closeFaqModal() {
    document.getElementById('faqModal').classList.remove('open');
}

function renderFaqRows() {
    const container = document.getElementById('faq-rows-container');
    container.innerHTML = '';

    if (faqData.length === 0) {
        addFaqRow(); // start with one empty row for convenience
        return;
    }

    faqData.forEach((item, i) => container.appendChild(buildFaqRowEl(item.q, item.a, i)));
}

function buildFaqRowEl(q = '', a = '', index) {
    const row = document.createElement('div');
    row.className = 'faq-row';
    row.dataset.index = index;
    row.innerHTML = `
        <div class="faq-row-head">
            <span class="faq-row-num">Question ${index + 1}</span>
            <button type="button" class="faq-rm" onclick="removeFaqRow(this)"><i class="fas fa-trash"></i> Remove</button>
        </div>
        <label>Question</label>
        <input type="text" class="faq-q" value="${escapeAttr(q)}" placeholder="e.g. Is this show based on a true story?">
        <label style="margin-top:.5rem;">Answer</label>
        <textarea class="faq-a" placeholder="Write the answer...">${escapeHtml(a)}</textarea>
    `;
    return row;
}

function addFaqRow() {
    const container = document.getElementById('faq-rows-container');
    const index = container.children.length;
    container.appendChild(buildFaqRowEl('', '', index));
}

function removeFaqRow(btn) {
    btn.closest('.faq-row').remove();
    // renumber remaining rows
    document.querySelectorAll('#faq-rows-container .faq-row').forEach((row, i) => {
        row.dataset.index = i;
        row.querySelector('.faq-row-num').textContent = 'Question ' + (i + 1);
    });
}

function saveFaqModal() {
    const rows = document.querySelectorAll('#faq-rows-container .faq-row');
    const data = [];
    rows.forEach(row => {
        const q = row.querySelector('.faq-q').value.trim();
        const a = row.querySelector('.faq-a').value.trim();
        if (q && a) data.push({ q, a });
    });

    faqData = data;
    document.getElementById('faq_json_input').value = JSON.stringify(faqData);
    renderFaqSummary();
    closeFaqModal();
}

function renderFaqSummary() {
    const wrap = document.getElementById('faq-summary-preview');
    if (faqData.length === 0) {
        wrap.innerHTML = '<div class="faq-prev-empty">No FAQs added yet. Click "Manage FAQs" to add some.</div>';
        return;
    }
    wrap.innerHTML = '<div id="faq-preview-list">' +
        faqData.map(item => `
            <div class="faq-prev-item">
                <strong>${escapeHtml(item.q)}</strong>
                ${escapeHtml(item.a.length > 90 ? item.a.slice(0, 90) + '…' : item.a)}
            </div>
        `).join('') +
    '</div>';
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
function escapeAttr(str) {
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Render FAQ summary on load (edit mode)
renderFaqSummary();

// ============================================================
// Table Template Insert
// ============================================================
let tplLibrary = [];
try {
    tplLibrary = JSON.parse(document.getElementById('tpl-data').textContent || '[]');
} catch (e) { tplLibrary = []; }

function openTemplateModal() {
    document.getElementById('templateModal').classList.add('open');
}
function closeTemplateModal() {
    document.getElementById('templateModal').classList.remove('open');
}

function insertTemplate(id) {
    const tpl = tplLibrary.find(t => t.id === id);
    if (!tpl) return;
    if (tinymce.get('content')) {
        tinymce.get('content').insertContent(tpl.html);
    }
    closeTemplateModal();
    showHeaderToast('Table template inserted', 'success', 2500);
}

// ============================================================
// Image Cropper (Cropper.js) — Banner upload
// ============================================================
let cropperInstance  = null;
let originalFileName = '';

document.getElementById('banner').addEventListener('change', function (e) {
    const file = e.target.files[0];
    if (!file) return;

    originalFileName = file.name.replace(/\.[^/.]+$/, '');

    const reader = new FileReader();
    reader.onload = function (evt) {
        document.getElementById('cropper-image').src = evt.target.result;
        document.getElementById('cropperModal').classList.add('open');

        if (cropperInstance) cropperInstance.destroy();
        cropperInstance = new Cropper(document.getElementById('cropper-image'), {
            aspectRatio: 16 / 9,
            viewMode: 1,
            autoCropArea: 1,
            responsive: true,
        });
    };
    reader.readAsDataURL(file);
});

function closeCropperModal() {
    document.getElementById('cropperModal').classList.remove('open');
    document.getElementById('banner').value = '';
    if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
}

const BANNER_SIZES = [
    { key: 'xl', width: 1600 },
    { key: 'lg', width: 1200 },
    { key: 'md', width: 800  },
    { key: 'sm', width: 480  },
];

function cropAndUploadBanner() {
    if (!cropperInstance) return;

    const dt = new DataTransfer();
    let processed = 0;

    BANNER_SIZES.forEach(size => {
        const canvas = cropperInstance.getCroppedCanvas({
            width: size.width,
            height: Math.round(size.width * 9 / 16),
            imageSmoothingQuality: 'high',
        });

        canvas.toBlob(blob => {
            const file = new File([blob], `${originalFileName}_${size.key}.webp`, { type: 'image/webp' });
            dt.items.add(file);
            processed++;

            if (processed === BANNER_SIZES.length) {
                document.getElementById('banner').files = dt.files;
                renderBannerPreview(dt.files);
                closeCropperModal();
                showHeaderToast('Banner cropped — will upload with the post', 'success', 3000);
            }
        }, 'image/webp', 0.85);
    });
}

function renderBannerPreview(fileList) {
    const wrap = document.getElementById('banner-preview-area');
    wrap.innerHTML = '<div class="existing-banner-preview"></div>';
    const grid = wrap.querySelector('.existing-banner-preview');

    Array.from(fileList).forEach(file => {
        const reader = new FileReader();
        reader.onload = (e) => {
            const label = file.name.includes('_xl') ? 'XL' : file.name.includes('_lg') ? 'LG' : file.name.includes('_md') ? 'MD' : 'SM';
            const thumb = document.createElement('div');
            thumb.className = 'banner-thumb';
            thumb.innerHTML = `<span class="banner-label">${label}</span><img src="${e.target.result}">`;
            grid.appendChild(thumb);
        };
        reader.readAsDataURL(file);
    });
}
</script>
<script>
// ============================================================
// Media Library modal
// ============================================================
const MEDIA_API = '/api/9h4k2m8p1q.php';

let mlbCurrentPage = 1;
let mlbSearchTerm  = '';
let mlbSelectedItem = null;
let mlbSearchDebounce = null;

function openMediaLibrary() {
    document.getElementById('mlb-overlay').style.display = 'flex';
    mlbSelectedItem = null;
    updateMlbUseButton();
    loadMediaLibrary(1);
}
function closeMediaLibrary() {
    document.getElementById('mlb-overlay').style.display = 'none';
}

async function loadMediaLibrary(page = 1) {
    mlbCurrentPage = page;
    const grid = document.getElementById('mlb-grid');
    grid.innerHTML = '<div class="mlb-empty">Loading media&hellip;</div>';

    try {
        const url = `${MEDIA_API}?action=list&page=${page}&q=${encodeURIComponent(mlbSearchTerm)}`;
        const res = await fetch(url);
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Failed to load media');

        renderMlbGrid(data.items);
        renderMlbPager(data.page, data.total_pages);
    } catch (err) {
        grid.innerHTML = `<div class="mlb-empty">Could not load media library.<br>${escapeHtml(err.message)}</div>`;
    }
}

function renderMlbGrid(items) {
    const grid = document.getElementById('mlb-grid');
    if (!items.length) {
        grid.innerHTML = '<div class="mlb-empty">No images found.</div>';
        return;
    }
    grid.innerHTML = '';
    items.forEach(item => {
        const el = document.createElement('div');
        el.className = 'mlb-item';
        el.dataset.id = item.id;
        el.innerHTML = `<img src="${item.url}" loading="lazy" alt="${escapeAttr(item.alt_text)}"><div class="mlb-item-check"><i class="fas fa-check"></i></div>`;
        el.addEventListener('click', () => selectMlbItem(item, el));
        grid.appendChild(el);
    });
}

function renderMlbPager(page, totalPages) {
    const pager = document.getElementById('mlb-pager');
    pager.innerHTML = '';
    if (totalPages <= 1) return;

    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = i;
        btn.className = i === page ? 'active' : '';
        btn.onclick = () => loadMediaLibrary(i);
        pager.appendChild(btn);
    }
}

function selectMlbItem(item, el) {
    document.querySelectorAll('.mlb-item.selected').forEach(n => n.classList.remove('selected'));
    el.classList.add('selected');
    mlbSelectedItem = item;
    updateMlbUseButton();
    renderMlbDetail(item);
}

function updateMlbUseButton() {
    document.getElementById('mlb-use-btn').disabled = !mlbSelectedItem;
}

function renderMlbDetail(item) {
    const wrap = document.getElementById('mlb-col-detail');
    wrap.innerHTML = `
        <div class="mlb-detail">
            <img src="${item.url}" alt="">
            <div class="mlb-field">
                <label>File name</label>
                <div class="mlb-field-val">${escapeHtml(item.file_name)}</div>
            </div>
            <div class="mlb-field">
                <label>Uploaded</label>
                <div class="mlb-field-val">${escapeHtml(item.uploaded_at || '')}</div>
            </div>
            <div class="mlb-field">
                <label>Alt text</label>
                <input type="text" id="mlb-alt-input" value="${escapeAttr(item.alt_text)}" placeholder="Describe this image">
                <span class="mlb-save-status" id="mlb-alt-status"></span>
            </div>
            <div class="mlb-field">
                <label>URL</label>
                <div class="mlb-url-row">
                    <input type="text" readonly value="${item.url}" onclick="this.select()">
                </div>
            </div>
            <div class="mlb-detail-actions">
                <a class="mlb-btn-download" href="${item.url}" target="_blank" download>Download</a>
                <button type="button" class="mlb-btn-del" onclick="deleteMlbItem(${item.id})"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    `;

    let altSaveTimer = null;
    document.getElementById('mlb-alt-input').addEventListener('input', (e) => {
        clearTimeout(altSaveTimer);
        const status = document.getElementById('mlb-alt-status');
        status.textContent = 'Saving…';
        altSaveTimer = setTimeout(async () => {
            const fd = new FormData();
            fd.append('action', 'update');
            fd.append('id', item.id);
            fd.append('alt_text', e.target.value);
            try {
                await fetch(MEDIA_API, { method: 'POST', body: fd });
                item.alt_text = e.target.value;
                mlbSelectedItem = item;
                status.textContent = 'Saved';
                setTimeout(() => { status.textContent = ''; }, 1500);
            } catch { status.textContent = 'Failed to save'; }
        }, 500);
    });
}

async function deleteMlbItem(id) {
    if (!confirm('Delete this image permanently? This cannot be undone.')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        const res = await fetch(MEDIA_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) {
            showHeaderToast(data.error || 'Could not delete image', 'error');
            return;
        }
        mlbSelectedItem = null;
        updateMlbUseButton();
        document.getElementById('mlb-col-detail').innerHTML = '<div class="mlb-no-sel">Select an image to see details</div>';
        loadMediaLibrary(mlbCurrentPage);
    } catch {
        showHeaderToast('Could not delete image', 'error');
    }
}

document.getElementById('mlb-search-input').addEventListener('input', (e) => {
    clearTimeout(mlbSearchDebounce);
    mlbSearchDebounce = setTimeout(() => {
        mlbSearchTerm = e.target.value.trim();
        loadMediaLibrary(1);
    }, 350);
});

document.getElementById('mlb-upload-input').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const progressWrap = document.getElementById('mlb-upload-progress');
    const bar = document.getElementById('mlb-upload-bar-fill');
    progressWrap.style.display = 'block';
    bar.style.width = '20%';

    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('file', file);

    try {
        bar.style.width = '60%';
        const res = await fetch(MEDIA_API, { method: 'POST', body: fd });
        const data = await res.json();
        bar.style.width = '100%';

        if (!data.success) throw new Error(data.error || 'Upload failed');

        setTimeout(() => { progressWrap.style.display = 'none'; bar.style.width = '0%'; }, 400);
        e.target.value = '';
        mlbSearchTerm = '';
        document.getElementById('mlb-search-input').value = '';
        loadMediaLibrary(1);
        showHeaderToast('Image uploaded to Media Library', 'success', 3000);
    } catch (err) {
        progressWrap.style.display = 'none';
        bar.style.width = '0%';
        showHeaderToast(err.message || 'Upload failed', 'error');
    }
});

function useMediaLibrarySelection() {
    if (!mlbSelectedItem) return;

    document.getElementById('existing_featured_image_id').value = mlbSelectedItem.id;

    // Clear any pending "Upload & Crop" file so it doesn't override this selection on submit
    document.getElementById('banner').value = '';
    document.getElementById('banner-preview-area').innerHTML = '';

    const previewWrap = document.getElementById('featured-image-preview-wrap');
    previewWrap.innerHTML = `<img src="${mlbSelectedItem.url}" class="feat-img-preview" id="featured-image-preview" onclick="openMediaLibrary()">`;

    closeMediaLibrary();
    showHeaderToast('Featured image set', 'success', 2500);
}

// ============================================================
// Choices.js (Additional Categories) + TomSelect (Tags)
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    new Choices('#additional_category_ids', {
        removeItemButton: true,
        placeholderValue: 'Select additional categories...',
        searchPlaceholderValue: 'Search categories...',
        shouldSort: false,
    });

    new TomSelect('#tags_select', {
        create: true,
        createOnBlur: true,
        persist: false,
        placeholder: 'Type to add tags...',
    });
});

// ============================================================
// Preview button — opens the post URL (or a draft-preview endpoint) in a new tab
// ============================================================
function previewPost() {
    const slug = slugInput.value.trim();
    if (!slug) {
        showHeaderToast('Add a title first so a link can be generated', 'error', 3000);
        return;
    }
    window.open(currentPostUrl() + (IS_EDIT ? '' : '?preview=1'), '_blank');
}

// ============================================================
// Form submit — sync TinyMCE/Text-tab content, clear autosave draft
// ============================================================
document.getElementById('post-form').addEventListener('submit', function (e) {
    if (currentTab === 'text') {
        document.getElementById('content').value = document.getElementById('content-html-editor').value;
    } else if (tinymce.get('content')) {
        tinymce.triggerSave();
    }

    localStorage.removeItem(AUTOSAVE_KEY);

    const submitBtn = document.getElementById('submit-btn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});

// ============================================================
// Autosave (localStorage draft) — ported from original EduMint post-manager
// ============================================================
const AUTOSAVE_KEY = 'post_draft_' + (IS_EDIT ? 'edit_' + POST_ID : 'new');
const AUTOSAVE_MAX_DRAFTS = 5;

function collectDraftData() {
    let content = '';
    if (currentTab === 'text') {
        content = document.getElementById('content-html-editor').value;
    } else if (tinymce.get('content')) {
        content = tinymce.get('content').getContent();
    }
    return {
        title:      titleInput.value,
        slug:       slugInput.value,
        content:    content,
        meta_kw:    document.getElementById('meta_keywords').value,
        meta_desc:  document.getElementById('meta_description').value,
        fb_desc:    document.getElementById('fb_description').value,
        category:   document.getElementById('category_id').value,
        state:      document.getElementById('state_id').value,
        faq_json:   document.getElementById('faq_json_input').value,
        timestamp:  new Date().getTime()
    };
}

function enforceMaxDraftsLimit() {
    const keys = Object.keys(localStorage).filter(k => k.startsWith('post_draft_'));
    if (keys.length <= AUTOSAVE_MAX_DRAFTS) return;

    const withTimestamps = keys.map(k => {
        try { return { key: k, ts: JSON.parse(localStorage.getItem(k)).timestamp || 0 }; }
        catch { return { key: k, ts: 0 }; }
    }).sort((a, b) => a.ts - b.ts);

    while (withTimestamps.length > AUTOSAVE_MAX_DRAFTS) {
        localStorage.removeItem(withTimestamps.shift().key);
    }
}

function showAutosaveStatus(state) {
    const el = document.getElementById('header-toast-slot');
    let indicator = document.getElementById('autosave-indicator');
    if (!indicator) {
        indicator = document.createElement('span');
        indicator.id = 'autosave-indicator';
        indicator.style.cssText = 'font-size:.75rem; color:var(--gray-400); margin-right:.4rem; white-space:nowrap;';
        el.insertBefore(indicator, el.firstChild);
    }
    indicator.textContent = state === 'saved' ? 'Draft saved' : '';
    if (state === 'saved') {
        setTimeout(() => { if (indicator) indicator.textContent = ''; }, 2500);
    }
}

function checkForExistingDraft() {
    const raw = localStorage.getItem(AUTOSAVE_KEY);
    if (!raw) return;
    try {
        const draft = JSON.parse(raw);
        if (!draft.title && !draft.content) return;

        const banner = document.createElement('div');
        banner.className = 'header-toast';
        banner.style.maxWidth = '420px';
        banner.innerHTML = `
            <i class="fas fa-history toast-icon"></i>
            <span class="toast-text">Unsaved draft found from ${new Date(draft.timestamp).toLocaleString()}.
                <a href="#" onclick="restoreDraft(); return false;" style="color:var(--primary); font-weight:600;">Restore</a></span>
            <button type="button" class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        const slot = document.getElementById('header-toast-slot');
        slot.insertBefore(banner, slot.firstChild);
    } catch (e) { /* ignore corrupt draft */ }
}

function restoreDraft() {
    const raw = localStorage.getItem(AUTOSAVE_KEY);
    if (!raw) return;
    try {
        const draft = JSON.parse(raw);
        titleInput.value = draft.title || '';
        titleInput.dispatchEvent(new Event('input'));
        slugInput.value = draft.slug || '';
        userEditedSlug = true;

        if (tinymce.get('content')) tinymce.get('content').setContent(draft.content || '');
        document.getElementById('content-html-editor').value = draft.content || '';

        document.getElementById('meta_keywords').value = draft.meta_kw || '';
        document.getElementById('meta_description').value = draft.meta_desc || '';
        metaDescEl.dispatchEvent(new Event('input'));
        document.getElementById('fb_description').value = draft.fb_desc || '';
        if (draft.category) document.getElementById('category_id').value = draft.category;
        if (draft.state) document.getElementById('state_id').value = draft.state;
        if (draft.faq_json) {
            document.getElementById('faq_json_input').value = draft.faq_json;
            try { faqData = JSON.parse(draft.faq_json); renderFaqSummary(); } catch (e) {}
        }

        showHeaderToast('Draft restored', 'success', 2500);
        updateChapterBadge();
    } catch (e) { /* ignore */ }
}

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(checkForExistingDraft, 800);

    setInterval(() => {
        try {
            localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(collectDraftData()));
            enforceMaxDraftsLimit();
            showAutosaveStatus('saved');
        } catch (e) { /* storage full or unavailable — ignore */ }
    }, 15000);
});

// Clear the draft once the form is actually submitted successfully
window.addEventListener('beforeunload', function (e) {
    // no-op placeholder retained for parity with original unsaved-changes guard if needed later
});
</script>

<?php include_once DROOT_PATH . '/admin/components/admin_footer.php'; ?>
</body>
</html>
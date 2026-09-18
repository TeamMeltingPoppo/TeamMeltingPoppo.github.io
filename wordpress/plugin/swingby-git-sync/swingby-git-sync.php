<?php
/**
 * Plugin Name: Swingby Git Sync
 * Description: Stage Astro builds from GitHub, then publish WordPress posts, pages and design together.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */
if (!defined('ABSPATH')) { exit; }

function swingby_git_error($message, $status = 400) {
    return new WP_Error('swingby_sync', $message, array('status' => $status));
}
function swingby_git_allowed() {
    return is_ssl() && current_user_can('manage_options') && current_user_can('edit_theme_options');
}
add_action('rest_api_init', function () {
    register_rest_route('swingby-git/v1', '/stage', array(
        'methods' => 'POST', 'callback' => 'swingby_git_stage',
        'permission_callback' => function () {
            return swingby_git_allowed() ? true : swingby_git_error('HTTPS and an administrator account are required.', 403);
        },
    ));
});

function swingby_git_safe_asset($name) {
    return is_string($name) && strlen($name) < 240 && !preg_match('~(^|/)\.|[\\\\\x00-\x1f]|^/|:~', $name)
        && preg_match('~\.(css|js|mjs|png|jpe?g|gif|webp|avif|ico|svg|woff2?|ttf|otf|eot|pdf|mp4|webm|mp3|ogg|wav)$~i', $name);
}
function swingby_git_safe_route($route) {
    if (!is_string($route) || !preg_match('~^/(?:[^?#\\\\\x00-\x20]+/)?$~u', $route)) { return false; }
    $decoded = rawurldecode($route);
    if (preg_match('~(^|/)(?:\.{1,2}|wp-admin|wp-json|wp-content|wp-includes)(/|$)|[\\\\\x00-\x20]|^//~i', $decoded)) { return false; }
    return !preg_match('~^/(?:wp-[^/]*|xmlrpc\.php|feed|robots\.txt|rss\.xml|sitemap[^/]*)(/|$)~i', $decoded);
}
function swingby_git_validate_manifest($m) {
    if (!is_array($m) || ($m['schema'] ?? null) !== 1 || !preg_match('/^[a-f0-9]{32}$/', $m['release'] ?? '')
        || !is_array($m['records'] ?? null) || !is_array($m['assets'] ?? null)
        || count($m['records']) > 500 || count($m['assets']) > 2000) {
        return swingby_git_error('Invalid manifest.');
    }
    $expected = untrailingslashit(home_url('/'));
    if (($m['site'] ?? '') !== $expected) { return swingby_git_error('Build site URL must match WordPress home URL.'); }
    if (isset($m['notFound'])) {
        foreach (array('head', 'body') as $key) {
            if (!is_string($m['notFound'][$key] ?? null) || strlen($m['notFound'][$key]) > 2000000) { return swingby_git_error('Invalid 404 document.'); }
        }
    }
    $seen = array();
    foreach ($m['records'] as $r) {
        if (!is_array($r) || !swingby_git_safe_route($r['path'] ?? null) || isset($seen[$r['path']])
            || !in_array($r['type'] ?? '', array('post', 'page'), true)
            || !is_string($r['title'] ?? null) || strlen($r['title']) > 1000
            || !is_bool($r['draft'] ?? null)) { return swingby_git_error('Invalid or duplicate record.'); }
        foreach (array('head', 'body', 'content') as $key) {
            if (!is_string($r[$key] ?? null) || strlen($r[$key]) > 2000000) { return swingby_git_error('Invalid document.'); }
        }
        foreach (array('tags', 'categories') as $key) {
            if (isset($r[$key]) && (!is_array($r[$key]) || count($r[$key]) > 50)) { return swingby_git_error('Invalid taxonomy.'); }
            foreach ($r[$key] ?? array() as $term) {
                if (!is_string($term) || strlen($term) > 200) { return swingby_git_error('Invalid term name.'); }
            }
        }
        if (isset($r['date']) && (!is_string($r['date']) || strtotime($r['date']) === false)) { return swingby_git_error('Invalid date.'); }
        $seen[$r['path']] = true;
    }
    if (!isset($seen['/'])) { return swingby_git_error('Home page missing.'); }
    foreach ($m['assets'] as $name => $a) {
        if (!swingby_git_safe_asset($name) || !is_array($a) || !preg_match('/^[a-f0-9]{64}$/', $a['sha256'] ?? '')
            || !is_int($a['size'] ?? null) || $a['size'] < 0 || $a['size'] > 32 * 1024 * 1024) {
            return swingby_git_error('Invalid asset.');
        }
    }
    return true;
}
function swingby_git_lock($callback) {
    // Fail closed after interruption. An administrator can clear the lock in wp-cli.
    if (!add_option('swingby_git_lock', time(), '', false)) { return swingby_git_error('Another import is running; retry later.', 409); }
    try { return $callback(); } finally { delete_option('swingby_git_lock'); }
}
function swingby_git_stage($request) {
    return swingby_git_lock(function () use ($request) {
        if (!class_exists('ZipArchive')) { return swingby_git_error('PHP zip extension is required.', 500); }
        $f = $request->get_file_params()['bundle'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 64 * 1024 * 1024) {
            return swingby_git_error('Upload site.zip (maximum 64 MiB). Check Nginx/PHP upload limits.');
        }
        $zip = new ZipArchive();
        if ($zip->open($f['tmp_name']) !== true) { return swingby_git_error('Invalid ZIP.'); }
        try { return swingby_git_import_zip($zip); } finally { $zip->close(); }
    });
}
function swingby_git_import_zip($zip) {
    $stat = $zip->statName('manifest.json');
    if (!$stat || $stat['size'] > 16 * 1024 * 1024 || $zip->numFiles > 2400) { return swingby_git_error('Invalid archive size.'); }
    $raw = $zip->getFromName('manifest.json');
    $m = json_decode($raw, true);
    $valid = swingby_git_validate_manifest($m);
    if (is_wp_error($valid)) { return $valid; }
    $total = 0; $entries = array();
    // Inspect every entry before writing anything. Never use extractTo on untrusted ZIPs.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $s = $zip->statIndex($i); $name = $s['name'];
        if (isset($entries[$name])) { return swingby_git_error('Duplicate archive entry.'); }
        $entries[$name] = true;
        $zip->getExternalAttributesIndex($i, $os, $attrs);
        if ((($attrs >> 16) & 0170000) === 0120000) { return swingby_git_error('Symlinks are not allowed.'); }
        if (str_ends_with($name, '/')) {
            if ($name !== 'assets/' && (!str_starts_with($name, 'assets/') || !swingby_git_safe_asset(substr($name, 7) . 'placeholder.png'))) { return swingby_git_error('Invalid archive directory.'); }
            continue;
        }
        if ($name !== 'manifest.json' && (!str_starts_with($name, 'assets/') || !isset($m['assets'][substr($name, 7)]))) {
            return swingby_git_error('Unexpected archive entry.');
        }
        $total += $s['size'];
        if ($total > 128 * 1024 * 1024) { return swingby_git_error('Uncompressed archive is too large.'); }
    }
    foreach ($m['assets'] as $name => $asset) {
        $s = $zip->statName('assets/' . $name);
        if (!$s || $s['size'] !== $asset['size']) { return swingby_git_error('Missing asset or invalid size.'); }
        $bytes = $zip->getFromName('assets/' . $name);
        if ($bytes === false || !hash_equals($asset['sha256'], hash('sha256', $bytes))) { return swingby_git_error('Asset checksum mismatch.'); }
    }
    $uploads = wp_upload_dir();
    if ($uploads['error']) { return swingby_git_error('Uploads directory unavailable.', 500); }
    $relative = '/swingby-git/' . $m['release'];
    $dir = $uploads['basedir'] . $relative;
    $url = set_url_scheme($uploads['baseurl'] . $relative, 'https');
    $fingerprint = hash('sha256', $raw);
    if (file_exists($dir . '/complete.txt') && trim(file_get_contents($dir . '/complete.txt')) !== $fingerprint) {
        return swingby_git_error('Immutable release ID collision.', 409);
    }
    $replacements = array('/sitemap-index.xml' => '/wp-sitemap.xml', '/rss.xml' => '/?feed=rss2');
    foreach ($m['assets'] as $name => $asset) {
        $replacements[$m['site'] . '/' . $name] = $url . '/' . $name;
        $replacements['/' . $name] = $url . '/' . $name;
        $encoded = implode('/', array_map('rawurlencode', explode('/', $name)));
        $replacements[$m['site'] . '/' . $encoded] = $url . '/' . $encoded;
        $replacements['/' . $encoded] = $url . '/' . $encoded;
    }
    if (!file_exists($dir . '/complete.txt')) {
        foreach ($m['assets'] as $name => $asset) {
            $target = $dir . '/' . $name;
            if (!wp_mkdir_p(dirname($target))) { return swingby_git_error('Cannot create asset directory.', 500); }
            $bytes = $zip->getFromName('assets/' . $name);
            if (preg_match('/\.(css|js|mjs)$/i', $name)) { $bytes = strtr($bytes, $replacements); }
            if (file_put_contents($target, $bytes, LOCK_EX) === false) { return swingby_git_error('Cannot write asset.', 500); }
        }
        if (file_put_contents($dir . '/complete.txt', $fingerprint, LOCK_EX) === false) { return swingby_git_error('Cannot finalize assets.', 500); }
    }
    foreach ($m['assets'] as $name => $asset) {
        if (!preg_match('/\.(png|jpe?g|gif|webp|avif)$/i', $name)) { continue; }
        $existing = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_swingby_asset_sha',
            'meta_value' => $asset['sha256'], 'fields' => 'ids', 'numberposts' => 1));
        if ($existing) { continue; }
        $file = $dir . '/' . $name; $size = wp_getimagesize($file);
        if (!$size) { return swingby_git_error('Invalid raster image.'); }
        $attachment = wp_insert_attachment(array('post_title' => sanitize_text_field(pathinfo($name, PATHINFO_FILENAME)),
            'post_mime_type' => $size['mime'], 'post_status' => 'inherit',
            'meta_input' => array('_swingby_asset_sha' => $asset['sha256'])), $file, 0, true);
        if (is_wp_error($attachment)) { return $attachment; }
        // Astro already produced optimized variants. Do not resize everything again on EC2.
        wp_update_attachment_metadata($attachment, array('width' => $size[0], 'height' => $size[1],
            'file' => ltrim($relative . '/' . $name, '/'), 'sizes' => array()));
    }
    $ids = array();
    foreach ($m['records'] as &$r) {
        foreach (array('head', 'body', 'content') as $key) { $r[$key] = strtr($r[$key], $replacements); }
        $key = hash('sha256', $r['path']);
        $found = get_posts(array('post_type' => array('page', 'post'), 'post_status' => array('publish','future','draft','pending','private','trash'),
            'meta_key' => '_swingby_key', 'meta_value' => $key, 'numberposts' => 2, 'fields' => 'ids'));
        if (count($found) > 1) { return swingby_git_error('Duplicate managed posts.', 409); }
        $id = $found[0] ?? 0;
        if ($id && (get_post_status($id) === 'trash' || get_post_type($id) !== $r['type'])) { return swingby_git_error('A managed post is trashed or changed type. Resolve it before syncing.', 409); }
        if (!$id) {
            $id = wp_insert_post(wp_slash(array('post_type' => $r['type'], 'post_title' => $r['title'], 'post_status' => 'draft',
                'post_name' => 'swingby-' . substr($key, 0, 16), 'post_content' => wp_kses_post($r['content']),
                'meta_input' => array('_swingby_key' => $key, '_swingby_path' => $r['path']))), true);
            if (is_wp_error($id)) { return $id; }
        }
        $r['id'] = $id;
        $ids[] = $id;
    }
    unset($r);
    if (isset($m['notFound'])) {
        foreach (array('head', 'body') as $key) { $m['notFound'][$key] = strtr($m['notFound'][$key], $replacements); }
    }
    // One option swap exposes a complete pending build. Uploads never change live pages.
    update_option('swingby_git_pending', $m, false);
    if (get_option('swingby_git_auto_publish', false)) {
        $published = swingby_git_publish();
        if (is_wp_error($published)) { return $published; }
        return array('release' => $m['release'], 'count' => count($ids), 'status' => 'published');
    }
    return array('release' => $m['release'], 'count' => count($ids), 'status' => 'staged');
}

function swingby_git_publish() {
    $m = get_option('swingby_git_pending');
    if (!$m) { return swingby_git_error('No pending build.'); }
    if (get_stylesheet() !== 'swingby-astro') { return swingby_git_error('Activate the Swingby Astro Bridge theme first.'); }
    $prior = get_option('swingby_git_live', array());
    $backup = array('live' => $prior, 'posts' => array(), 'show_on_front' => get_option('show_on_front'), 'page_on_front' => get_option('page_on_front'));
    foreach ($m['records'] as $r) {
        $p = get_post($r['id'], ARRAY_A);
        if (!$p || $p['post_status'] === 'trash') { return swingby_git_error('A staged post was deleted. Stage again.', 409); }
        $backup['posts'][] = array('ID' => $p['ID'], 'post_title' => $p['post_title'], 'post_content' => $p['post_content'],
            'post_status' => $p['post_status'], 'post_date' => $p['post_date'], 'post_date_gmt' => $p['post_date_gmt'],
            'tags' => wp_get_post_terms($p['ID'], 'post_tag', array('fields' => 'ids')),
            'categories' => wp_get_post_terms($p['ID'], 'category', array('fields' => 'ids')));
    }
    update_option('swingby_git_backup', $backup, false);
    foreach ($m['records'] as $r) {
        $post = array('ID' => $r['id'], 'post_title' => $r['title'], 'post_content' => wp_kses_post($r['content']), 'post_status' => $r['draft'] ? 'draft' : 'publish');
        if (isset($r['date'])) {
            $post['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($r['date']));
            $post['post_date'] = get_date_from_gmt($post['post_date_gmt']);
            if (!$r['draft'] && $post['post_date_gmt'] > gmdate('Y-m-d H:i:s')) { $post['post_status'] = 'future'; }
        }
        $id = wp_update_post(wp_slash($post), true);
        if (is_wp_error($id)) { swingby_git_rollback(); return $id; }
        if ($r['type'] === 'post') {
            foreach (array('tags' => 'post_tag', 'categories' => 'category') as $field => $taxonomy) {
                $result = wp_set_object_terms($id, $r[$field] ?? array(), $taxonomy);
                if (is_wp_error($result)) { swingby_git_rollback(); return $result; }
            }
        }
        if ($r['path'] === '/') { update_option('show_on_front', 'page'); update_option('page_on_front', $id); }
    }
    // Keep removed routes: deleting a source file never deletes a WordPress post.
    $merged = array();
    foreach ($prior['records'] ?? array() as $r) { $merged[$r['path']] = $r; }
    foreach ($m['records'] as $r) { $merged[$r['path']] = $r; }
    $m['records'] = array_values($merged);
    update_option('swingby_git_live', $m, false);
    delete_option('swingby_git_pending');
    return true;
}
function swingby_git_rollback() {
    $backup = get_option('swingby_git_backup');
    if (!$backup) { return swingby_git_error('No rollback available.'); }
    foreach ($backup['posts'] as $p) {
        $tags = $p['tags']; $categories = $p['categories']; unset($p['tags'], $p['categories']);
        $result = wp_update_post(wp_slash($p), true);
        if (is_wp_error($result)) { return $result; }
        if (get_post_type($p['ID']) === 'post') {
            wp_set_object_terms($p['ID'], array_map('intval', $tags), 'post_tag');
            wp_set_object_terms($p['ID'], array_map('intval', $categories), 'category');
        }
    }
    update_option('swingby_git_live', $backup['live'], false);
    update_option('show_on_front', $backup['show_on_front']); update_option('page_on_front', $backup['page_on_front']);
    delete_option('swingby_git_backup');
    return true;
}
function swingby_git_route_key($route) { return rawurldecode('/' . trim($route, '/') . (trim($route, '/') === '' ? '' : '/')); }
add_action('parse_request', function ($wp) {
    if (is_admin() || array_intersect(array_keys($wp->query_vars), array('rest_route','p','page_id','preview','s','feed','robots','sitemap','sitemap-subtype','sitemap-stylesheet'))) { return; }
    $route = swingby_git_route_key($wp->request);
    foreach (get_option('swingby_git_live', array())['records'] ?? array() as $r) {
        if (swingby_git_route_key($r['path']) === $route) {
            if (get_post_status($r['id']) !== 'publish') { $wp->query_vars = array('error' => '404'); return; }
            $wp->query_vars = array($r['type'] === 'page' ? 'page_id' : 'p' => $r['id']); return;
        }
    }
});
function swingby_git_permalink($link, $post) {
    if (is_int($post)) { $post = get_post($post); }
    $route = get_post_meta($post->ID, '_swingby_path', true);
    return $route && $post->post_status === 'publish' ? home_url($route) : $link;
}
add_filter('post_link', 'swingby_git_permalink', 10, 2);
add_filter('page_link', 'swingby_git_permalink', 10, 2);
function swingby_git_document() {
    if (is_404()) { return get_option('swingby_git_live', array())['notFound'] ?? null; }
    if (!is_singular() || post_password_required()) { return null; }
    foreach (get_option('swingby_git_live', array())['records'] ?? array() as $r) {
        if ((int) $r['id'] === get_queried_object_id()) { return $r; }
    }
    return null;
}
add_action('template_redirect', function () {
    if (get_option('swingby_git_live') && trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') === 'rss.xml') {
        wp_safe_redirect(get_feed_link(), 301); exit;
    }
});
add_action('admin_menu', function () { add_management_page('Swingby Git Sync', 'Swingby Git Sync', 'manage_options', 'swingby-git', 'swingby_git_admin'); });
function swingby_git_admin() {
    if (!current_user_can('manage_options') || !current_user_can('edit_theme_options')) { return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('swingby_git_manage');
        if (($_POST['operation'] ?? '') === 'stage') {
            $request = new WP_REST_Request('POST'); $request->set_file_params($_FILES);
            $result = swingby_git_stage($request);
        } else { $result = swingby_git_lock(function () {
            $op = $_POST['operation'] ?? '';
            if ($op === 'settings') {
                update_option('swingby_git_auto_publish', isset($_POST['auto_publish']), false);
                return true;
            }
            if ($op === 'publish') { return swingby_git_publish(); }
            if ($op === 'rollback') { return swingby_git_rollback(); }
            return swingby_git_error('Unknown operation.');
        }); }
        echo '<div class="notice"><p>' . esc_html(is_wp_error($result) ? $result->get_error_message() : '処理が完了しました。') . '</p></div>';
    }
    $m = get_option('swingby_git_pending'); $live = get_option('swingby_git_live');
    echo '<div class="wrap"><h1>Swingby Git Sync</h1><p>Astro / TypeScript / Markdown / Tailwind をGitHubで編集します。push後はここで確認して公開してください。</p>';
    echo '<p>公開中: ' . esc_html($live['release'] ?? 'なし') . '</p>';
    echo '<p>管理画面で本文を変更しても表示用Astroスナップショットには反映されません。変更はGitHub側で行ってください。</p>';
    echo '<h2>初回のビルド取り込み</h2><form method="post" enctype="multipart/form-data">'; wp_nonce_field('swingby_git_manage');
    echo '<input type="hidden" name="operation" value="stage"><input type="file" name="bundle" accept=".zip" required><p>site.zip を選択します。通常の更新はGitHub Actionsが送信します。</p>';
    submit_button('ビルドを取り込む', 'secondary'); echo '</form>';
    echo '<form method="post">'; wp_nonce_field('swingby_git_manage');
    echo '<input type="hidden" name="operation" value="settings"><label><input type="checkbox" name="auto_publish" value="1" ' . checked(get_option('swingby_git_auto_publish'), true, false) . '> 次回以降、GitHubから届いたビルドを自動公開する</label><p>初期状態はオフです。有効にすると記事だけでなくページ・JavaScript・CSSもpushで公開されます。</p>';
    submit_button('更新方法を保存', 'secondary'); echo '</form>';
    if ($m) {
        echo '<h2>公開待ち: ' . esc_html($m['release']) . '</h2><ul>';
        foreach ($m['records'] as $r) {
            $preview = wp_nonce_url(admin_url('admin-post.php?action=swingby_git_preview&id=' . $r['id']), 'swingby_preview');
            echo '<li>' . esc_html($r['title'] . ' — ' . $r['path'] . ($r['draft'] ? '（下書き）' : '')) . ' <a target="_blank" rel="noopener" href="' . esc_url($preview) . '">プレビュー</a></li>';
        }
        echo '</ul><p>公開すると固定ページ・記事・デザインを一緒に反映し、ホームページを切り替えます。draft:true は公開しません。</p><form method="post">';
        wp_nonce_field('swingby_git_manage');
        echo '<input type="hidden" name="operation" value="publish">'; submit_button('このビルドを公開'); echo '</form>';
    } else { echo '<p>公開待ちのビルドはありません。GitHub Actionsから送信してください。</p>'; }
    if (get_option('swingby_git_backup')) {
        echo '<form method="post">'; wp_nonce_field('swingby_git_manage');
        echo '<input type="hidden" name="operation" value="rollback">'; submit_button('直前の公開状態に戻す', 'secondary'); echo '</form>';
    }
    echo '</div>';
}
add_action('admin_post_swingby_git_preview', function () {
    if (!current_user_can('manage_options') || !current_user_can('edit_theme_options')) { wp_die('Forbidden', '', array('response' => 403)); }
    check_admin_referer('swingby_preview');
    $id = absint($_GET['id'] ?? 0);
    foreach (get_option('swingby_git_pending', array())['records'] ?? array() as $r) {
        if ($r['id'] === $id) {
            nocache_headers(); header('X-Robots-Tag: noindex, nofollow'); header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="ja"><head>' . $r['head'] . '</head><body>' . $r['body'] . '</body></html>'; exit;
        }
    }
    wp_die('Preview not found', '', array('response' => 404));
});

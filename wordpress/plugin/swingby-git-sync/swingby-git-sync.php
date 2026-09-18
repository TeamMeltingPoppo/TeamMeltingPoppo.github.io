<?php
/**
 * Plugin Name: Swingby Git Sync
 * Description: Stage Astro builds from GitHub, then publish WordPress posts, pages and design together.
 * Version: 0.3.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/bidirectional.php';

function swingby_git_error($message, $status = 400) {
    return new WP_Error('swingby_sync', $message, array('status' => $status));
}
function swingby_git_allowed() {
    return is_ssl() && current_user_can('manage_options') && current_user_can('edit_theme_options');
}
add_action('rest_api_init', function () {
    register_rest_route('swingby-git/v1', '/deleted-posts', array(
        'methods' => 'GET', 'callback' => function () {
            $response = new WP_REST_Response(array('schema' => 1, 'posts' => swingby_git_deleted_posts()));
            $response->header('Cache-Control', 'no-store');
            return $response;
        },
        'permission_callback' => function () {
            return swingby_git_allowed() ? true : swingby_git_error('HTTPS and an administrator account are required.', 403);
        },
    ));
    register_rest_route('swingby-git/v1', '/stage', array(
        'methods' => 'POST', 'callback' => 'swingby_git_stage',
        'permission_callback' => function () {
            return swingby_git_allowed() ? true : swingby_git_error('HTTPS and an administrator account are required.', 403);
        },
    ));
});

// Keep permanent deletion records even after native post metadata has been removed.
add_action('before_delete_post', function ($id, $post) {
    $path = get_post_meta($id, '_swingby_path', true);
    if ($post->post_type !== 'post' || !$path) { return; }
    $deleted = get_option('swingby_git_deleted', array());
    $deleted[$path] = array('id' => (int)$id, 'path' => $path, 'type' => 'post', 'status' => 'deleted', 'sourcePath' => get_post_meta($id, '_swingby_source_path', true), 'sourceSha' => get_post_meta($id, '_swingby_source_sha', true));
    update_option('swingby_git_deleted', $deleted, false);
}, 10, 2);
function swingby_git_deleted_posts() {
    $deleted = get_option('swingby_git_deleted', array());
    foreach (array('swingby_git_live', 'swingby_git_pending') as $option) {
        foreach (get_option($option, array())['records'] ?? array() as $r) {
            if ($r['type'] !== 'post') { continue; }
            $status = get_post_status($r['id']);
            if ($status === 'trash' || $status === false) {
                $deleted[$r['path']] = array('id' => (int)$r['id'], 'path' => $r['path'], 'type' => 'post', 'status' => $status ?: 'deleted', 'sourcePath' => $r['sourcePath'] ?? null, 'sourceSha' => $r['sourceSha'] ?? null);
            }
        }
    }
    $live = get_option('swingby_git_live', array());
    $active = $live['sourcePaths'] ?? array_column($live['records'] ?? array(), 'path');
    foreach ($deleted as &$r) { $r['needsPublish'] = in_array($r['path'], $active, true); } unset($r);
    return array_values($deleted);
}
function swingby_git_reject_deleted($manifest) {
    $deleted = array_column(swingby_git_deleted_posts(), 'path');
    foreach ($manifest['records'] as $r) {
        if ($r['type'] === 'post' && in_array($r['path'], $deleted, true)) {
            return swingby_git_error('A source post was deleted in WordPress. Run GitHub sync to archive it before publishing.', 409);
        }
    }
    return true;
}

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
    foreach ($m['records'] as $r) {
        foreach (array('wordpressRawContent', 'wordpressExcerpt') as $field) {
            if (isset($r[$field]) && (!is_string($r[$field]) || strlen($r[$field]) > 2 * 1024 * 1024)) { return swingby_git_error('Invalid editor content.'); }
        }
        foreach (array('wordpressId', 'wordpressFeaturedMedia') as $field) {
            if (isset($r[$field]) && (!is_int($r[$field]) || $r[$field] < 0)) { return swingby_git_error('Invalid WordPress ID.'); }
        }
    }
    if (isset($m['settings']) && !swingby_git_settings_valid($m['settings'])) { return swingby_git_error('Invalid site settings.'); }
    $valid = swingby_git_check_conflicts($m);
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
        if (!$id && $r['type'] === 'post' && !empty($r['wordpressId'])) {
            $id = absint($r['wordpressId']);
            $existing = get_post_meta($id, '_swingby_key', true);
            if (get_post_type($id) !== 'post' || ($existing && $existing !== $key)) { return swingby_git_error('WordPress ID does not match the source.', 409); }
            update_post_meta($id, '_swingby_key', $key); update_post_meta($id, '_swingby_path', $r['path']);
        }
        if ($id && (get_post_status($id) === 'trash' || get_post_type($id) !== $r['type'])) { return swingby_git_error('A managed post is trashed or changed type. Resolve it before syncing.', 409); }
        if (!$id) {
            $id = wp_insert_post(wp_slash(array('post_type' => $r['type'], 'post_title' => $r['title'], 'post_status' => 'draft',
                'post_name' => 'swingby-' . substr($key, 0, 16), 'post_content' => wp_kses_post($r['content']),
                'meta_input' => array('_swingby_key' => $key, '_swingby_path' => $r['path']))), true);
            if (is_wp_error($id)) { return $id; }
        }
        $r['id'] = $id;
        if ($r['type'] === 'post') { $r['stagedNativeHash'] = swingby_git_state_hash(swingby_git_native_state($id)); }
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
    $valid = swingby_git_check_conflicts($m); if (is_wp_error($valid)) { return $valid; }
    if (get_stylesheet() !== 'swingby-astro') { return swingby_git_error('Activate the Swingby Astro Bridge theme first.'); }
    $prior = get_option('swingby_git_live', array());
    $backup = array('settings' => get_option('swingby_site_settings', null), 'live' => $prior, 'posts' => array(), 'show_on_front' => get_option('show_on_front'), 'page_on_front' => get_option('page_on_front'));
    foreach ($m['records'] as $r) {
        $p = get_post($r['id'], ARRAY_A);
        if (!$p || $p['post_status'] === 'trash') { return swingby_git_error('A staged post was deleted. Stage again.', 409); }
        if ($r['type'] === 'post' && isset($r['stagedNativeHash']) && $r['stagedNativeHash'] !== swingby_git_state_hash(swingby_git_native_state($r['id']))) { return swingby_git_error('Post changed after staging. Sync again.', 409); }
        $backup['posts'][] = array('ID' => $p['ID'], 'post_title' => $p['post_title'], 'post_content' => $p['post_content'],
            'post_excerpt' => $p['post_excerpt'], 'featured_media' => (int)get_post_thumbnail_id($p['ID']), 'post_status' => $p['post_status'], 'post_date' => $p['post_date'], 'post_date_gmt' => $p['post_date_gmt'],
            'tags' => wp_get_post_terms($p['ID'], 'post_tag', array('fields' => 'ids')),
            'categories' => wp_get_post_terms($p['ID'], 'category', array('fields' => 'ids')));
    }
    $obsolete = array(); $new_paths = array_column($m['records'], 'path');
    foreach ($prior['records'] ?? array() as $old) {
        if ($old['type'] !== 'page' || !preg_match('~^/(?:tags/[^/]+|blog/[0-9]+)/$~u', $old['path']) || in_array($old['path'], $new_paths, true)) { continue; }
        $p = get_post($old['id'], ARRAY_A); if (!$p || $p['post_status'] !== 'publish') { continue; }
        $obsolete[] = $p['ID'];
        $backup['posts'][] = array('ID' => $p['ID'], 'post_title' => $p['post_title'], 'post_content' => $p['post_content'], 'post_excerpt' => $p['post_excerpt'],
            'post_status' => $p['post_status'], 'post_date' => $p['post_date'], 'post_date_gmt' => $p['post_date_gmt'], 'tags' => array(), 'categories' => array());
    }
    update_option('swingby_git_backup', $backup, false);
    foreach ($obsolete as $id) { wp_update_post(array('ID' => $id, 'post_status' => 'draft')); }
    foreach ($m['records'] as $r) {
        $post = array('ID' => $r['id'], 'post_title' => $r['title'], 'post_content' => wp_kses_post($r['wordpressRawContent'] ?? $r['content']), 'post_status' => $r['draft'] ? (($r['wordpressStatus'] ?? '') === 'pending' ? 'pending' : 'draft') : 'publish');
        if (isset($r['date'])) {
            $post['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($r['date']));
            $post['post_date'] = get_date_from_gmt($post['post_date_gmt']);
            if (!$r['draft'] && $post['post_date_gmt'] > gmdate('Y-m-d H:i:s')) { $post['post_status'] = 'future'; }
        }
        if (isset($r['wordpressExcerpt'])) { $post['post_excerpt'] = wp_kses_post($r['wordpressExcerpt']); }
        $id = wp_update_post(wp_slash($post), true);
        if (is_wp_error($id)) { swingby_git_rollback(); return $id; }
        if (isset($r['wordpressFeaturedMedia'])) {
            if ($r['wordpressFeaturedMedia']) { set_post_thumbnail($id, absint($r['wordpressFeaturedMedia'])); } else { delete_post_thumbnail($id); }
        }
        if ($r['type'] === 'post') {
            foreach (array('tags' => 'post_tag', 'categories' => 'category') as $field => $taxonomy) {
                $result = wp_set_object_terms($id, $r[$field] ?? array(), $taxonomy);
                if (is_wp_error($result)) { swingby_git_rollback(); return $result; }
            }
        }
        if ($r['path'] === '/') { update_option('show_on_front', 'page'); update_option('page_on_front', $id); }
    }
    foreach ($m['records'] as &$record) {
        if ($record['type'] === 'post') { $record['nativeHash'] = swingby_git_state_hash(swingby_git_native_state($record['id']));
            $record['nativeContentHash'] = hash('sha256', get_post($record['id'])->post_content);
            update_post_meta($record['id'], '_swingby_source_path', $record['sourcePath'] ?? '');
            update_post_meta($record['id'], '_swingby_source_sha', $record['sourceSha'] ?? ''); }
    } unset($record);
    if (isset($m['settings'])) { update_option('swingby_site_settings', $m['settings'], false); }
    // Keep removed routes: deleting a source file never deletes a WordPress post.
    $m['sourcePaths'] = array_column($m['records'], 'path');
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
        $tags = $p['tags']; $categories = $p['categories']; $featured = $p['featured_media'] ?? 0; unset($p['tags'], $p['categories'], $p['featured_media']);
        $result = wp_update_post(wp_slash($p), true);
        if (is_wp_error($result)) { return $result; }
        if ($featured) { set_post_thumbnail($p['ID'], $featured); } else { delete_post_thumbnail($p['ID']); }
        if (get_post_type($p['ID']) === 'post') {
            wp_set_object_terms($p['ID'], array_map('intval', $tags), 'post_tag');
            wp_set_object_terms($p['ID'], array_map('intval', $categories), 'category');
        }
    }
    update_option('swingby_git_live', $backup['live'], false);
    if (array_key_exists('settings', $backup)) { update_option('swingby_site_settings', $backup['settings'], false); }
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
    if (!$post || $post->post_type !== 'page') { return $link; }
    $route = get_post_meta($post->ID, '_swingby_path', true);
    return $route && $post->post_status === 'publish' ? home_url($route) : $link;
}
// Posts use WordPress's configured date/ID permalink; pages retain their source routes.
add_filter('page_link', 'swingby_git_permalink', 10, 2);
function swingby_git_post_url($url) {
    $parts = wp_parse_url($url);
    if (!$parts || !isset($parts['path'])) { return $url; }
    if (isset($parts['host']) && strtolower($parts['host']) !== strtolower(wp_parse_url(home_url('/'), PHP_URL_HOST))) { return $url; }
    if (isset($parts['scheme']) && !in_array($parts['scheme'], array('http', 'https'), true)) { return $url; }
    $route = swingby_git_route_key($parts['path']);
    foreach (get_option('swingby_git_live', array())['records'] ?? array() as $r) {
        if ($r['type'] !== 'post' || swingby_git_route_key($r['path']) !== $route || get_post_status($r['id']) !== 'publish') { continue; }
        $link = get_permalink($r['id']);
        if (isset($parts['query'])) { $link .= (str_contains($link, '?') ? '&' : '?') . $parts['query']; }
        if (isset($parts['fragment'])) { $link .= '#' . $parts['fragment']; }
        return $link;
    }
    return $url;
}
function swingby_git_rewrite_links($html) {
    // Parse attributes, not arbitrary text/scripts or partial URL prefixes.
    $tags = new WP_HTML_Tag_Processor($html);
    while ($tags->next_tag()) {
        $attribute = $tags->get_tag() === 'META' ? 'content' : 'href';
        if ($attribute === 'content' && !in_array($tags->get_attribute('property') ?? $tags->get_attribute('name'), array('og:url', 'twitter:url'), true)) { continue; }
        $url = $tags->get_attribute($attribute);
        if (is_string($url)) {
            $rewritten = swingby_git_post_url($url);
            if ($rewritten !== $url) { $tags->set_attribute($attribute, $rewritten); }
        }
    }
    return $tags->get_updated_html();
}
add_filter('the_content', 'swingby_git_rewrite_links', 20);
function swingby_git_document() {
    if (is_404()) { $r = get_option('swingby_git_live', array())['notFound'] ?? null; if ($r) { $r['head'] = swingby_git_rewrite_links($r['head']); $r['body'] = swingby_git_rewrite_links($r['body']); } return $r; }
    if (!is_singular() || post_password_required()) { return null; }
    foreach (get_option('swingby_git_live', array())['records'] ?? array() as $r) {
        if ((int) $r['id'] === get_queried_object_id()) {
            $r['head'] = swingby_git_rewrite_links($r['head']);
            $r['body'] = swingby_git_rewrite_links($r['body']);
            return $r;
        }
    }
    return null;
}
add_action('template_redirect', function () {
    if (is_preview() || is_feed() || !is_singular('post')) { return; }
    $requested = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
    $target = swingby_git_post_url($requested);
    if ($target !== $requested) { wp_safe_redirect($target, 301); exit; }
}, 1);
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
    echo '<p>投稿の編集はGitHubへ同期され、次回ビルドで反映されます。固定ページ・配色は「Swingby サイト編集」を使ってください。</p>';
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

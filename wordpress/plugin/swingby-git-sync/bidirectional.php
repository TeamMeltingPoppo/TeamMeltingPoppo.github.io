<?php
if (!defined('ABSPATH')) { exit; }
function swingby_git_native_state($id) {
    $p = get_post($id);
    if (!$p) { return null; }
    $tags = wp_get_post_terms($id, 'post_tag', array('fields' => 'names'));
    $categories = wp_get_post_terms($id, 'category', array('fields' => 'names'));
    sort($tags); sort($categories);
    return array('title' => $p->post_title, 'content' => $p->post_content, 'date' => $p->post_date_gmt,
        'status' => $p->post_status, 'tags' => $tags, 'categories' => $categories, 'excerpt' => $p->post_excerpt,
        'featuredMedia' => (int)get_post_thumbnail_id($id), 'passwordProtected' => $p->post_password !== '');
}
function swingby_git_state_hash($state) { return hash('sha256', wp_json_encode($state)); }
function swingby_git_baseline($r) {
    if (isset($r['nativeHash'])) { return $r['nativeHash']; }
    // Upgrade from 0.1/0.2 without accepting pre-existing editor changes as synchronized.
    $tags = $r['tags'] ?? array(); $categories = $r['categories'] ?? array(); sort($tags); sort($categories);
    $date = isset($r['date']) ? gmdate('Y-m-d H:i:s', strtotime($r['date'])) : '';
    return swingby_git_state_hash(array('title' => $r['title'], 'content' => wp_kses_post($r['content']), 'date' => $date,
        'status' => $r['draft'] ? 'draft' : ($date > gmdate('Y-m-d H:i:s') ? 'future' : 'publish'),
        'tags' => $tags, 'categories' => $categories, 'excerpt' => '', 'featuredMedia' => 0, 'passwordProtected' => false));
}
function swingby_git_export_state() {
    $live = get_option('swingby_git_live', array()); $posts = array(); $managed = array();
    foreach ($live['records'] ?? array() as $r) {
        if ($r['type'] !== 'post') { continue; }
        $managed[(int)$r['id']] = $r;
    }
    foreach (get_posts(array('post_type' => 'post', 'post_status' => array('publish','future','draft','pending','private'), 'numberposts' => -1)) as $p) {
        $r = $managed[$p->ID] ?? null; $native = swingby_git_native_state($p->ID); $hash = swingby_git_state_hash($native);
        if ($r && swingby_git_baseline($r) === $hash) { continue; }
        // Private/password-protected text must never be exported into the public repository.
        if ($p->post_status === 'private' || $p->post_password !== '') { return swingby_git_error('A changed post is private/password-protected. Resolve its synchronization scope before syncing.', 409); }
        $posts[] = array('id' => $p->ID, 'path' => $r['path'] ?? '/blog/blog/wp-' . $p->ID . '/',
            'sourcePath' => $r['sourcePath'] ?? ($r ? null : 'content/blog/wp-' . $p->ID . '/index.md'),
            'sourceSha' => $r['sourceSha'] ?? null, 'revision' => $hash, 'contentChanged' => !$r || hash('sha256', $native['content']) !== ($r['nativeContentHash'] ?? hash('sha256', wp_kses_post($r['content']))), 'new' => !$r, 'data' => array_merge($native, array('featuredURL' => get_the_post_thumbnail_url($p->ID, 'full') ?: '')));
    }
    $settings = get_option('swingby_site_settings', $live['settings'] ?? null);
    $response = new WP_REST_Response(array('schema' => 1, 'requiresBootstrap' => empty($live['settingsSha']), 'posts' => $posts, 'deleted' => swingby_git_deleted_posts(),
        'settings' => $settings, 'settingsSha' => $live['settingsSha'] ?? null,
        'settingsChanged' => $settings && ($settings['fields'] ?? null) !== ($live['settings']['fields'] ?? null),
        'settingsRevision' => $settings ? swingby_git_state_hash($settings['fields']) : null));
    $response->header('Cache-Control', 'no-store'); return $response;
}
add_action('rest_api_init', function () {
    register_rest_route('swingby-git/v1', '/state', array('methods' => 'GET', 'callback' => 'swingby_git_export_state',
        'permission_callback' => function () { return swingby_git_allowed() ? true : swingby_git_error('Administrator required.', 403); }));
});
function swingby_git_check_conflicts($manifest) {
    $valid = swingby_git_reject_deleted($manifest); if (is_wp_error($valid)) { return $valid; }
    $live = get_option('swingby_git_live', array());
    foreach ($manifest['records'] as $next) {
        if ($next['type'] !== 'post') {
            foreach ($live['records'] ?? array() as $old) {
                if ($old['path'] !== $next['path']) { continue; }
                $p = get_post($old['id']);
                if ($p && ($p->post_title !== $old['title'] || $p->post_content !== wp_kses_post($old['content']))) {
                    return swingby_git_error('A generated page was edited directly. Preserve that edit and use Swingby Site Editor for synchronized page fields.', 409);
                }
            }
            continue;
        }
        foreach ($live['records'] ?? array() as $old) {
            if ($old['path'] !== $next['path']) { continue; }
            $current = swingby_git_state_hash(swingby_git_native_state($old['id']));
            if ($current !== swingby_git_baseline($old) && $current !== ($next['wordpressRevision'] ?? '')) {
                return swingby_git_error('WordPress changed since the last sync. Pull its changes before publishing.', 409);
            }
        }
        if (!empty($next['wordpressId']) && get_post($next['wordpressId'])) {
            $current = swingby_git_state_hash(swingby_git_native_state($next['wordpressId']));
            $existing = get_post_meta($next['wordpressId'], '_swingby_path', true);
            if (!$existing && $current !== ($next['wordpressRevision'] ?? '')) { return swingby_git_error('New WordPress post changed during sync.', 409); }
        }
    }
    $settings = get_option('swingby_site_settings', $live['settings'] ?? null);
    if ($settings && $settings['fields'] !== ($live['settings']['fields'] ?? null)
        && swingby_git_state_hash($settings['fields']) !== ($manifest['settings']['wordpressRevision'] ?? '')) {
        return swingby_git_error('Site settings changed during sync. Pull them before publishing.', 409);
    }
    return true;
}
function swingby_git_settings_valid($settings) {
    if (!is_array($settings) || ($settings['schema'] ?? null) !== 1 || !is_array($settings['fields'] ?? null) || count($settings['fields']) > 100) { return false; }
    foreach ($settings['fields'] as $key => $f) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key) || !is_array($f) || !is_string($f['label'] ?? null) || strlen($f['label']) > 200) { return false; }
        $v = $f['value'] ?? null; $type = $f['type'] ?? '';
        if (!in_array($type, array('text','textarea','color','url','number'), true)) { return false; }
        if ($type === 'number') { if (!is_numeric($v) || $v < ($f['min'] ?? 0) || $v > ($f['max'] ?? 2000)) { return false; } }
        elseif (!is_string($v) || strlen($v) > 20000) { return false; }
        elseif ($type === 'color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) { return false; }
        elseif ($type === 'url' && $v !== '' && !preg_match('~^https?://[^\s]+$~', $v)) { return false; }
    }
    return true;
}
add_action('admin_menu', function () {
    add_management_page('Swingby サイト編集', 'Swingby サイト編集', 'manage_options', 'swingby-site-editor', 'swingby_git_settings_admin');
});
function swingby_git_settings_admin() {
    if (!current_user_can('manage_options') || !current_user_can('edit_theme_options')) { return; }
    $settings = get_option('swingby_site_settings', get_option('swingby_git_live', array())['settings'] ?? null);
    echo '<div class="wrap"><h1>Swingby サイト編集</h1><p>固定ページの文章・リンク・配色・本文サイズを編集できます。即時同期を設定すると、保存直後にGitHubとの同期を開始します。記事は通常の「投稿」で編集します。</p>';
    if (!$settings) { echo '<p>GitHubから新しいビルドを一度取り込むと、編集項目が表示されます。</p></div>'; return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('swingby_site_settings'); $next = $settings;
        foreach ($next['fields'] as $key => &$f) {
            $value = wp_unslash($_POST['fields'][$key] ?? '');
            if (!is_string($value)) { $value = ''; }
            $f['value'] = $f['type'] === 'number' ? (int)$value : ($f['type'] === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value));
        } unset($f);
        if (swingby_git_settings_valid($next)) {
            $saved = swingby_git_lock(function () use ($next) { update_option('swingby_site_settings', $next, false); return true; });
            if (!is_wp_error($saved)) { $settings = $next; echo '<div class="notice notice-success"><p>保存しました。即時同期を設定済みの場合は、この保存を合図に同期を開始します。</p></div>'; }
            else { echo '<div class="notice notice-error"><p>同期処理中です。少し待って保存し直してください。</p></div>'; }
        } else { echo '<div class="notice notice-error"><p>色・URL・数値の範囲を確認してください。変更は保存していません。</p></div>'; }
    }
    echo '<form method="post">'; wp_nonce_field('swingby_site_settings');
    foreach ($settings['fields'] as $key => $f) {
        echo '<p><label for="field-' . esc_attr($key) . '"><strong>' . esc_html($f['label']) . '</strong></label><br>';
        $attrs = ' id="field-' . esc_attr($key) . '" name="fields[' . esc_attr($key) . ']"';
        if ($f['type'] === 'textarea') { echo '<textarea class="large-text" rows="5"' . $attrs . '>' . esc_textarea($f['value']) . '</textarea>'; }
        else { echo '<input class="regular-text" type="' . esc_attr($f['type']) . '"' . $attrs . ' value="' . esc_attr($f['value']) . '"' . ($f['type'] === 'number' ? ' min="' . (int)$f['min'] . '" max="' . (int)$f['max'] . '"' : '') . '>'; }
        echo '</p>';
    }
    submit_button('変更を保存'); echo '</form></div>';
}

add_action('admin_notices', function () {
    $screen = get_current_screen(); $id = absint($_GET['post'] ?? 0);
    if ($screen && $screen->base === 'post' && get_post_type($id) === 'page' && get_post_meta($id, '_swingby_path', true)) {
        echo '<div class="notice notice-warning"><p>この固定ページはAstroで生成しています。同期できる文章・配色の編集は <a href="' . esc_url(admin_url('tools.php?page=swingby-site-editor')) . '">Swingby サイト編集</a> を使ってください。この画面での直接変更は上書きを防ぐため同期を停止します。</p></div>';
    }
});

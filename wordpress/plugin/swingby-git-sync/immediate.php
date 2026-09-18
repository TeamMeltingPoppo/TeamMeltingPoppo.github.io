<?php
if (!defined('ABSPATH')) { exit; }

function swingby_git_without_dispatch($callback) {
    $prior = $GLOBALS['swingby_git_importing'] ?? false;
    $GLOBALS['swingby_git_importing'] = true;
    try { return $callback(); } finally { $GLOBALS['swingby_git_importing'] = $prior; }
}
function swingby_git_dispatch_token() {
    if (defined('SWINGBY_GITHUB_DISPATCH_TOKEN')) { return (string)SWINGBY_GITHUB_DISPATCH_TOKEN; }
    $saved = get_option('swingby_git_dispatch_credential');
    if (!$saved || !function_exists('openssl_decrypt')) { return ''; }
    $iv = base64_decode($saved['iv'] ?? '', true); $tag = base64_decode($saved['tag'] ?? '', true); $data = base64_decode($saved['data'] ?? '', true);
    if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $data === false) { return ''; }
    return openssl_decrypt($data, 'aes-256-gcm', hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true), OPENSSL_RAW_DATA, $iv, $tag) ?: '';
}
function swingby_git_dispatch_store_token($token) {
    if (!is_string($token) || !preg_match('/^github_pat_[A-Za-z0-9_]{30,480}$/D', $token)) { return swingby_git_error('Fine-grained personal access token required.'); }
    if (!function_exists('openssl_encrypt')) { return swingby_git_error('OpenSSL is required to store the token securely.'); }
    $iv = random_bytes(12); $tag = '';
    $data = openssl_encrypt($token, 'aes-256-gcm', hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($data === false) { return swingby_git_error('Token encryption failed.'); }
    update_option('swingby_git_dispatch_credential', array('iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($data)), false);
    return true;
}
function swingby_git_dispatch_mark($reason = 'post') {
    if (!empty($GLOBALS['swingby_git_importing'])) { return; }
    // A REST save can update the post, terms and thumbnail in the same request.
    // Dispatch once at shutdown, after all of those writes have completed.
    if (!empty($GLOBALS['swingby_git_dispatch_marked'])) { return; }
    $GLOBALS['swingby_git_dispatch_marked'] = true;
    update_option('swingby_git_dispatch_pending', array('id' => wp_generate_uuid4(), 'reason' => $reason, 'created' => time(), 'attempts' => 0), false);
}
function swingby_git_dispatch_post_changed($id, $post) {
    if (!$post || $post->post_type !== 'post' || in_array($post->post_status, array('auto-draft', 'inherit'), true)
        || wp_is_post_revision($id) || wp_is_post_autosave($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) { return; }
    swingby_git_dispatch_mark('post');
}
add_action('wp_after_insert_post', 'swingby_git_dispatch_post_changed', 30, 2);
add_action('deleted_post', 'swingby_git_dispatch_post_changed', 30, 2);
foreach (array('added_post_meta', 'updated_post_meta', 'deleted_post_meta') as $hook) {
    add_action($hook, function ($meta_id, $id, $key) {
        if ($key === '_thumbnail_id') { swingby_git_dispatch_post_changed($id, get_post($id)); }
    }, 30, 3);
}
add_action('set_object_terms', function ($id, $terms, $tt_ids, $taxonomy) {
    if (in_array($taxonomy, array('category', 'post_tag'), true)) { swingby_git_dispatch_post_changed($id, get_post($id)); }
}, 30, 4);
foreach (array('edited_term', 'delete_term') as $hook) {
    add_action($hook, function ($id, $tt_id, $taxonomy) {
        if (in_array($taxonomy, array('category', 'post_tag'), true)) { swingby_git_dispatch_mark('taxonomy'); }
    }, 30, 3);
}
add_action('update_option_swingby_site_settings', function ($old, $next) {
    if (($old['fields'] ?? null) !== ($next['fields'] ?? null)) { swingby_git_dispatch_mark('site-settings'); }
}, 30, 2);

// Compare-and-delete so a concurrent request's newer change is never erased.
function swingby_git_dispatch_delete_matching($name, $value) {
    global $wpdb;
    $deleted = $wpdb->delete($wpdb->options, array('option_name' => $name, 'option_value' => maybe_serialize($value)));
    wp_cache_delete($name, 'options');
    return $deleted;
}
function swingby_git_dispatch_retry($delay = 60) {
    if (!wp_next_scheduled('swingby_git_dispatch_retry')) { wp_schedule_single_event(time() + $delay, 'swingby_git_dispatch_retry'); }
}
function swingby_git_dispatch_flush() {
    $pending = get_option('swingby_git_dispatch_pending');
    if (!$pending || !empty($GLOBALS['swingby_git_importing'])) { return false; }
    $token = swingby_git_dispatch_token();
    if (!$token) {
        update_option('swingby_git_dispatch_status', array('state' => 'not-configured', 'time' => time()), false);
        return false;
    }
    $lock_name = 'swingby_git_dispatch_lock'; $old_lock = get_option($lock_name);
    if ($old_lock && ($old_lock['time'] ?? 0) < time() - 120) { swingby_git_dispatch_delete_matching($lock_name, $old_lock); }
    $lock = array('id' => wp_generate_uuid4(), 'time' => time());
    if (!add_option($lock_name, $lock, '', false)) { swingby_git_dispatch_retry(15); return false; }
    try {
        $response = wp_remote_post('https://api.github.com/repos/TeamMeltingPoppo/TeamMeltingPoppo.github.io/actions/workflows/wordpress.yml/dispatches', array(
            'timeout' => 8, 'redirection' => 0, 'sslverify' => true,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/vnd.github+json',
                'Content-Type' => 'application/json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Swingby-Git-Sync/0.4.0'),
            'body' => wp_json_encode(array('ref' => 'main', 'inputs' => array('source' => 'wordpress'))),
        ));
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $accepted = in_array($code, array(200, 204), true);
        // Never store raw API errors, request headers or the token in diagnostics.
        update_option('swingby_git_dispatch_status', array('state' => $accepted ? 'accepted' : 'failed', 'code' => $code, 'time' => time()), false);
        if ($accepted) {
            swingby_git_dispatch_delete_matching('swingby_git_dispatch_pending', $pending);
            wp_clear_scheduled_hook('swingby_git_dispatch_retry');
        } else {
            swingby_git_dispatch_retry(60);
        }
        return $accepted;
    } finally {
        swingby_git_dispatch_delete_matching($lock_name, $lock);
        if (get_option('swingby_git_dispatch_pending')) { swingby_git_dispatch_retry(15); }
    }
}
add_action('shutdown', function () {
    if (!empty($GLOBALS['swingby_git_dispatch_marked'])) { swingby_git_dispatch_flush(); }
}, 1000);
add_action('swingby_git_dispatch_retry', 'swingby_git_dispatch_flush');

add_action('admin_menu', function () {
    add_management_page('Swingby 即時同期', 'Swingby 即時同期', 'manage_options', 'swingby-immediate-sync', 'swingby_git_dispatch_admin');
});
function swingby_git_dispatch_admin() {
    if (!current_user_can('manage_options') || !current_user_can('edit_theme_options')) { return; }
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('swingby_git_dispatch_settings');
        $op = $_POST['operation'] ?? '';
        if ($op === 'disconnect') {
            if (defined('SWINGBY_GITHUB_DISPATCH_TOKEN')) { $message = 'wp-config.phpで設定したキーを解除してください。'; }
            else { delete_option('swingby_git_dispatch_credential'); wp_clear_scheduled_hook('swingby_git_dispatch_retry'); $message = '即時起動用キーを解除しました。'; }
        } elseif ($op === 'connect' || $op === 'retry') {
            $saved = true;
            if ($op === 'connect') {
                $token_input = wp_unslash($_POST['token'] ?? '');
                $saved = swingby_git_dispatch_store_token(is_string($token_input) ? trim($token_input) : '');
            }
            if (is_wp_error($saved)) { $message = 'キーを保存できませんでした。Fine-grained tokenの形式とPHPのOpenSSL設定を確認してください。'; }
            else {
                swingby_git_dispatch_mark('connection-test');
                $message = swingby_git_dispatch_flush() ? 'GitHubが同期開始の要求を受け付けました。完了結果はActionsで確認できます。' : '同期開始に失敗しました。下の状態とGitHubキーの権限・有効期限を確認してください。';
                $GLOBALS['swingby_git_dispatch_marked'] = false;
            }
        }
    }
    $status = get_option('swingby_git_dispatch_status', array());
    $status_label = array('accepted' => 'GitHubに起動を依頼済み', 'failed' => '起動に失敗', 'not-configured' => 'キー未設定')[$status['state'] ?? ''] ?? '未実行';
    echo '<div class="wrap"><h1>Swingby 即時同期</h1><p>投稿の保存・公開・削除・復元、タグやカテゴリーの変更、Swingby サイト編集の保存直後にGitHub Actionsを起動します。GitHubへの保存と公開の完了には実行待ち・ビルドの時間がかかります。</p>';
    if ($message) { echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>'; }
    echo '<p>対象: TeamMeltingPoppo/TeamMeltingPoppo.github.io（main）</p>';
    echo '<p>キー: ' . (swingby_git_dispatch_token() ? '設定済み' : '未設定') . ' ／ 最終状態: ' . esc_html($status_label) . (isset($status['code']) ? '（HTTP ' . (int)$status['code'] . '）' : '') . '</p>';
    echo '<p>GitHubのFine-grained personal access tokenを作成し、Resource ownerをTeamMeltingPoppo、対象リポジトリをこのリポジトリだけ、Repository permissionsのActionsをRead and writeにしてください。Contentsの書き込み権限は不要です。</p>';
    echo '<p><a href="https://github.com/settings/personal-access-tokens/new?name=Swingby%20immediate%20sync&amp;target_name=TeamMeltingPoppo&amp;actions=write" target="_blank" rel="noopener noreferrer">GitHubで専用キーを作成</a> ／ <a href="https://github.com/TeamMeltingPoppo/TeamMeltingPoppo.github.io/actions/workflows/wordpress.yml" target="_blank" rel="noopener noreferrer">同期結果を確認</a></p>';
    echo '<form method="post">'; wp_nonce_field('swingby_git_dispatch_settings');
    echo '<input type="hidden" name="operation" value="connect"><label>専用キー <input type="password" name="token" class="large-text" autocomplete="new-password" required></label><p>キーは暗号化してWordPressに保存します。GitHubリポジトリや画面には表示しません。</p>';
    submit_button('キーを保存して接続テスト'); echo '</form>';
    foreach (array('retry' => '今すぐ同期を開始', 'disconnect' => '保存したキーを解除') as $op => $label) {
        echo '<form method="post">'; wp_nonce_field('swingby_git_dispatch_settings');
        echo '<input type="hidden" name="operation" value="' . esc_attr($op) . '">'; submit_button($label, 'secondary'); echo '</form>';
    }
    echo '</div>';
}

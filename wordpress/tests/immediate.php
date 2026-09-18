<?php
$root = getenv('SWINGBY_TEST_WP_ROOT');
if (!$root || !file_exists($root . '/.swingby-disposable-test')) { throw new Exception('Disposable WordPress marker missing'); }
define('WP_INSTALLING', true);
define('DISABLE_WP_CRON', true);
$_SERVER['HTTP_HOST'] = 'melting-poppo.com'; $_SERVER['HTTPS'] = 'on';
require $root . '/wp-load.php';
require __DIR__ . '/../plugin/swingby-git-sync/swingby-git-sync.php';
function verify($condition, $label) { if (!$condition) { throw new Exception($label); } echo "PASS: $label\n"; }
function next_request() { $GLOBALS['swingby_git_dispatch_marked'] = false; delete_option('swingby_git_dispatch_pending'); wp_clear_scheduled_hook('swingby_git_dispatch_retry'); }
$token = 'github_pat_' . str_repeat('x', 60);
verify(swingby_git_dispatch_store_token($token) === true, 'Dedicated token stored');
verify(swingby_git_dispatch_token() === $token, 'Encrypted token round-trip');
verify(!str_contains(wp_json_encode(get_option('swingby_git_dispatch_credential')), $token), 'No plaintext token in stored option');
$calls = 0; $mode = 'success'; $new_pending = null;
add_filter('pre_http_request', function ($preempt, $args, $url) use (&$calls, &$mode, &$new_pending, $token) {
    verify($url === 'https://api.github.com/repos/TeamMeltingPoppo/TeamMeltingPoppo.github.io/actions/workflows/wordpress.yml/dispatches', 'Dispatch is restricted to the configured repository');
    verify($args['redirection'] === 0 && $args['sslverify'] === true, 'Dispatch credentials never follow redirects');
    verify($args['headers']['Authorization'] === 'Bearer ' . $token, 'Dedicated credential is used');
    verify(json_decode($args['body'], true) === array('ref' => 'main', 'inputs' => array('source' => 'wordpress')), 'Workflow receives the WordPress event');
    $calls++;
    if ($mode === 'concurrent') {
        $new_pending = array('id' => wp_generate_uuid4(), 'reason' => 'post', 'created' => time(), 'attempts' => 0);
        update_option('swingby_git_dispatch_pending', $new_pending, false);
    }
    return array('headers' => array(), 'body' => '', 'response' => array('code' => $mode === 'failure' ? 503 : 204, 'message' => 'Mocked'));
}, 10, 3);
next_request();
$id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Event test', 'post_content' => '<p>Test</p>'));
verify((bool)get_option('swingby_git_dispatch_pending'), 'Post save queues immediate dispatch');
wp_set_post_tags($id, array('event-test')); update_post_meta($id, '_thumbnail_id', 123);
verify($calls === 0, 'Wait until the save request finishes before starting GitHub');
verify(swingby_git_dispatch_flush() && $calls === 1, 'One save with tags and thumbnail dispatches only once');
verify(!get_option('swingby_git_dispatch_pending'), 'Accepted dispatch clears its pending event');
next_request();
swingby_git_without_dispatch(function () use ($id) {
    wp_update_post(array('ID' => $id, 'post_title' => 'Imported'));
    wp_set_post_tags($id, array('from-github')); update_post_meta($id, '_thumbnail_id', 456);
    update_option('swingby_site_settings', array('fields' => array('title' => array('value' => 'Imported'))), false);
});
verify(!get_option('swingby_git_dispatch_pending'), 'Imports and settings imports do not trigger a sync loop');
next_request();
wp_update_post(array('ID' => $id, 'post_status' => 'publish'));
verify((bool)get_option('swingby_git_dispatch_pending'), 'Publish triggers dispatch');
next_request(); wp_trash_post($id);
verify((bool)get_option('swingby_git_dispatch_pending'), 'Trash triggers dispatch');
next_request(); wp_untrash_post($id);
verify((bool)get_option('swingby_git_dispatch_pending'), 'Restore triggers dispatch');
next_request(); wp_delete_post($id, true);
verify((bool)get_option('swingby_git_dispatch_pending'), 'Permanent deletion triggers dispatch');
next_request();
wp_insert_post(array('post_type' => 'post', 'post_status' => 'auto-draft', 'post_title' => 'Untitled'));
verify(!get_option('swingby_git_dispatch_pending'), 'Opening a blank editor does not trigger dispatch');
next_request();
wp_insert_post(array('post_type' => 'revision', 'post_status' => 'inherit', 'post_title' => 'Autosave'));
verify(!get_option('swingby_git_dispatch_pending'), 'Autosave revisions do not trigger dispatch');
next_request();
swingby_git_lock(fn() => update_option('swingby_site_settings', array('fields' => array('title' => array('value' => 'Editor change'))), false));
verify((bool)get_option('swingby_git_dispatch_pending'), 'Site editor save triggers dispatch even inside its write lock');
$mode = 'failure'; $before = get_option('swingby_git_dispatch_pending');
verify(!swingby_git_dispatch_flush(), 'HTTP failure is reported');
verify(get_option('swingby_git_dispatch_pending') === $before && wp_next_scheduled('swingby_git_dispatch_retry'), 'Failed event retained and automatic retry scheduled');
$mode = 'concurrent'; verify(swingby_git_dispatch_flush(), 'Concurrent request test accepted');
verify(get_option('swingby_git_dispatch_pending') === $new_pending, 'Accepted request cannot erase a newer concurrent change');
$mode = 'success';
add_option('swingby_git_dispatch_lock', array('id' => 'another-request', 'time' => time()), '', false); $count = $calls;
verify(!swingby_git_dispatch_flush() && $calls === $count, 'Concurrent dispatch lock avoids duplicate requests');
delete_option('swingby_git_dispatch_lock');
verify(swingby_git_dispatch_flush() && !get_option('swingby_git_dispatch_pending'), 'Retry eventually drains the remaining change');
next_request(); delete_option('swingby_git_dispatch_credential'); swingby_git_dispatch_mark('post'); $count = $calls;
verify(!swingby_git_dispatch_flush() && $calls === $count, 'Missing credentials never call GitHub');
verify(get_option('swingby_git_dispatch_status')['state'] === 'not-configured', 'Missing credentials visible to administrator');
next_request();
echo "Immediate dispatch tests passed. No external HTTP calls were made.\n";

<?php
// Run only against a disposable WordPress installation (SQLite is sufficient).
$root = getenv('SWINGBY_TEST_WP_ROOT');
if (!$root || !file_exists($root . '/.swingby-disposable-test')) { throw new Exception('Disposable WordPress marker missing'); }
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = 'melting-poppo.com'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['HTTPS'] = 'on';
require $root . '/wp-load.php';
add_filter('pre_wp_mail', '__return_true');
if (!is_blog_installed()) {
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install('Swingby Test', 'sync-test', 'test@example.invalid', false, '', bin2hex(random_bytes(24)));
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once __DIR__ . '/../plugin/swingby-git-sync/swingby-git-sync.php';
function check($condition, $label) { if (!$condition) { throw new Exception($label); } echo "PASS: $label\n"; }
function import_bundle($path) {
    $z = new ZipArchive(); if ($z->open($path) !== true) { throw new Exception('ZIP open failed'); }
    try { return swingby_git_import_zip($z); } finally { $z->close(); }
}
$admin = get_user_by('login', 'sync-test'); wp_set_current_user($admin->ID);
update_option('home', 'https://melting-poppo.com'); update_option('siteurl', 'https://melting-poppo.com');
check(swingby_git_allowed(), 'Administrator permission');
wp_set_current_user(0); check(!swingby_git_allowed(), 'Anonymous denied'); wp_set_current_user($admin->ID);
$bundle = __DIR__ . '/../../wordpress-build/site.zip';
$result = import_bundle($bundle);
check(!is_wp_error($result), 'Import original Astro build: ' . (is_wp_error($result) ? $result->get_error_message() : 'ok'));
$m = get_option('swingby_git_pending');
check(count($m['records']) === 10, 'Ten WordPress records');
$ids = array_column($m['records'], 'id');
foreach ($ids as $id) { check(get_post_status($id) === 'draft', 'Initial record stays draft: ' . $id); }
$article = array_values(array_filter($m['records'], fn($r) => $r['path'] === '/blog/blog/swingbytshirt/'))[0];
check(count($article['categories']) === 1, 'Original categories retained');
foreach ($m['records'] as $r) {
    check(!str_contains($r['head'] . $r['body'], 'comhttps://'), 'No duplicated asset origins: ' . $r['path']);
    check(!preg_match('~["\x27](/_astro/|https://melting-poppo.com/_astro/)~', $r['head'] . $r['body']), 'All asset references remapped: ' . $r['path']);
}
$media_count = (int)wp_count_posts('attachment')->inherit;
check($media_count > 40, 'Images registered in media library');
$result = import_bundle($bundle);
check(!is_wp_error($result), 'Repeated push succeeds');
check($ids === array_column(get_option('swingby_git_pending')['records'], 'id'), 'Repeated push keeps WordPress IDs');
check((int)wp_count_posts('attachment')->inherit === $media_count, 'Repeated push does not duplicate images');
switch_theme('swingby-astro');
check(!is_wp_error(swingby_git_publish()), 'Publish complete build');
check(!get_option('swingby_git_pending'), 'Pending cleared after publish');
foreach ($ids as $id) { check(get_post_status($id) === 'publish', 'Record published: ' . $id); }
check(get_option('show_on_front') === 'page', 'Home page selected');
check(str_contains(get_option('swingby_git_live')['notFound']['body'], '404'), 'Original 404 design retained');
update_option('permalink_structure', '/blog/%year%%monthnum%%day%/%post_id%');
$GLOBALS['wp_rewrite']->init();
$expected = home_url('/blog/' . get_the_date('Ymd', $article['id']) . '/' . $article['id']);
check(get_permalink($article['id']) === $expected, 'Native date/ID permalink respected');
$GLOBALS['wp_rewrite']->flush_rules(false);
$_SERVER['REQUEST_URI'] = wp_parse_url($expected, PHP_URL_PATH);
$_SERVER['PHP_SELF'] = '/index.php';
$native_request = new WP(); $native_request->parse_request();
check((int)($native_request->query_vars['p'] ?? 0) === $article['id'], 'Native date/ID request resolves through WordPress rewrite rules');
$_SERVER['REQUEST_URI'] = '/';
check(swingby_git_post_url($article['path']) === $expected, 'Legacy route redirects to native permalink');
check(swingby_git_post_url(rtrim($article['path'], '/') . '?test=1#part') === $expected . '?test=1#part', 'Query and fragment retained');
check(swingby_git_post_url('https://other.example' . $article['path']) === 'https://other.example' . $article['path'], 'External links unchanged');
check(swingby_git_post_url($article['path'] . 'extra/') === $article['path'] . 'extra/', 'No partial path replacement');
$rewritten = swingby_git_rewrite_links('<a href="' . $article['path'] . '">Read</a><meta property="og:url" content="https://melting-poppo.com' . $article['path'] . '">');
check(substr_count($rewritten, $expected) === 2, 'Card links and social metadata use native permalink');
check(get_permalink((int)get_option('page_on_front')) === home_url('/'), 'Home page route unchanged');
check(has_term('Apparel', 'category', $article['id']), 'Native WordPress categories');
check(has_term('Tシャツ', 'post_tag', $article['id']), 'Native WordPress tags');
wp($article['path']);
// Exercise request routing with a real WP_Query.
$wp = new WP(); $wp->request = trim($article['path'], '/'); $wp->query_vars = array(); do_action_ref_array('parse_request', array(&$wp));
check(($wp->query_vars['p'] ?? 0) === $article['id'], 'Article route resolves to post ID');
$GLOBALS['wp_query'] = new WP_Query($wp->query_vars);
check(swingby_git_document()['id'] === $article['id'], 'Theme receives the correct live document');
ob_start(); include __DIR__ . '/../theme/swingby-astro/index.php'; $html = ob_get_clean();
check(str_contains($html, $expected), 'Rendered article includes native canonical URL');
check(str_contains($html, 'article-content') && str_contains($html, 'Swingby'), 'Theme renders original article HTML');
check(!is_wp_error(swingby_git_rollback()), 'Rollback succeeds');
check(!get_option('swingby_git_live'), 'Rollback restores original live state');
foreach ($ids as $id) { check(get_post_status($id) === 'draft', 'Rollback restores draft: ' . $id); }
// Previewing a new build must not modify already-published content.
import_bundle($bundle); swingby_git_publish(); $old_title = get_the_title($article['id']);
import_bundle($bundle); $pending = get_option('swingby_git_pending');
foreach ($pending['records'] as &$r) { if ($r['id'] === $article['id']) { $r['title'] = 'Updated'; $r['draft'] = true; $r['date'] = '2099-01-01T00:00:00Z'; } } unset($r);
update_option('swingby_git_pending', $pending, false);
check(get_the_title($article['id']) === $old_title, 'Staging does not change public content');
check(!is_wp_error(swingby_git_publish()), 'Publish new draft state');
check(get_post_status($article['id']) === 'draft', 'Future-dated draft never becomes scheduled/public');
check(!is_wp_error(swingby_git_rollback()), 'Rollback to prior published release');
check(get_the_title($article['id']) === $old_title && get_post_status($article['id']) === 'publish', 'Rollback restores prior title and publication');
// A malicious ZIP cannot write a PHP file or replace public content.
$tmp = tempnam(sys_get_temp_dir(), 'swingby-bad'); copy($bundle, $tmp);
$z = new ZipArchive(); $z->open($tmp); $z->addFromString('assets/shell.php', '<?php exit;'); $z->close();
check(is_wp_error(import_bundle($tmp)), 'Reject unexpected PHP ZIP entry'); unlink($tmp);
update_option('swingby_git_auto_publish', true, false);
check((import_bundle($bundle)['status'] ?? '') === 'published', 'Explicit opt-in enables automatic publication');
echo "Integration tests passed.\n";

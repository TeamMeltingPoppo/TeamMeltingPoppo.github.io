<?php
// Fast validation tests; no WordPress or network required.
define('ABSPATH', __DIR__);
class WP_Error { public function __construct(public $code, public $message, public $data = array()) {} }
function add_action(...$args) {} function add_filter(...$args) {}
function home_url($path = '/') { return 'https://melting-poppo.com' . $path; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function is_wp_error($v) { return $v instanceof WP_Error; }
require __DIR__ . '/../plugin/swingby-git-sync/swingby-git-sync.php';
function expect($condition, $label) { if (!$condition) { throw new Exception($label); } }
$m = json_decode(file_get_contents(__DIR__ . '/../../wordpress-build/bundle/manifest.json'), true);
expect(swingby_git_validate_manifest($m) === true, 'Real Astro manifest must validate');
foreach (array('../evil.js', '/evil.js', '.htaccess', 'ok/../../evil.js', 'evil.php', 'evil.phtml', 'evil.php5', 'evil\\path.js', 'x:evil.js') as $path) {
    expect(!swingby_git_safe_asset($path), 'Reject unsafe asset: ' . $path);
}
foreach (array('/wp-admin/', '/wp-json/', '/wp-content/', '/%2e%2e/', '//evil/', '/foo?bar/', '/foo%00/') as $path) {
    expect(!swingby_git_safe_route($path), 'Reject unsafe route: ' . $path);
}
expect((bool)swingby_git_safe_asset('_astro/背面デザイン.webp'), 'Unicode images supported');
expect((bool)swingby_git_safe_route('/tags/鳥人間コンテスト/'), 'Unicode routes supported');
$bad = $m; $bad['records'][] = $m['records'][0]; expect(is_wp_error(swingby_git_validate_manifest($bad)), 'Reject duplicate routes');
$bad = $m; $bad['site'] = 'https://other.example'; expect(is_wp_error(swingby_git_validate_manifest($bad)), 'Reject wrong origin');
$bad = $m; $bad['records'][0]['draft'] = 'false'; expect(is_wp_error(swingby_git_validate_manifest($bad)), 'Draft must be boolean');
$bad = $m; $bad['assets']['evil.php'] = array('sha256'=>str_repeat('a',64),'size'=>1); expect(is_wp_error(swingby_git_validate_manifest($bad)), 'Reject PHP upload');
$zip = new ZipArchive(); expect($zip->open(__DIR__ . '/../../wordpress-build/site.zip') === true, 'ZIP opens');
foreach ($m['assets'] as $name => $asset) {
    $bytes = $zip->getFromName('assets/' . $name);
    expect(hash('sha256', $bytes) === $asset['sha256'], 'Checksum: ' . $name);
    expect(strlen($bytes) === $asset['size'], 'Size: ' . $name);
}
$zip->close();
echo 'PASS: real manifest, ' . count($m['assets']) . " asset checksums, unsafe paths, PHP payload rejection, duplicate routes and types\n";

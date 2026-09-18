<?php
if (!defined('ABSPATH')) { exit; }
$document = function_exists('swingby_git_document') ? swingby_git_document() : null;
if ($document) {
    // These documents are trusted build output, accepted only from an administrator
    // using an Application Password. Never execute PHP or eval archive contents.
    ?><!doctype html><html lang="ja"><head><?php echo $document['head']; wp_head(); ?></head><body <?php body_class(); ?>><?php
    wp_body_open();
    echo $document['body'];
    wp_footer();
    ?></body></html><?php
} else {
    ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html(wp_get_document_title()); ?></title><?php wp_head(); ?></head><body <?php body_class(); ?>><?php wp_body_open(); ?>
    <header><a href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a></header><main><?php
    if (have_posts()) { while (have_posts()) { the_post(); the_title('<h1>', '</h1>'); the_content(); } }
    else { ?><h1>ページが見つかりません</h1><?php }
    ?></main><?php wp_footer(); ?></body></html><?php
}

<?php

// @require fixtures/cache-tags-setup.php

/**
 * Collecting a page's tags, as a render would: the collectors started, then
 * queries, terms, links, fields, content, texts and patterns read in a
 * language, in another, and across them; the layout's marks, declared
 * globals and global fetches; pruning; the posts Timber built that no hook
 * saw; and the page's buffer closed, with its debug script.
 */

require __DIR__ . '/fixtures/cache-tags-helpers.php';

add_filter('wp_go_cache_tags/link_meta', fn ($keys) => [...$keys, 'zz_ct_external']);

$item = wg_ct_post(['post_type' => 'zz_ct_item', 'post_content' => 'Uno'], 'it');
$item_en = wg_ct_post(['post_type' => 'zz_ct_item', 'post_content' => 'One'], 'en');
wp_go_i18n_set_language($item_en, 'en', $item);
$plain = wg_ct_post(['post_type' => 'zz_ct_plain']);
$hidden = wg_ct_post(['post_type' => 'zz_ct_hidden']);
$office = wg_ct_post(['post_type' => 'zz_ct_office']);
$parent = wg_ct_post(['post_type' => 'page'], 'it');
$child = wg_ct_post(['post_type' => 'page', 'post_parent' => $parent], 'it');
$pattern = wg_ct_post(['post_type' => 'wp_block', 'post_content' => '<!-- wp:paragraph --><p>zz</p><!-- /wp:paragraph -->']);
$image = (int) wp_insert_attachment(['post_title' => 'zz ct image', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => 'http://base.test/zz-ct.jpg'], false, $item);
$topic = wg_ct_term('zz_ct_topic', 'zz ct topic', 'it');
$topic_en = wg_ct_term('zz_ct_topic', 'zz ct subject', 'en');
$role = wg_ct_term('zz_ct_role', 'zz ct role');
$secret = wg_ct_term('zz_ct_secret', 'zz ct secret');
wp_set_object_terms($item, [$topic], 'zz_ct_topic');
wg_ct_forget();

wp_go_cache_tags_start_collecting();

/** What a part of the page collected: its tags as they were noted, and the globals. */
function wg_ct_render(callable $render): array
{
    wp_go_cache_tags_reset();
    $render();

    $tags = array_keys(wp_go_cache_tags_collected());
    sort($tags);

    return ['tags' => $tags, 'globals' => wp_go_cache_tags_final_globals()];
}

section('Lists of posts');

$query = fn (array $args) => fn () => new WP_Query($args + ['fields' => 'ids', 'no_found_rows' => true]);

same(['type-zz_ct_item--it'], wg_ct_render($query(['post_type' => 'zz_ct_item']))['tags'], 'a query of a translated type is a list in the request\'s language');
same(['type-zz_ct_item--en'], wg_ct_render(fn () => wp_go_i18n_in('en', $query(['post_type' => 'zz_ct_item'])))['tags'], 'switched to English, the English list');
same(['type-zz_ct_item--en'], wg_ct_render($query(['post_type' => 'zz_ct_item', 'lang' => 'en']))['tags'], 'a lang asked is the list\'s language');
same(['type-zz_ct_item'], wg_ct_render($query(['post_type' => 'zz_ct_item', 'lang' => 'all']))['tags'], 'lang=all keeps the plain tag');
same(['type-zz_ct_item'], wg_ct_render(fn () => get_posts(['post_type' => 'zz_ct_item', 'fields' => 'ids']))['tags'], 'get_posts(), its filters suppressed, is across languages: the plain tag');
same(['type-zz_ct_plain'], wg_ct_render($query(['post_type' => 'zz_ct_plain']))['tags'], 'an untranslated type keeps the plain tag');
same([], wg_ct_render($query(['post_type' => 'zz_ct_hidden']))['tags'], 'a type that isn\'t tracked tags nothing');
same(['type-zz_ct_person'], wg_ct_render($query(['post_type' => 'zz_ct_person']))['tags'], 'a hidden type the card tracks is tagged');
same([], wg_ct_render($query(['post_type' => 'zz_ct_item', 'p' => $item]))['tags'], 'a query for one post isn\'t a list');
same([], wg_ct_render($query(['post_type' => 'zz_ct_item', 'post__in' => [$item, $item_en]]))['tags'], 'nor one for posts picked by id');
same(["children-$parent"], wg_ct_render($query(['post_type' => 'page', 'post_parent' => $parent]))['tags'], 'a list of a post\'s children is its children tag, with no type tag');
same(['type-page--it'], wg_ct_render($query(['post_type' => 'page', 'post_parent' => 0]))['tags'], 'top-level posts, parent 0, keep the type tag');
$any = wg_ct_render($query(['post_type' => 'any']))['tags'];
check('post_type any lists every public type', wg_ct_has($any, 'type-page--it', 'type-zz_ct_item--it', 'type-zz_ct_plain'), wg_ct_show($any));
check('but not those no visitor sees', wg_ct_lacks($any, 'type-zz_ct_hidden', 'type-zz_ct_person'), wg_ct_show($any));

section('Lists of terms');

$terms = fn (array $args) => fn () => get_terms($args + ['hide_empty' => false]);

same(['taxonomy-zz_ct_topic--it'], wg_ct_render($terms(['taxonomy' => 'zz_ct_topic']))['tags'], 'get_terms() of a translated taxonomy is a list in the request\'s language');
same(['taxonomy-zz_ct_topic--en'], wg_ct_render(fn () => wp_go_i18n_in('en', $terms(['taxonomy' => 'zz_ct_topic'])))['tags'], 'in English, the English list');
same(['taxonomy-zz_ct_topic--en'], wg_ct_render($terms(['taxonomy' => 'zz_ct_topic', 'lang' => 'en']))['tags'], 'a lang asked is the list\'s language');
same(['taxonomy-zz_ct_topic'], wg_ct_render($terms(['taxonomy' => 'zz_ct_topic', 'lang' => 'all']))['tags'], 'lang=all keeps the plain tag');
same(['taxonomy-zz_ct_topic'], wg_ct_render($terms(['taxonomy' => 'zz_ct_topic', 'wgl_all' => true]))['tags'], 'so does WP-Go Languages\' wgl_all');
same(['taxonomy-zz_ct_role'], wg_ct_render($terms(['taxonomy' => 'zz_ct_role']))['tags'], 'a tracked, untranslated taxonomy keeps the plain tag');
same([], wg_ct_render($terms(['taxonomy' => 'zz_ct_secret']))['tags'], 'a taxonomy that isn\'t tracked tags nothing');
same(["term-$role"], wg_ct_render($terms(['taxonomy' => 'zz_ct_role', 'include' => [$role]]))['tags'], 'terms asked by id aren\'t a list: each one\'s own tag');
same(["term-$role"], wg_ct_render(fn () => get_term_by('slug', get_term($role)->slug, 'zz_ct_role'))['tags'], 'get_term_by() is one of those');
same(["term-$topic"], wg_ct_render(fn () => get_term_link($topic))['tags'], 'a link to a term tags the term');
$own = wg_ct_render(fn () => get_the_terms($item, 'zz_ct_topic'))['tags'];
check('a post\'s own terms tag each term, not the taxonomy', in_array("term-$topic", $own, true) && !in_array('taxonomy-zz_ct_topic--it', $own, true), wg_ct_show($own));

section('What the page used of a post');

same(["link-$item"], wg_ct_render(fn () => get_permalink($item))['tags'], 'its address: its link');
same(["link-$item"], wg_ct_render(fn () => get_the_title($item))['tags'], 'its title: its link');
same(["link-$parent"], wg_ct_render(fn () => get_page_link($parent))['tags'], 'a page\'s address: its link');
same(["post-$item"], wg_ct_render(fn () => get_post_meta($item, 'zz_ct_subtitle', true))['tags'], 'a field: the post');
same(["link-$item"], wg_ct_render(fn () => get_post_meta($item, 'zz_ct_external', true))['tags'], 'a link meta key: its link');
same(["post-$item"], wg_ct_render(fn () => get_the_date('', $item))['tags'], 'its date: the post');
same(["content-$item"], wg_ct_render(fn () => get_the_excerpt($item))['tags'], 'its excerpt: its content');
same(["content-$item"], wg_ct_render(fn () => post_password_required($item))['tags'], 'the password check every content passes: its content');
same(["post-$image"], wg_ct_render(fn () => wp_get_attachment_url($image))['tags'], 'an image\'s address: the attachment');
same([], wg_ct_render(fn () => get_post_meta($hidden, 'zz_ct_subtitle', true))['tags'], 'a post of a type that isn\'t tracked: nothing');
same([], wg_ct_render(fn () => get_post_meta(0, 'zz_ct_subtitle', true))['tags'], 'no post given is no post, not the global one');
$content = wg_ct_render(function () use ($item) {
    $GLOBALS['post'] = get_post($item);
    setup_postdata($GLOBALS['post']);
    apply_filters('the_content', '<p>zz</p>');
    wp_reset_postdata();
})['tags'];
check('the content rendered: the global post\'s content', in_array("content-$item", $content, true), wg_ct_show($content));
$block = wg_ct_render(fn () => render_block(['blockName' => 'core/block', 'attrs' => ['ref' => $pattern], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]))['tags'];
check('a synced pattern placed: its pattern tag', in_array("pattern-$pattern", $block, true), wg_ct_show($block));
same(['zz-ct-numbers'], wg_ct_render(fn () => wp_go_cache_tag('zz-ct-numbers'))['tags'], 'a tag added by hand is the page\'s');

section('Texts');

$card = wp_go_cache_tags_text_tag('zz-ct-texts', 'views/card.twig', 'it');
same([$card], wg_ct_render(fn () => _x('Read more', 'views/card.twig', 'zz-ct-texts'))['tags'], 'a tracked domain\'s _x() tags its file, in the request\'s language');
same(['domain' => 'zz-ct-texts', 'context' => 'views/card.twig', 'language' => 'it'], wp_go_cache_tags_texts()[ $card ] ?? null, 'the tag is noted with its domain, file and language, for the debug JSON');
same([wp_go_cache_tags_text_tag('zz-ct-texts', 'views/card.twig', 'en')], wg_ct_render(fn () => wp_go_i18n_in('en', fn () => _x('Read more', 'views/card.twig', 'zz-ct-texts')))['tags'], 'printed in English, the English tag');
same([], wg_ct_render(fn () => _x('Read more', 'views/card.twig', 'zz-ct-untracked'))['tags'], 'a domain that isn\'t tracked tags nothing');
same([], wg_ct_render(fn () => __('Read more', 'zz-ct-texts'))['tags'], '__() has no file: nothing');
same([wp_go_cache_tags_text_tag(WP_GO_SITE_TEXTS_DOMAIN, 'zz.ct.cta', 'en')], wg_ct_render(fn () => apply_filters('wp_go_site_text', 'Call us', 'zz.ct.cta', 'en'))['tags'], 'a Site Texts key printed: its tag in the language printed');
$footer = wg_ct_render(fn () => _x('Address', 'views/footer.twig', 'zz-ct-texts'));
check('a file declared global goes with the globals, not the page', $footer['tags'] === [] || !array_diff($footer['tags'], $footer['globals']), wg_ct_show($footer['tags']));
same([wp_go_cache_tags_text_tag('zz-ct-texts', 'views/footer.twig', 'it')], $footer['globals'], 'its tag in the language printed');

section('The layout\'s marks');

same('', wp_go_cache_tags_global_start() . wp_go_cache_tags_global_end(), 'the marks print nothing');
$marked = wg_ct_render(function () {
    wp_go_cache_tags_global_start();
    wp_go_cache_tag('zz-ct-header');
    wp_go_cache_tags_global_start();
    wp_go_cache_tag('zz-ct-menu');
    wp_go_cache_tags_global_end();
    wp_go_cache_tag('zz-ct-logo');
    same(1, wp_go_cache_tags_global_depth(), 'one end of two leaves the outer mark open');
    wp_go_cache_tags_global_end();
    wp_go_cache_tags_global_end();
    same(0, wp_go_cache_tags_global_depth(), 'an extra end stays at 0');
    wp_go_cache_tag('zz-ct-body');
});
same(['zz-ct-body'], $marked['tags'], 'outside the marks, the page\'s tag');
same(['zz-ct-header', 'zz-ct-logo', 'zz-ct-menu'], $marked['globals'], 'inside them, nested or not, global');

$declared = wg_ct_render(fn () => get_permalink($office));
same([], $declared['tags'], 'a global type\'s post, read outside the marks, isn\'t the page\'s');
same(["link-$office"], $declared['globals'], 'it\'s global');
same([], wg_ct_render(fn () => new WP_Query(['post_type' => 'zz_ct_office', 'fields' => 'ids']))['tags'], 'a global type\'s list too');

wp_go_cache_tags_reset();
wp_go_cache_tag('zz-ct-both');
wp_go_cache_tags_global_start();
wp_go_cache_tag('zz-ct-both');
wp_go_cache_tags_global_end();
same([], wp_go_cache_tags_final(), 'a tag the layout carries too is left to the layout');

section('Global fetches');

$menu_item = (object) ['ID' => 999999, 'post_type' => 'nav_menu_item'];
$menu_item = new WP_Post($menu_item);
$menu_item->type = 'post_type';
$menu_item->object_id = $parent;
same(['posts' => [$item, 999999], 'links' => [$parent]], wp_go_cache_tags_fetched_ids([get_post($item), $menu_item]), 'a fetch\'s posts are found, and the page a menu item links to');
same(['posts' => [$item], 'links' => []], wp_go_cache_tags_fetched_ids((object) ['items' => [get_post($item)]]), 'a menu\'s items are walked');

$fetched = wg_ct_render(function () use ($item, $menu_item, $parent) {
    do_action('wp_go_blocks/global_start');
    new WP_Query(['post_type' => 'zz_ct_plain', 'fields' => 'ids']);
    do_action('wp_go_blocks/global_end', [get_post($item), $menu_item]);
    get_post_meta($item, 'zz_ct_subtitle', true);
    get_permalink($parent);
    get_post_meta($parent, 'zz_ct_subtitle', true);
});
same(['type-zz_ct_plain', "link-$parent", "post-$item"], array_values(array_intersect(['type-zz_ct_plain', "link-$parent", "post-$item"], $fetched['globals'])), 'the fetch\'s query, a post it returned read later, and the link a menu item points to are global');
same(["post-$parent"], $fetched['tags'], 'the menu\'s page keeps its fields on the page');

section('Pruning');

wp_go_cache_tags_reset();
new WP_Query(['post_type' => 'zz_ct_item', 'fields' => 'ids']);
get_permalink($item);
get_post_meta($item_en, 'zz_ct_subtitle', true);
get_the_excerpt($item);
get_term_link($topic);
get_term_link($topic_en);
get_terms(['taxonomy' => 'zz_ct_topic', 'hide_empty' => false]);
$final = wp_go_cache_tags_final();
check('a list in the post\'s language stands in for its link', !in_array("link-$item", $final, true), wg_ct_show($final));
check('an Italian list doesn\'t stand in for an English post', in_array("post-$item_en", $final, true), wg_ct_show($final));
check('the content tag stays: no list stands in for it', in_array("content-$item", $final, true), wg_ct_show($final));
check('a taxonomy\'s list in the term\'s language stands in for the term', !in_array("term-$topic", $final, true), wg_ct_show($final));
check('not for a term in another language', in_array("term-$topic_en", $final, true), wg_ct_show($final));
same($final, array_values(array_unique($final)), 'the final tags are each once');
$sorted = $final;
sort($sorted);
same($sorted, $final, 'and sorted');

wp_go_cache_tags_reset();
get_posts(['post_type' => 'zz_ct_item', 'fields' => 'ids']);
get_post_meta($item_en, 'zz_ct_subtitle', true);
same(['type-zz_ct_item'], wp_go_cache_tags_final(), 'the plain list stands in for a post of any language');

section('Posts Timber built that no hook saw');

wp_go_cache_tags_reset();
apply_filters('timber/post/class', 'Timber\\Post', get_post($plain));
wp_go_cache_tags_global_start();
apply_filters('timber/post/class', 'Timber\\Post', get_post($child));
apply_filters('timber/post/class', 'Timber\\Post', get_post($item));
wp_go_cache_tags_global_end();
get_post_meta($item, 'zz_ct_subtitle', true);
wp_go_cache_tags_finish();
check('built on the page, it gets its post tag at the end', in_array("post-$plain", wp_go_cache_tags_final(), true), wg_ct_show(wp_go_cache_tags_final()));
check('built only in the layout, a global post tag', in_array("post-$child", wp_go_cache_tags_final_globals(), true), wg_ct_show(wp_go_cache_tags_final_globals()));
check('one a hook saw keeps what the hook gave it', in_array("post-$item", wp_go_cache_tags_final(), true) && !in_array("post-$item", wp_go_cache_tags_final_globals(), true));

section('The page\'s own post');

$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query(['p' => $item, 'post_type' => 'zz_ct_item']);
$own = wg_ct_render(function () use ($item, $item_en) {
    wp_go_cache_tags_global_start();
    get_post_meta($item, 'zz_ct_subtitle', true);
    get_post_meta($item_en, 'zz_ct_subtitle', true);
    wp_go_cache_tags_global_end();
});
same(["post-$item"], $own['tags'], 'read in the layout, the page\'s own post is still the page\'s');
same(["post-$item_en"], $own['globals'], 'another post read there is global');
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query(['p' => $office, 'post_type' => 'zz_ct_office']);
same([], wg_ct_render(fn () => get_post_meta($office, 'zz_ct_subtitle', true))['tags'], 'declared global, the page\'s own post stays global');

section('The page\'s buffer');

wp_set_current_user(0);
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query(['p' => $item, 'post_type' => 'zz_ct_item']);
wp_go_cache_tags_reset();
$handed = [];
add_action('wp_go_cache_tags/page', function (...$args) use (&$handed) {
    $handed[] = $args;
}, 10, 4);
add_filter('wp_go_cache_tags/debug', '__return_true');
ob_start();
$level = ob_get_level();
wp_go_cache_tags_open_buffer();
$opened = ob_get_level() === $level + 1 && wp_go_cache_tags_buffer() !== null;
wp_go_cache_tags_collect_page();
echo '<html><body><p>zz ct page</p></body></html>';
ob_start(); // a buffer opened after ours, as wp-go-blocks' is
echo '<!-- zz ct late -->';
wp_go_cache_tags_close_buffer();
$out = ob_get_clean();
$buffer = &wp_go_cache_tags_buffer();
$buffer = null;
remove_action('shutdown', 'wp_go_cache_tags_close_buffer', 0);
remove_action('template_redirect', 'wp_go_cache_tags_collect_page', PHP_INT_MAX);
remove_filter('wp_go_cache_tags/debug', '__return_true');

check('a page a cache may keep opens its buffer, on wp', $opened);
check('the buffers opened after it are closed into it', str_contains($out, '<!-- zz ct late -->') && ob_get_level() === $level - 1);
preg_match('#<script type="application/json" id="wg-cache-tags">(.*?)</script>\n</body>#s', $out, $script);
check('the debug script goes before the last </body>', !empty($script));
$debug = json_decode($script[1] ?? '', true);
$listed = array_column($debug['tags'] ?? [], 'tag');
check('the page\'s own post is tagged: its fields and its content', wg_ct_has($listed, "post-$item", "content-$item"), wg_ct_show($listed));
// wp-cli has sent its output already: no status can be set, so none is 200
same(http_response_code() === 200 ? null : 'status ' . http_response_code(), $debug['skipped'] ?? 'none', 'the status is checked at the end: anything but 200 is skipped');
check('and a page skipped is never handed to the adapter', http_response_code() === 200 || $handed === []);

wg_ct_done();

<?php

/**
 * Redirects: a redirect written. wp_go_redirects_fields()'s checks (the
 * source, the destination, the limits, the loops, exact and through
 * patterns, up to WP_GO_REDIRECTS_HOPS on), the table's binary collation and
 * its 191-character index, and the screen's save handler: hand-written rows,
 * enabling and disabling, unchanged rows not written, a source taken, a live
 * page's warning.
 */

require __DIR__ . '/fixtures/redirects-helpers.php';

global $wpdb;

$table = wp_go_redirects_table();
$fields = fn (array $values, int $id = 0) => wp_go_redirects_fields($values, $id);
$refused = fn (array $values, int $id = 0) => $fields($values, $id)[0] === null;

section('The source and the destination');

[$f, $error] = $fields(['source' => '/WGTest-A/', 'fixed' => 1, 'url' => '/wgtest-b', 'note' => "  a <b>note</b>\non two lines  "]);
same(null, $error, 'a fixed redirect is fine');
same('wgtest-a', $f['source'], 'its source is normalized');
same('wgtest-b', $f['url'], 'its destination loses its first slash');
same(301, $f['status'], 'its status is 301 by default');
same("a note\non two lines", $f['note'], 'its note is plain text, its lines kept, trimmed');
same(0, $f['is_glob'], 'an exact source isn\'t a pattern');
same(302, $fields(['source' => 'wgtest-a', 'fixed' => 1, 'url' => 'x', 'status' => 302])[0]['status'], 'a status of the five is kept');
same(301, $fields(['source' => 'wgtest-a', 'fixed' => 1, 'url' => 'x', 'status' => 303])[0]['status'], 'any other status is 301');
check('an empty source is refused: the home page can\'t be redirected', $refused(['source' => '/', 'fixed' => 1, 'url' => 'x']));
check('a source of 255 characters is fine', !$refused(['source' => str_repeat('a', 255), 'fixed' => 1, 'url' => 'x']));
check('a source of 256 characters is refused', $refused(['source' => str_repeat('a', 256), 'fixed' => 1, 'url' => 'x']));
check('the length counts characters, not bytes: 255 è are fine', !$refused(['source' => str_repeat('è', 255), 'fixed' => 1, 'url' => 'x']));
check('a fixed destination of 2,048 characters is fine', !$refused(['source' => 'wgtest-a', 'fixed' => 1, 'url' => str_repeat('d', 2048)]));
check('a fixed destination of 2,049 is refused', $refused(['source' => 'wgtest-a', 'fixed' => 1, 'url' => str_repeat('d', 2049)]));
check('a destination with more stars than its source is refused', $refused(['source' => 'wgtest-p/*', 'fixed' => 1, 'url' => 'q/*/*']));
check('a destination with as many is fine', !$refused(['source' => 'wgtest-p/*/*', 'fixed' => 1, 'url' => 'q/*-*']));
check('no post chosen and no fixed address is refused', $refused(['source' => 'wgtest-a', 'post' => 0]));

$attachment = wp_insert_attachment(['post_title' => 'wgtest file', 'post_mime_type' => 'image/png', 'post_status' => 'inherit']);
check('an attachment isn\'t a destination', $refused(['source' => 'wgtest-a', 'post' => $attachment]));

$draft = wgr_page(['post_name' => 'wgtest-draft', 'post_status' => 'draft']);
[$f, $error] = $fields(['source' => 'wgtest-a', 'post' => $draft]);
check('a draft is a destination: it redirects once published', $error === null && $f['post_id'] === $draft);
same('', wp_go_redirects_post_info(get_post($draft))['path'], 'a draft has no path to show');

[$f] = $fields(['source' => 'wgtest-g', 'status' => 410, 'url' => 'ignored']);
same(['', 0], [$f['url'], $f['post_id']], 'a 410 has no destination');
same(1, $fields(['source' => 'wgtest-g/*', 'status' => 410])[0]['is_glob'], 'a pattern can be a 410');

section('Loops');

$page = wgr_page(['post_name' => 'wgtest-page']);
check('a page already at the source is refused: it would loop', $refused(['source' => 'wgtest-page', 'post' => $page]));
check('old/* to old/new/* is refused: it would answer its own destination', $refused(['source' => 'wgtest-old/*', 'fixed' => 1, 'url' => 'wgtest-old/new/*']));
check('a redirect to itself in other capitals is refused', $refused(['source' => 'wgtest-same', 'fixed' => 1, 'url' => '/WGTEST-Same/']));
check('another site\'s url never loops', !$refused(['source' => 'wgtest-out', 'fixed' => 1, 'url' => 'https://example.com/wgtest-out']));

$b_to_a = wgr_row(['source' => 'wgtest-loop-b', 'url' => 'wgtest-loop-a']);
[$f, $error] = $fields(['source' => 'wgtest-loop-a', 'fixed' => 1, 'url' => 'wgtest-loop-b']);
check('a to b with b to a is refused, naming the redirect that sends back', $f === null && str_contains((string) $error, '/wgtest-loop-b'));
check('c to b (with b to a) is fine', !$refused(['source' => 'wgtest-loop-c', 'fixed' => 1, 'url' => 'wgtest-loop-b']));
check('b to a edited into a to b is no loop with its old self', !$refused(['source' => 'wgtest-loop-a', 'fixed' => 1, 'url' => 'wgtest-loop-b'], $b_to_a));

wgr_row(['source' => 'wgtest-pb/*', 'url' => 'wgtest-pa/*']);
check('a/* to b/* with b/* to a/* is refused', $refused(['source' => 'wgtest-pa/*', 'fixed' => 1, 'url' => 'wgtest-pb/*']));
wgr_row(['source' => 'wgtest-qb/*', 'url' => 'wgtest-qa']);
check('a to b/x with b/* to a is refused', $refused(['source' => 'wgtest-qa', 'fixed' => 1, 'url' => 'wgtest-qb/x']));
wgr_page(['post_name' => 'wgtest-rb']);
$rb_child = wgr_page(['post_name' => 'x', 'post_parent' => (int) get_page_by_path('wgtest-rb')->ID]);
wgr_row(['source' => 'wgtest-rb/*', 'url' => 'wgtest-ra']);
same('wgtest-rb/x', wp_go_redirects_post_path(get_post($rb_child)), 'a page is published at wgtest-rb/x');
check('a to b/x with b/* to a is fine while a page is published at b/x: the page wins over the pattern', !$refused(['source' => 'wgtest-ra', 'fixed' => 1, 'url' => 'wgtest-rb/x']));

wgr_row(['source' => 'wgtest-disb', 'url' => 'wgtest-disa', 'enabled' => 0]);
check('a disabled redirect isn\'t followed', !$refused(['source' => 'wgtest-disa', 'fixed' => 1, 'url' => 'wgtest-disb']));

wgr_row(['source' => 'wgtest-page', 'url' => 'wgtest-to-page-src']);
check('a to a page whose address redirects back to a is refused', $refused(['source' => 'wgtest-to-page-src', 'post' => $page]));

// a chain: h0 → h1 → … → h9 → back to the source (10 on), and one longer
for ($i = 1; $i < 10; $i++) {
    wgr_row(['source' => "wgtest-h$i", 'url' => 'wgtest-h' . ($i + 1)]);
}
wgr_row(['source' => 'wgtest-h10', 'url' => 'wgtest-h0']);
check('a loop back 10 redirects on is caught', $refused(['source' => 'wgtest-h0', 'fixed' => 1, 'url' => 'wgtest-h1']));
wgr_row(['source' => 'wgtest-h0x', 'url' => 'wgtest-h1']);
check('one 11 on is past the walk, and saved', !$refused(['source' => 'wgtest-h0', 'fixed' => 1, 'url' => 'wgtest-h0x']));
same(null, wp_go_redirects_loop('wgtest-h0', 'wgtest-h0x'), 'wp_go_redirects_loop() stops after WP_GO_REDIRECTS_HOPS');
same('wgtest-h10', wp_go_redirects_loop('wgtest-h0', 'wgtest-h1')->source, 'within them it names the redirect that sends back');

$rules = [(object) ['source' => 'wgtest-new-b', 'is_glob' => 0, 'post_id' => 0, 'url' => 'wgtest-new-a', 'status' => 301, 'enabled' => 1]];
same('wgtest-new-b', wp_go_redirects_loop('wgtest-new-a', 'wgtest-new-b', $rules)->source, 'rules not in the table yet are followed too');
check('without $follow the others aren\'t followed', wp_go_redirects_fields(['source' => 'wgtest-loop-a', 'fixed' => 1, 'url' => 'wgtest-loop-b'], 0, false)[0] !== null);

section('The table: collation and its index');

wgr_row(['source' => 'wgtest-caffe', 'url' => 'x']);
same(null, wgr_find('wgtest-caffè'), 'caffè isn\'t caffe: the source is compared byte for byte');
check('caffè can be written beside caffe', $wpdb->insert($table, ['source' => 'wgtest-caffè', 'url' => 'y', 'status' => 301, 'created' => current_time('mysql', true)]) !== false);
same('y', wgr_find('wgtest-caffè')->url, 'each is found as itself');
$long = 'wgtest-' . str_repeat('b', 200);
wgr_row(['source' => $long . '1', 'url' => 'x']);
$wpdb->suppress_errors(true);
same(false, $wpdb->insert($table, ['source' => $long . '2', 'url' => 'x', 'status' => 301, 'created' => current_time('mysql', true)]), 'two sources alike in their first 191 characters can\'t both be in');
$wpdb->suppress_errors(false);

section('The save: written by hand');

$save = fn (array $rows) => wgr_post('wp_go_redirects_save', ['rows' => $rows]);
$notices = function () {
    $notices = get_transient(wp_go_redirects_notices_key());
    delete_transient(wp_go_redirects_notices_key());

    return $notices ?: ['error' => [], 'warning' => []];
};
$args = fn (array $answer) => wp_parse_args((string) wp_parse_url($answer['redirect'] ?? '', PHP_URL_QUERY));

$answer = $save(['new-0' => ['source' => 'wgtest-saved', 'fixed' => '1', 'url' => '/wgtest-there', 'status' => '302', 'note' => 'why']]);
same('1', $args($answer)['added'] ?? null, 'a new redirect is added, and counted');
$saved = wgr_find('wgtest-saved');
check('it\'s written as posted, hand-written, by the user', $saved && $saved->url === 'wgtest-there' && (int) $saved->status === 302 && (int) $saved->auto === 0 && (int) $saved->created_by === get_current_user_id() && $saved->note === 'why');
same(null, $saved->modified, 'a new redirect has no modified date');

$save([$saved->id => ['source' => 'wgtest-saved', 'fixed' => '1', 'url' => 'wgtest-there', 'status' => '302', 'note' => 'why']]);
same(null, wgr_find('wgtest-saved')->modified, 'saved unchanged, it isn\'t written');

$auto = wgr_row(['source' => 'wgtest-auto', 'post_id' => $page, 'auto' => 1]);
$save([$auto => ['enabled' => '0']]);
$row = wgr_find('wgtest-auto');
check('disabled alone, it stays added on a change of address', (int) $row->enabled === 0 && (int) $row->auto === 1 && $row->modified !== null);
$save([$auto => ['enabled' => '1']]);
check('enabled again, it\'s still one', (int) wgr_find('wgtest-auto')->enabled === 1 && (int) wgr_find('wgtest-auto')->auto === 1);
$save([$auto => ['source' => 'wgtest-auto', 'post' => (string) $page, 'status' => '302']]);
check('edited, it\'s hand-written', (int) wgr_find('wgtest-auto')->auto === 0 && (int) wgr_find('wgtest-auto')->status === 302);

$off = wgr_row(['source' => 'wgtest-off', 'url' => 'x', 'enabled' => 0]);
$save([$off => ['source' => 'wgtest-off', 'fixed' => '1', 'url' => 'y']]);
check('editing a disabled redirect keeps it disabled', (int) wgr_find('wgtest-off')->enabled === 0 && wgr_find('wgtest-off')->url === 'y');

// enabling isn't checked again: a loop disabled, then enabled
$loop = wgr_row(['source' => 'wgtest-loop-a', 'url' => 'wgtest-loop-b', 'enabled' => 0]);
$save([$loop => ['enabled' => '1']]);
same(1, (int) wgr_find('wgtest-loop-a')->enabled, 'enabling a redirect isn\'t checked again, a loop included');

$answer = $save(['new-0' => ['source' => '/wgtest-saved/', 'fixed' => '1', 'url' => 'z']]);
$said = $notices();
check('a source another redirect has is refused, and said', ($args($answer)['added'] ?? '') === '0' && str_contains(implode(' ', $said['error']), '/wgtest-saved'));
$save(['new-0' => ['source' => 'wgtest-off', 'fixed' => '1', 'url' => 'z']]);
check('a disabled redirect still holds its source', count($notices()['error']) === 1);

$answer = $save(['new-0' => ['source' => 'wgtest-page', 'fixed' => '1', 'url' => 'elsewhere']]);
check('an exact source where a page is published is refused while another redirect has it', count($notices()['error']) === 1);
$wpdb->delete($table, ['source' => 'wgtest-page']);
$answer = $save(['new-0' => ['source' => 'wgtest-page', 'fixed' => '1', 'url' => 'elsewhere']]);
$said = $notices();
check('an exact source where a page is published is saved, with a warning', ($args($answer)['added'] ?? '') === '1' && str_contains(implode(' ', $said['warning']), 'wgtest-page'));

$wpdb->suppress_errors(true);
$answer = $save(['new-0' => ['source' => $long . '3', 'fixed' => '1', 'url' => 'x']]);
$wpdb->suppress_errors(false);
$said = $notices();
check('a row the database refuses isn\'t counted, and its message is said', ($args($answer)['added'] ?? '') === '0' && str_contains(implode(' ', $said['error']), 'the database refused it'));

$answer = $save([$saved->id => ['remove' => '1'], 'new-1' => ['source' => '  '], 999999999 => ['source' => 'wgtest-gone-since', 'fixed' => '1', 'url' => 'x']]);
check('a removed row is deleted; an empty new one and one gone since are skipped', ($args($answer)['removed'] ?? '') === '1' && !wgr_find('wgtest-saved') && !wgr_find('wgtest-gone-since'));

$answer = $save(['new-0' => ['source' => 'wgtest-qa', 'fixed' => '1', 'url' => 'wgtest-qb/x']]);
$said = $notices();
check('a loop through the table is refused by the save', ($args($answer)['added'] ?? '') === '0' && str_contains(implode(' ', $said['error']), 'it would loop'));

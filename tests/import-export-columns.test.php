<?php

/**
 * Import Export: which lists it works on, the columns a kind's fields open
 * into, what each column exports for an item, and how each kind of cell is
 * read back. On pages, with the in-memory groups of
 * fixtures/import-export-setup.php; attachments are rows without files.
 */

require __DIR__ . '/fixtures/import-export-setup.php';

$suffix = wp_generate_password(6, false, false);
$attachment = function (string $file, string $alt = '', string $mime = 'image/jpeg'): int {
    $id = wp_insert_attachment([ 'post_title' => $file, 'post_mime_type' => $mime, 'post_status' => 'inherit' ], "2026/10/$file");
    if ($alt !== '') {
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
    }

    return $id;
};
$error = function (callable $parse): string {
    try {
        $parse();

        return '';
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }
};

section('The lists');

$kind = wp_go_import_export_kind('post', 'page');
check('pages are a kind, through the post types filter', is_array($kind) && $kind['kind'] === 'post' && $kind['name'] === 'page');
check('pages are translated: a sheet per language', $kind['translated'] === true);
same(null, wp_go_import_export_kind('post', 'post'), 'posts, taken out by the filter, are no kind');
same(null, wp_go_import_export_kind('nonsense', 'page'), 'a kind is post or term');
$topics = wp_go_import_export_kind('term', 'wgie_topic');
check('a taxonomy is a kind of terms, untranslated', is_array($topics) && $topics['kind'] === 'term' && $topics['translated'] === false);
check('attachments and ACF post types are never offered', !array_intersect([ 'attachment', 'acf-field-group' ], wp_go_import_export_all_post_types()));
$export = wp_go_import_export_url($kind, 'export');
check('the export URL is admin-post with its nonce', str_contains($export, 'admin-post.php') && str_contains($export, 'action=wp_go_import_export_download') && str_contains($export, '_wpnonce='));
check('the import URL is the page with no menu entry', str_contains(wp_go_import_export_url($kind, 'import'), 'page=wg-import-excel&kind=post&name=page'));
same('wp_go_import_export_import_term_wgie_topic', wp_go_import_export_nonce($topics, 'import'), 'the nonce action names the kind and the list');

section('Sheet names');

same('English (en)', wp_go_import_export_language_sheet_name('en', 'English'), 'a language sheet is its name and its code');
$long = wp_go_import_export_language_sheet_name('zh-hk', 'Cinese (semplificato, Hong Kong)');
check('a long name is cut before the code, within 31 characters', mb_strlen($long) <= 31 && str_ends_with($long, '… (zh-hk)'), $long);
same('en (en)', wp_go_import_export_language_sheet_name('en', '  '), 'a language without a name is named by its code');
$used = [];
same('a b c', wp_go_import_export_sheet_name('a[b]c', 1, $used), 'the characters Excel refuses become spaces');
same('A B C (2)', wp_go_import_export_sheet_name('A:B*C', 2, $used), 'a name taken, case aside, gets a number');
same('Sheet3', wp_go_import_export_sheet_name(' / ', 3, $used), 'a blank name is the sheet\'s number');

section('Columns from the fields');

$columns = array_column(wp_go_import_export_columns($kind), null, 'label');
$labels = array_keys($columns);
same([ 'ID', 'translation_of', 'post_title', 'post_name', 'post_status', 'post_date', 'post_parent', 'post_thumbnail', 'post_thumbnail.alt' ], array_slice($labels, 0, 9), 'a page\'s own columns come first, in order');
check('a taxonomy of the post type with an admin list is a column', isset($columns['wgie_topic']));
check('tabs and messages hold no value: no column', !isset($columns['wgie_tab']) && !isset($columns['wgie_message']));
check('ID and translation_of are read-only', !isset($columns['ID']['parse']) && !isset($columns['translation_of']['parse']));
same(1, count(array_filter($labels, fn ($label) => str_starts_with($label, 'wgie_intro'))), 'a field named like one of an earlier group is left out');
same('field_wgie_intro', $columns['wgie_intro']['write'][1]['key'] ?? null, 'the first group\'s field is the one kept');
check('the second group\'s other fields are there', isset($columns['wgie_more']));
check('a field named like an own column is headed with its key once its group title is taken', isset($columns['post_name (IE test)'], $columns['post_name (field_wgie_post_name)']));
same([ 'wgie_photo.image', 'wgie_photo.image_mobile', 'wgie_photo.alt' ], array_values(array_filter($labels, fn ($label) => str_starts_with($label, 'wgie_photo.'))), 'the theme\'s image group opens into image, image_mobile and its own alt');
check('the image group\'s alt can be imported', isset($columns['wgie_photo.alt']['parse']));
check('a plain image\'s alt, the media\'s own, is read-only', isset($columns['wgie_speakers.1.photo.alt']) && !isset($columns['wgie_speakers.1.photo.alt']['parse']));
check('a group opens into its sub-fields', isset($columns['wgie_address.city']['parse']) && !isset($columns['wgie_address']));
check('a link, flexible content: read-only', !isset($columns['wgie_cta']['parse']) && !isset($columns['wgie_blocks']['parse']));
check('a required field with a parser is required', $columns['wgie_subtitle']['required'] === true);
check('a required field the import can\'t read is not', $columns['wgie_cta']['required'] === false);
check('a required field inside a repeater is not', $columns['wgie_speakers.1.name']['required'] === false);
same('Novità', $columns['wgie_kicker']['default'] ?? null, 'a field\'s default is the column\'s');
check('rich text and repeater rows have no default', !array_key_exists('default', $columns['wgie_body']) && !array_key_exists('default', $columns['wgie_speakers.1.name']));
check('rich text warns when the HTML loses something', isset($columns['wgie_body']['warning']));
same([ 'wgie_speakers.1.name', 'wgie_speakers.1.pic.image', 'wgie_speakers.1.pic.image_mobile', 'wgie_speakers.1.pic.alt', 'wgie_speakers.1.photo', 'wgie_speakers.1.photo.alt' ], array_values(array_filter($labels, fn ($label) => str_starts_with($label, 'wgie_speakers.'))), 'a repeater with no rows anywhere has one set, its clone opened too');

$three = array_column(wp_go_import_export_columns($kind, [], 3), null, 'label');
check('`$min_rows` asks for more sets', isset($three['wgie_speakers.3.name']) && !isset($three['wgie_speakers.4.name']));
$row = $three['wgie_speakers.2.name'];
same(1, $row['rows'][0]['index'], 'a set\'s column knows its row index');
same([ 1, 'field_wgie_sp_name' ], $row['write'][2], 'and writes to its row\'s sub-field key');
same('field_wgie_speakers', $row['write'][1]['key'], 'under the top-level field');
check('the header numbers size the repeaters too', isset(wgie_columns([ 'ID', 'wgie_speakers.4.photo' ])['wgie_speakers.4.name']));

$sized = wgie_page("WGIE sized $suffix", 'it', null, [ 'wgie_speakers' => [ [ 'name' => 'A' ], [ 'name' => 'B' ] ] ]);
$by_items = array_column(wp_go_import_export_columns($kind, [ get_post($sized) ]), null, 'label');
check('the item with the most rows sizes the sets', isset($by_items['wgie_speakers.2.name']) && !isset($by_items['wgie_speakers.3.name']));

$term_columns = array_column(wp_go_import_export_columns($topics), 'label');
same([ 'ID', 'name', 'slug', 'description' ], $term_columns, 'a flat untranslated taxonomy\'s columns: no translation_of, no parent');

section('Values');

$anna = $attachment("wgie-anna-$suffix.jpg", 'Anna, the media alt');
$bruno = $attachment("wgie-bruno-$suffix.jpg");
$topic = wp_insert_term("R&D $suffix", 'wgie_topic');
$target = wgie_page("WGIE target $suffix", 'it');
$item = wgie_page("WGIE values $suffix", 'it', null, [
    'wgie_intro'    => "Uno\n\nDue <b>tre</b> &amp; R&D",
    'wgie_body'     => "<p>Para</p>\n\n<p>Two</p>",
    'wgie_cta'      => [ 'title' => 'Go', 'url' => 'https://example.com/go', 'target' => '' ],
    'wgie_role'     => 'counsel',
    'wgie_flag'     => 1,
    'wgie_day'      => '20261005',
    'wgie_address'  => [ 'city' => 'Milano' ],
    'wgie_photo'    => [ 'image' => $anna, 'image_mobile' => $bruno, 'alt' => '' ],
    'wgie_rel'      => $target,
    'wgie_gallery'  => [ $anna, $bruno ],
    'wgie_blocks'   => [ [ 'acf_fc_layout' => 'quote', 'text' => 'Well said' ] ],
    'wgie_speakers' => [ [ 'name' => 'Anna', 'photo' => $anna ], [ 'name' => 'Bruno', 'pic' => [ 'image' => $bruno, 'alt' => 'Own alt' ] ] ],
], [ 'post_name' => 'привет-' . strtolower($suffix) ]);
wp_set_object_terms($item, [ $topic['term_id'] ], 'wgie_topic');
$post = get_post($item);
$cols = array_column(wp_go_import_export_columns($kind, [ $post ]), null, 'label');
$value = fn (string $label) => wp_go_i18n_in('it', fn () => trim($cols[$label]['value']($post)));

same((string) $item, $value('ID'), 'ID is the post\'s');
same('', $value('translation_of'), 'an original translates nothing');
same('привет-' . strtolower($suffix), $value('post_name'), 'the slug is decoded');
same("Uno\n\nDue <b>tre</b> &amp; R&D", $value('wgie_intro'), 'a textarea is exported raw: no paragraphs, its HTML and entities as stored');
same("<p>Para</p>\n\n<p>Two</p>", $value('wgie_body'), 'rich text is its HTML as stored');
same('Go https://example.com/go', $value('wgie_cta'), 'a link is its title and URL');
same('counsel', $value('wgie_role'), 'a select is its value');
same(_x('Yes', 'features/import-export/includes/columns.php', 'wp-go'), $value('wgie_flag'), 'a true/false is Yes, in the admin\'s language');
same('05/10/2026', $value('wgie_day'), 'a date is in its return format');
same('Milano', $value('wgie_address.city'), 'a group\'s sub-field');
same("wgie-anna-$suffix.jpg #$anna", $value('wgie_photo.image'), 'an image is its file name and id');
same("wgie-bruno-$suffix.jpg #$bruno", $value('wgie_photo.image_mobile'), 'the mobile image too');
same('Anna, the media alt', $value('wgie_photo.alt'), 'the image group\'s alt is the media\'s when its own is empty');
same('Own alt', $value('wgie_speakers.2.pic.alt'), 'and its own when set');
same("WGIE target $suffix #$target", $value('wgie_rel'), 'a related post is its title and id');
same("wgie-anna-$suffix.jpg #$anna (Anna, the media alt)\nwgie-bruno-$suffix.jpg #$bruno", $value('wgie_gallery'), 'a gallery is a line per image, the alt in brackets');
same('quote: text: Well said', $value('wgie_blocks'), 'flexible content is a line per row');
same('Bruno', $value('wgie_speakers.2.name'), 'a repeater row\'s sub-field is in its set');
same("wgie-anna-$suffix.jpg #$anna", $value('wgie_speakers.1.photo'), 'an image in a row');
same('Anna, the media alt', $value('wgie_speakers.1.photo.alt'), 'a plain image\'s alt is the media\'s');
same("R&D $suffix #{$topic['term_id']}", $value('wgie_topic'), 'a term is its name decoded, and its id');
same('', $value('wgie_kicker'), 'an empty field is an empty cell');

$term = WP_Term::get_instance($topic['term_id']);
$term_value = array_column(wp_go_import_export_columns($topics), null, 'label');
same("R&D $suffix", $term_value['name']['value']($term), 'a term\'s name column is decoded');
same("R&D $suffix #{$topic['term_id']}", wp_go_import_export_term_ref($topic['term_id']), 'term_ref reads by id');
same('', wp_go_import_export_term_ref(0), 'no term is no reference');

section('Reading cells back');

$parse = fn (string $label, string $cell, ?object $images = null) => $columns[$label]['parse']($cell, $images ?? wp_go_import_export_images([]));
same('counsel', $parse('wgie_role', 'Counsel'), 'a choice by its label');
same('partner', $parse('wgie_role', 'PARTNER'), 'or by its value, any case');
check('another word is not a choice', str_contains($error(fn () => $parse('wgie_role', 'boss')), '"boss" is not a choice: write one of partner, counsel'));
same(1, $parse('wgie_flag', 'sì'), 'Italian yes is yes');
same(0, $parse('wgie_flag', 'FALSE'), 'false, any case, is no');
same(0, $parse('wgie_flag', ''), 'an empty true/false is no');
check('maybe is neither', $error(fn () => $parse('wgie_flag', 'maybe')) !== '');
same('20261005', $parse('wgie_day', '05/10/2026'), 'a date in its return format');
same('20261005', $parse('wgie_day', '2026-10-05'), 'a date as Y-m-d');
check('an impossible date is refused', str_contains($error(fn () => $parse('wgie_day', '31/02/2026')), 'Not a date'));
same('2026-10-05 14:30:00', $parse('post_date', '2026-10-05 14:30'), 'a post date with minutes');
same('2026-10-05 00:00:00', $parse('post_date', '2026-10-05'), 'a post date without time');
check('a bad email is refused', $error(fn () => $parse('wgie_email', 'not-an-email')) === 'Not an email address.');
same('', $parse('wgie_email', ''), 'an empty email empties it');
check('a status not in the list is refused', str_contains($error(fn () => $parse('post_status', 'trash')), 'Not a status'));
same('citta-nuova', $parse('post_name', 'Città Nuova'), 'a slug is made as WordPress makes one');

same([ 42, 43 ], wp_go_import_export_parse_refs('Hello, world #42, Other #43', true, fn () => [], fn () => null), 'on a line with ids, only a comma after an id ends an item');
same([ 7, 8 ], wp_go_import_export_parse_refs("a, b", true, fn ($name) => [ 'a' => [ 7 ], 'b' => [ 8 ] ][$name], fn () => null), 'on a line without ids, every comma does');
same([ 5, 6 ], wp_go_import_export_parse_refs("x.jpg #5 (an alt, with a comma)\ny.jpg #6", true, fn () => [], fn () => null), 'an alt in brackets after an id is ignored');
same(2, wp_go_import_export_parse_refs('Hello, world #2', false, fn () => [], fn () => null), 'a single reference is the whole line, commas and all');
check('two lines in a single reference are refused', $error(fn () => wp_go_import_export_parse_refs("a #1\nb #2", false, fn () => [], fn () => null)) === 'Only one is allowed here.');
check('a name found twice asks for the id', str_contains($error(fn () => wp_go_import_export_parse_refs('Twin', false, fn () => [ 1, 2 ], fn () => null)), 'matches more than one'));
check('a name not found says so', str_contains($error(fn () => wp_go_import_export_parse_refs('Ghost', false, fn () => [], fn () => null)), '"Ghost" not found.'));

$it_twin = wgie_page("WGIE twin $suffix", 'it');
$en_twin = wgie_page("WGIE twin $suffix", 'en', $it_twin);
same($en_twin, wp_go_i18n_in('en', fn () => wp_go_import_export_parse_posts("WGIE twin $suffix", [ 'page' ], false)), 'a post name is looked up in the row\'s language');
same($it_twin, wp_go_i18n_in('it', fn () => wp_go_import_export_parse_posts("WGIE twin $suffix", [ 'page' ], false)), 'the same name in the other language finds the other');
same($en_twin, wp_go_i18n_in('it', fn () => wp_go_import_export_parse_posts("x #$en_twin", [ 'page' ], false)), 'an #id is accepted in any language');
check('an #id of another type is refused', str_contains($error(fn () => wp_go_import_export_parse_posts("#$anna", [ 'page' ], false)), 'is not a post that goes here'));
same([ $topic['term_id'] ], wp_go_import_export_parse_terms("R&D $suffix", [ 'wgie_topic' ], true), 'a term by its name');
$category = wp_insert_term("WGIE cat $suffix", 'category');
check('a term of another taxonomy is refused', str_contains($error(fn () => wp_go_import_export_parse_terms("#{$category['term_id']}", [ 'wgie_topic' ], true)), 'is not a term that goes here'));
$user = wp_insert_user([ 'user_login' => "wgie_$suffix", 'user_pass' => wp_generate_password(), 'display_name' => "WGIE User $suffix", 'role' => 'subscriber' ]);
same($user, wp_go_import_export_parse_users("wgie_$suffix", false), 'a user by login');
same("WGIE User $suffix #$user", wp_go_import_export_user_ref($user), 'a user is its display name and id');

$pdf = $attachment("wgie-brochure-$suffix.pdf", '', 'application/pdf');
same($anna, wp_go_import_export_parse_media("wgie-anna-$suffix.jpg", true, false, wp_go_import_export_images([])), 'an image by its file name');
same($anna, wp_go_import_export_parse_media("wgie anna $suffix.jpg", true, false, wp_go_import_export_images([])), 'or as WordPress would name its upload');
same($anna, wp_go_import_export_parse_media("2026/10/wgie-anna-$suffix.jpg", true, false, wp_go_import_export_images([])), 'or by its path in the uploads');
check('a PDF is not an image', str_contains($error(fn () => wp_go_import_export_parse_media("#$pdf", true, false, wp_go_import_export_images([]))), 'is not an image of the media library'));
same($pdf, wp_go_import_export_parse_media("wgie-brochure-$suffix.pdf", false, false, wp_go_import_export_images([])), 'a file field takes it');
$images = wp_go_import_export_images([ "New-$suffix.JPG" => WP_GO_IMPORT_EXPORT_PENDING ]);
same(WP_GO_IMPORT_EXPORT_PENDING, wp_go_import_export_parse_media("new-$suffix.jpg", true, false, $images), 'a name not in the library is a dropped image, any case, pending in the check');
same([ "New-$suffix.JPG" => true ], $images->used, 'and is noted as used by the row');
same([ $anna, $bruno ], wp_go_import_export_parse_media("wgie-anna-$suffix.jpg #$anna (alt)\nwgie-bruno-$suffix.jpg", true, true, wp_go_import_export_images([])), 'a gallery mixes ids and names, a line each');
check('a name neither in the library nor dropped is not found', str_contains($error(fn () => wp_go_import_export_parse_media("ghost-$suffix.jpg", true, false, wp_go_import_export_images([]))), 'not found'));

section('Comparing cells');

same("ab\nc", wp_go_import_export_normalize("a\x01b\r\nc "), 'a cell is compared without control characters, Windows line ends or spaces around');
same("x\ty", wp_go_import_export_normalize("x\ty\x0b"), 'tabs stay, a vertical tab goes');
same('English (en)!F14', wp_go_import_export_cell('English (en)', 5, 14), 'a cell is named as Excel names it');
same('English (en)!14', wp_go_import_export_cell('English (en)', false, 14), 'a column the sheet hasn\'t is the row alone');
same("Para\nTwo & more", wp_go_import_export_html_text('<p>Para</p><p>Two &amp; more</p>'), 'HTML as plain lines');

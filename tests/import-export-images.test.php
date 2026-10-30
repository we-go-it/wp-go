<?php

/**
 * Import Export's images by name: a real upload over 2560 pixels, which
 * WordPress keeps as name-scaled.jpg, found by its original name too, and a
 * dropped image's id once uploaded, used by a row of the run. The image and
 * every size WordPress made of it are deleted at the end, even when the file
 * stops early.
 */

require __DIR__ . '/fixtures/import-export-setup.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$suffix = strtolower(wp_generate_password(6, false, false));
$base = wp_upload_dir()['basedir'];
$leftovers = fn () => array_merge(glob("$base/*/*/wgie-big-$suffix*") ?: [], glob("$base/wgie-big-$suffix*") ?: []);
register_shutdown_function(function () use ($leftovers) {
    foreach ($leftovers() as $file) {
        @unlink($file);
    }
});

section('An image over 2560 pixels');

$tmp = wp_tempnam("wgie-big-$suffix.jpg");
$canvas = imagecreatetruecolor(3000, 2000);
imagefill($canvas, 0, 0, imagecolorallocate($canvas, 200, 50, 50));
imagejpeg($canvas, $tmp, 40);
$id = media_handle_sideload([ 'name' => "wgie big $suffix.jpg", 'tmp_name' => $tmp ], 0);
@unlink($tmp);
if (!check('the image is uploaded', is_int($id), is_wp_error($id) ? $id->get_error_message() : '')) {
    return;
}
$attached = get_post_meta($id, '_wp_attached_file', true);
$dir = dirname($attached);
check('WordPress keeps it scaled', str_ends_with($attached, "wgie-big-$suffix-scaled.jpg"), $attached);
same("wgie-big-$suffix.jpg", wp_get_attachment_metadata($id)['original_image'] ?? null, 'its original name is in its metadata');

$find = fn (string $name) => wp_go_import_export_media_ids($name);
same([ $id ], $find("wgie-big-$suffix-scaled.jpg"), 'its stored name finds it');
same([ $id ], $find("wgie-big-$suffix.jpg"), 'its original name finds it');
same([ $id ], $find(strtoupper("wgie-big-$suffix.jpg")), 'any case');
same([ $id ], $find("$dir/wgie-big-$suffix.jpg"), 'with its folder');
same([], $find("1999/01/wgie-big-$suffix.jpg"), 'not with another folder');
same([], $find("big-$suffix.jpg"), 'not by the end of its name');
$parse = fn (string $cell, bool $multiple = false) => wp_go_import_export_parse_media($cell, true, $multiple, wp_go_import_export_images([]));
same($id, $parse("wgie big $suffix.jpg"), 'the name as dropped, before WordPress named the upload, finds it');
same([ $id, $id ], $parse("wgie-big-$suffix.jpg (red)\nwgie-big-$suffix-scaled.jpg", true), 'both names in a gallery, an alt after one');

$page = wgie_page("WGIE pictured $suffix", 'it');
$cells = [ 'ID' => (string) $page, 'post_thumbnail' => "wgie-big-$suffix.jpg" ];
$images = wp_go_import_export_images([ "wgie-big-$suffix.jpg" => WP_GO_IMPORT_EXPORT_PENDING ]);
$plan = wp_go_i18n_in('it', fn () => wp_go_import_export_plan(wp_go_import_export_kind('post', 'page'), $cells, wgie_columns(), 'it', false, $images));
same($id, $plan['changes']['post_thumbnail'] ?? null, 'an image already in the library isn\'t one to upload again');
same([], $images->used, 'even when one of that name was dropped');

section('A dropped image, once uploaded');

$dropped = wgie_page("WGIE dropped $suffix", 'it');
$answer = wgie_run([ [ 'Italiano (it)', 2, [ 'ID' => (string) $dropped, 'post_thumbnail' => "Portrait-$suffix.JPG" ] ] ], [ "portrait-$suffix.jpg" => $id ]);
check('a row using it is saved', ($answer['data'][0]['ok'] ?? false) === true);
same($id, (int) get_post_thumbnail_id($dropped), 'with the uploaded image, by its name, any case');
$answer = wgie_run([ [ 'Italiano (it)', 3, [ 'ID' => (string) $dropped, 'post_thumbnail' => "failed-$suffix.jpg" ] ] ], [ "failed-$suffix.jpg" => 0 ]);
check('an image that failed to upload fails the rows that use it', ($answer['data'][0]['ok'] ?? true) === false && str_contains(implode(' ', $answer['data'][0]['errors'] ?? []), 'not found'));

section('Cleaning up');

check('the upload made files', count($leftovers()) > 1);
wp_delete_attachment($id, true);
same([], $leftovers(), 'deleting the image removes every file of it');

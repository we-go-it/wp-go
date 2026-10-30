<?php

/**
 * Loaded by wp-cli before WordPress (`// @require fixtures/cache-tags-setup.php`):
 * the post types and taxonomies the cache-tags tests build their posts in,
 * and Cache Tags' card as those tests need it, without saving it.
 *
 * Cache Tags reads its settings once a request, the first time it needs
 * them, so they're given here, through the option's filter, ahead of any
 * read:
 *
 *   zz_ct_item     public, translated (WP-Go Languages), with zz_ct_topic
 *                  (public, translated)
 *   zz_ct_plain    public, not translated
 *   zz_ct_person   not public, tracked on the card; zz_ct_role, not
 *                  public, tracked too
 *   zz_ct_hidden   not public, not tracked; zz_ct_secret the same for terms
 *   zz_ct_office   public, declared global; zz_ct_area, public, declared global
 *   zz-ct-texts    a tracked text domain, its views/footer.twig declared global
 */

const WG_TEST_CT_SETTINGS = [
    'tracked_types'      => ['zz_ct_person'],
    'tracked_taxonomies' => ['zz_ct_role'],
    'text_domains'       => ['zz-ct-texts'],
    'global_types'       => ['zz_ct_office'],
    'global_taxonomies'  => ['zz_ct_area'],
    'global_texts'       => ['zz-ct-texts|views/footer.twig'],
];

WP_CLI::add_wp_hook('option_wp_go_settings', function ($settings) {
    $settings = is_array($settings) ? $settings : [];
    $settings['cache-tags'] = WG_TEST_CT_SETTINGS;

    return $settings;
});

WP_CLI::add_wp_hook('wp_go_languages/post_types', fn ($types) => [...(array) $types, 'zz_ct_item']);
WP_CLI::add_wp_hook('wp_go_languages/taxonomies', fn ($taxonomies) => [...(array) $taxonomies, 'zz_ct_topic']);

WP_CLI::add_wp_hook('init', function () {
    $public = ['public' => true, 'show_in_rest' => true];
    $hidden = ['public' => false, 'publicly_queryable' => false, 'show_ui' => true];

    register_post_type('zz_ct_item', $public + ['label' => 'CT items', 'taxonomies' => ['zz_ct_topic']]);
    register_post_type('zz_ct_plain', $public + ['label' => 'CT plain']);
    register_post_type('zz_ct_office', $public + ['label' => 'CT offices']);
    register_post_type('zz_ct_person', $hidden + ['label' => 'CT people']);
    register_post_type('zz_ct_hidden', $hidden + ['label' => 'CT hidden']);

    register_taxonomy('zz_ct_topic', ['zz_ct_item'], ['public' => true, 'label' => 'CT topics']);
    register_taxonomy('zz_ct_area', ['zz_ct_item'], ['public' => true, 'label' => 'CT areas']);
    register_taxonomy('zz_ct_role', ['zz_ct_person', 'zz_ct_item'], ['public' => false, 'show_ui' => true, 'label' => 'CT roles']);
    register_taxonomy('zz_ct_secret', ['zz_ct_item'], ['public' => false, 'show_ui' => true, 'label' => 'CT secret']);
}, 20);

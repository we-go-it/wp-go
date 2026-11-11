<?php

/**
 * The features' own settings, as the WP-Go screen shows them below each
 * checkbox and wp_go_settings_sanitize() keeps them (save.php). Their choices
 * come from choices.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The features' own settings, by feature and by the key the feature reads
 * with wp_go_setting(). Each is one of:
 *
 *   multiselect  any of `choices` [value => label], picked in a combobox,
 *                `default` (or none) while unsaved.
 *                With `inverted`, what's stored is what's left unpicked, so a
 *                choice that appears later starts picked (a new post type gets
 *                the Excel buttons). Posted as [key][picked][], so what the
 *                screen posts can't be taken for what's stored
 *                (wp_go_settings_sanitize())
 *   select       one of `choices`, `default` while unsaved. Those in
 *                `disabled` are shown but can't be picked, nor saved
 *   checkbox     on or off, `default` while unsaved
 *   text         a line of text, `default` while unsaved, `placeholder` shown
 *                while empty
 *
 * @return array<string,array<string,array<string,mixed>>>
 */
function wp_go_settings_fields(): array
{
    $editors = _x('Editors and administrators', 'includes/settings/fields.php', 'wp-go');
    $admins = _x('Administrators only', 'includes/settings/fields.php', 'wp-go');
    $hidden = _x('Hidden', 'includes/settings/fields.php', 'wp-go');

    return [
        'editor' => [
            'mode' => [
                'type'        => 'select',
                'label'       => _x('Canvas', 'includes/settings/fields.php', 'wp-go'),
                'description' => wp_go_requirement_met('wp-go-blocks')
                    ? _x('Twig: the theme loads its blocks\' scripts and styles into the canvas. Payload and Nuxt render the blocks as data, with wp-go-blocks; Nuxt needs NUXT_URL in .env and wp-go-blocks\' content API on.', 'includes/settings/fields.php', 'wp-go')
                    : _x('Payload and Nuxt render the blocks as data, which needs the wp-go-blocks package: without it, the canvas is the blocks\' own previews.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => [
                    'twig'    => _x('The blocks\' own previews (Twig)', 'includes/settings/fields.php', 'wp-go'),
                    'payload' => _x('The page as data (Payload)', 'includes/settings/fields.php', 'wp-go'),
                    'nuxt'    => _x('The Nuxt page over the canvas (Nuxt)', 'includes/settings/fields.php', 'wp-go'),
                ],
                // without wp-go-blocks they can't be picked; already picked, it stays
                // (the feature shows Twig meanwhile)
                'disabled'    => wp_go_requirement_met('wp-go-blocks') ? [] : array_values(array_diff(['payload', 'nuxt'], [wp_go_setting('editor', 'mode')])),
                'default'     => 'twig',
            ],
            'shell' => [
                'type'        => 'checkbox',
                'label'       => _x('Page around the canvas', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Twig and Payload: the site\'s header, footer and template markup around the blocks. Nuxt shows its own page.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'inserter' => [
                'type'        => 'checkbox',
                'label'       => _x('"+" between blocks', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('With the page around the canvas, which has no gaps for Gutenberg\'s own: a strip on hover where two blocks meet, its + opening the quick inserter there.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'separators' => [
                'type'        => 'checkbox',
                'label'       => _x('Lines between blocks', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('With the page around the canvas: a faint dashed line where one block ends and the next begins.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'new_block' => [
                'type'        => 'checkbox',
                'label'       => _x('New blocks open in the expanded editor', 'includes/settings/fields.php', 'wp-go'),
                'description' => wp_go_requirement_met('acf')
                    ? _x('A new ACF block with empty fields opens in ACF\'s expanded editor, however it\'s added: Done keeps it, Cancel or Escape removes it.', 'includes/settings/fields.php', 'wp-go')
                    : _x('Needs ACF Pro: not loaded.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'acf_forms' => [
                'type'        => 'checkbox',
                'label'       => _x('Tidier ACF forms', 'includes/settings/fields.php', 'wp-go'),
                'description' => wp_go_requirement_met('acf')
                    ? _x('Collapsible groups, instructions under the label, a full-window expanded editor with a Cancel.', 'includes/settings/fields.php', 'wp-go')
                    : _x('Needs ACF Pro: not loaded.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'post_title' => [
                'type'        => 'checkbox',
                'label'       => _x('Title in the sidebar', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The post\'s title, edited from the sidebar instead of above the blocks.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'meta_box_sidebar' => [
                'type'        => 'checkbox',
                'label'       => _x('Fields tab', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The meta boxes in a Fields tab of the sidebar, between Page and Block, instead of the pane under the canvas.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'sidebar_resize' => [
                'type'        => 'checkbox',
                'label'       => _x('Resizable sidebar', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The editor\'s right sidebar, resized by dragging its left edge.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'changes' => [
                'type'        => 'checkbox',
                'label'       => _x('Unsaved changes', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('What\'s changed and not saved yet in yellow (blocks, fields, a term\'s or an options page\'s), and a Changes button listing it all, old and new.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'sidebar_patches' => [
                'type'        => 'checkbox',
                'label'       => _x('Sidebar patches', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The sidebar\'s form controls with borders and roomier lines.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
        ],
        'cache-tags' => [
            'tracked_types' => [
                'type'        => 'multiselect',
                'label'       => _x('Tracked post types', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Post types with no page of their own that pages still show (a team\'s people): pages listing them are purged when one changes. Those with pages of their own are always tracked.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_hidden_post_types(),
            ],
            'tracked_taxonomies' => [
                'type'        => 'multiselect',
                'label'       => _x('Tracked taxonomies', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Taxonomies with no archive that pages still show (filters, a grid of areas): pages listing them are purged when a term changes. Those with archives are always tracked.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_hidden_taxonomies(),
            ],
            'text_domains' => [
                'type'        => 'multiselect',
                'label'       => _x('Tracked text domains', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Translated texts of these domains tag the pages that print them, file by file: saving a translation in String Translation purges the pages showing that file\'s texts. Site texts\' keys are always tracked, key by key.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_string_domains([get_template()]),
                'default'     => [get_template()],
            ],
            'global_types' => [
                'type'        => 'multiselect',
                'label'       => _x('Global post types', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Shown on every page wherever the template renders them: saving any post of these empties the whole cache. For what the template\'s marked layout (header, footer) doesn\'t already cover.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_object_labels(wp_go_settings_post_types()),
            ],
            'global_taxonomies' => [
                'type'        => 'multiselect',
                'label'       => _x('Global taxonomies', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The same for taxonomies: changing any of their terms empties the whole cache.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_object_labels(wp_go_settings_taxonomies()),
            ],
            'global_texts' => [
                'type'        => 'multiselect',
                'label'       => _x('Global texts', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Files, or Site texts\' keys, whose translated texts are on every page: saving a translation of one of them empties the whole cache.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_text_files(),
            ],
        ],
        'static-cache' => [
            'index' => [
                'type'        => 'select',
                'label'       => _x('Tag index', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Where the list of which pages each tag is on is kept. The pages themselves are always files, served by the web server. Changing it empties the cache.', 'includes/settings/fields.php', 'wp-go') . ' ' . wp_go_settings_redis_status(),
                'choices'     => [
                    'files' => _x('Files, beside the pages', 'includes/settings/fields.php', 'wp-go'),
                    'redis' => _x('Redis', 'includes/settings/fields.php', 'wp-go'),
                ],
                // Redis not answering can't be picked; already picked, it stays
                'disabled'    => wp_go_settings_redis_answers() || wp_go_setting('static-cache', 'index') === 'redis' ? [] : ['redis'],
                'default'     => 'files',
            ],
            'rebuild' => [
                'type'        => 'checkbox',
                'label'       => _x('Rebuild on save', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Render the saved page again in the background, so its visitors find it cached. The other pages a save drops are written again as they\'re visited, so a post shown site-wide never has the whole site rendered at once.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'capability' => [
                'type'        => 'select',
                'label'       => _x('Who sees the menu', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The admin bar\'s Static cache menu: how many pages it holds, and Drop this page.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => ['edit_others_posts' => $editors, 'manage_options' => $admins],
                'default'     => 'edit_others_posts',
            ],
            'admin_bar' => [
                'type'        => 'checkbox',
                'label'       => _x('Admin bar menu', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Show the Static cache menu in the admin bar. Without it, the cache is emptied with wp static-cache clear.', 'includes/settings/fields.php', 'wp-go'),
                'default'     => true,
            ],
            'empty_access' => [
                'type'        => 'select',
                'label'       => _x('Empty button', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('"Empty the cache" in the admin bar menu, after a confirmation: whether it shows, and for whom.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => ['off' => $hidden, 'edit_others_posts' => $editors, 'manage_options' => $admins],
                'default'     => 'edit_others_posts',
            ],
            'warm_access' => [
                'type'        => 'select',
                'label'       => _x('Warm-up button', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('"Warm up the cache" in the admin bar menu, after a confirmation: every public page rendered in the background, those already cached skipped. Whether it shows, and for whom.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => ['off' => $hidden, 'edit_others_posts' => $editors, 'manage_options' => $admins],
                'default'     => 'edit_others_posts',
            ],
        ],
        'site-texts' => [
            'capability' => [
                'type'        => 'select',
                'label'       => _x('Who can use it', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Who writes the texts. Adding and removing keys is administrators\' only.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => ['edit_pages' => $editors, 'manage_options' => $admins],
                'default'     => 'edit_pages',
            ],
            'protected' => [
                'type'        => 'text',
                'label'       => _x('Protected prefixes', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Keys this site\'s code doesn\'t print but another front end does, comma-separated groups or prefixes: reading the code never marks them unused. The theme can add its own in code (wp_go_site_texts_protected).', 'includes/settings/fields.php', 'wp-go'),
                'placeholder' => 'nuxt',
                'default'     => '',
            ],
        ],
        'redirects' => [
            'capability' => [
                'type'        => 'select',
                'label'       => _x('Who can use it', 'includes/settings/fields.php', 'wp-go'),
                'description' => '',
                'choices'     => ['edit_pages' => $editors, 'manage_options' => $admins],
                'default'     => 'edit_pages',
            ],
        ],
        'container-pages' => [
            'post_types' => [
                'type'        => 'multiselect',
                'label'       => _x('Post types', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The post types whose posts can be flagged as containers. The theme can change the list in code (wp_go_container_pages/post_types).', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_object_labels(array_filter(wp_go_settings_post_types(), 'is_post_type_viewable')),
                'default'     => ['page'],
            ],
        ],
        'not-found-page' => [
            'page' => [
                'type'        => 'select',
                'label'       => _x('Page', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Shown, with the 404 status, wherever the site finds nothing; in another language, its translation. Only once published and with content. None: the theme\'s own sentence.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_pages(),
                'default'     => '',
            ],
        ],
        'dev-tools' => [
            'param' => [
                'type'        => 'text',
                'label'       => _x('URL parameter', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('What turns the dev tools on: ?devtools=<secret>, the secret being DEV_TOOLS_SECRET in .env. In development the tools are on anyway, and ?devtools=0 turns them off. Empty: devtools.', 'includes/settings/fields.php', 'wp-go'),
                'placeholder' => 'devtools',
                'default'     => '',
            ],
            'extra_params' => [
                'type'        => 'text',
                'label'       => _x('Other parameters', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('Other URL parameters that only the dev tools use, comma-separated: a page carrying one is never indexed or cached either. The theme can add its own in code (wp_go_dev_tools/params).', 'includes/settings/fields.php', 'wp-go'),
                'placeholder' => 'dev-images',
                'default'     => '',
            ],
            'skip_tools' => [
                'type'        => 'multiselect',
                'inverted'    => true,
                'label'       => _x('Tools', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The dev tools\' panels this site has. One left out gets no button and no shortcut: a site with no forms has no use for the form inspector, one without cache tags for the cache panel.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_dev_tools(),
            ],
        ],
        'import-export' => [
            'skip_post_types' => [
                'type'        => 'multiselect',
                'inverted'    => true,
                'label'       => _x('Post types', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The post types whose admin list has the Excel buttons. A post type added later has them until removed here.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_object_labels(wp_go_settings_post_types()),
            ],
            'skip_taxonomies' => [
                'type'        => 'multiselect',
                'inverted'    => true,
                'label'       => _x('Taxonomies', 'includes/settings/fields.php', 'wp-go'),
                'description' => _x('The taxonomies whose admin list has the Excel buttons. A taxonomy added later has them until removed here.', 'includes/settings/fields.php', 'wp-go'),
                'choices'     => wp_go_settings_object_labels(wp_go_settings_taxonomies()),
            ],
        ],
    ];
}

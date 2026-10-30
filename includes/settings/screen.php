<?php

/**
 * The WP-Go screen: a section per plugin each feature was or was part of,
 * its title and what it does, then a checkbox per feature, saved through the
 * Settings API into WP_GO_OPTION (../../wp-go.php, save.php). Administrators
 * only.
 *
 * Below a feature's checkbox, its own settings (fields.php, controls.php):
 * saved into WP_GO_SETTINGS, read by the feature with wp_go_setting().
 *
 * Each plugin is a card (a <details> styled as a postbox), closed until
 * opened. Its header names the plugins its features need, each marked there
 * or missing, and says how many of its features are on. A button above them
 * collapses them all while any is open, and expands them all once none is.
 *
 * A card whose needed plugin is missing is greyed out and its checkboxes
 * can't be changed (inert, so they're still posted and the choice is kept):
 * the features are loaded once the plugin is there. A need of another feature
 * (Static Cache's Cache Tags) follows that feature's checkbox as it is ticked.
 * What a feature only uses when it's there (WPML) is listed inside the card.
 * A need of one feature of a card greys out its row only.
 *
 * The styles and the behaviour are in settings.css and settings.js, beside
 * this file (built into dist/includes/settings/).
 *
 * A change applies from the next request, the one options.php redirects to.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_enqueue_scripts', 'wp_go_settings_assets');

/** The screen's styles and script, on the screen only, with the texts the script needs. */
function wp_go_settings_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_' . WP_GO_PAGE) {
        return;
    }

    $css = 'includes/settings/settings.css';
    $js  = 'includes/settings/settings.js';

    wp_enqueue_style('wg-settings', wp_go_asset_url($css), [], wp_go_asset_version($css));
    wp_enqueue_script('wg-settings', wp_go_asset_url($js), [], wp_go_asset_version($js), true);
    wp_add_inline_script('wg-settings', 'window.wgSettings = ' . wp_json_encode(['labels' => wp_go_settings_labels()]) . ';', 'before');
}

/**
 * The screen's short texts, printed by it and by settings.js as cards change.
 *
 * @return array<string,string>
 */
function wp_go_settings_labels(): array
{
    return [
        'on'          => _x('On', 'includes/settings/screen.php', 'wp-go'),
        'off'         => _x('Off', 'includes/settings/screen.php', 'wp-go'),
        /* features turned on, of how many */
        'count'       => _x('%1$d of %2$d on', 'includes/settings/screen.php', 'wp-go'),
        'unavailable' => _x('Unavailable', 'includes/settings/screen.php', 'wp-go'),
        'expand'      => _x('Expand all', 'includes/settings/screen.php', 'wp-go'),
        'search'      => _x('Search…', 'includes/settings/screen.php', 'wp-go'),
        /* a picked choice's name */
        'remove'      => _x('Remove %s', 'includes/settings/screen.php', 'wp-go'),
        'empty'       => _x('No matches', 'includes/settings/screen.php', 'wp-go'),
        'collapse'    => _x('Collapse all', 'includes/settings/screen.php', 'wp-go'),
    ];
}

function wp_go_settings_screen(): void
{
    $texts = wp_go_settings_texts();
    // built once: its choices are queries
    $fields = wp_go_settings_fields();
    $plugins = [];

    foreach (WP_GO_FEATURES as $name => $feature) {
        $plugins[ $feature['plugin'] ][] = $name;
    }

    $labels = wp_go_settings_labels();

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(_x('WP-Go', 'includes/settings/screen.php', 'wp-go')); ?></h1>
        <?php
        // "Settings saved": WordPress prints it by itself only under Settings.
        settings_errors();
        ?>
        <p><?php echo esc_html(_x('Turn each feature on or off. A feature turned off isn\'t loaded at all.', 'includes/settings/screen.php', 'wp-go')); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields(WP_GO_PAGE); ?>

            <p><button type="button" class="button wp-go-toggle-all"><?php echo esc_html($labels['expand']); ?></button></p>

            <?php foreach ($plugins as $plugin => $names) :
                [$title, $about] = $texts['plugins'][ $plugin ] ?? [$plugin, ''];

                // what every feature of the card needs is the card's; what
                // only one needs is its row's
                $requires = array_map(fn ($name) => WP_GO_FEATURES[ $name ]['requires'] ?? [], $names);
                $needs = array_values(count($requires) > 1 ? array_intersect(...$requires) : $requires[0]);
                $uses = array_values(array_diff(array_unique(array_merge(...array_map(fn ($name) => WP_GO_FEATURES[ $name ]['uses'] ?? [], $names))), $needs));
                $missing = array_values(array_filter($needs, fn ($need) => !wp_go_requirement_met($need)));
                $on = count(array_filter($names, 'wp_go_feature_chosen'));
                ?>
                <details
                    class="postbox wp-go-card<?php echo $missing ? ' is-unavailable' : ''; ?>"
                    data-features="<?php echo esc_attr(wp_json_encode($names)); ?>"
                    data-needs="<?php echo esc_attr(wp_json_encode(array_values(array_filter($needs, fn ($need) => isset(WP_GO_FEATURES[ $need ]))))); ?>"
                    data-plugins-missing="<?php echo array_diff($missing, array_keys(WP_GO_FEATURES)) ? '1' : ''; ?>"
                >
                    <summary>
                        <h2><?php echo esc_html($title); ?></h2>
                        <span class="wp-go-card__needs">
                            <?php if ($needs) : ?>
                                <?php echo esc_html(_x('Needs', 'includes/settings/screen.php', 'wp-go')); ?>
                                <?php foreach ($needs as $need) : ?>
                                    <span class="wp-go-need <?php echo in_array($need, $missing, true) ? 'is-missing' : 'is-met'; ?>" data-need="<?php echo esc_attr($need); ?>"><?php echo esc_html(wp_go_settings_requirement_name($need)); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </span>
                        <span class="wp-go-card__status<?php echo $on && !$missing ? ' is-on' : ''; ?>">
                            <?php
                            echo esc_html($missing ? $labels['unavailable'] : (count($names) > 1
                                ? sprintf($labels['count'], $on, count($names))
                                : ($on ? $labels['on'] : $labels['off'])));
                            ?>
                        </span>
                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                    </summary>
                    <div class="inside">
                        <p class="wp-go-card__missing">
                            <?php echo esc_html(_x('Not loaded until every plugin it needs is active (or, for a WP-Go feature, turned on).', 'includes/settings/screen.php', 'wp-go')); ?>
                        </p>
                        <p class="wp-go-card__about"><?php echo esc_html($about); ?></p>
                        <?php if ($uses) : ?>
                            <p class="wp-go-card__needs">
                                <?php echo esc_html(_x('Uses, when active:', 'includes/settings/screen.php', 'wp-go')); ?>
                                <?php foreach ($uses as $use) : ?>
                                    <span class="wp-go-need <?php echo wp_go_requirement_met($use) ? 'is-met' : ''; ?>"><?php echo esc_html(wp_go_settings_requirement_name($use)); ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                        <table class="form-table" role="presentation" <?php echo $missing ? 'inert' : ''; ?>>
                            <?php foreach ($names as $name) :
                                // a plugin that is one feature: its checkbox is the plugin's
                                [$label, $description] = count($names) > 1
                                    ? $texts['features'][ $name ] ?? [$name, '']
                                    : [_x('Enabled', 'includes/settings/screen.php', 'wp-go'), ''];
                                $blocked = wp_go_feature_blocked($name);
                                // the card already says what it needs: the row only what's its own
                                $own = $blocked !== null && !in_array($blocked, $needs, true) ? $blocked : null;
                                $id = 'wp-go-' . $name;
                                ?>
                                <tr class="wp-go-row<?php echo $own !== null ? ' is-unavailable' : ''; ?>" <?php echo $own !== null ? 'inert' : ''; ?>>
                                    <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label></th>
                                    <td>
                                        <label>
                                            <input
                                                type="checkbox"
                                                id="<?php echo esc_attr($id); ?>"
                                                name="<?php echo esc_attr(WP_GO_OPTION . "[$name]"); ?>"
                                                value="1"
                                                <?php checked(wp_go_feature_chosen($name)); ?>
                                            >
                                            <?php echo esc_html($description); ?>
                                        </label>
                                        <?php if ($own !== null) : ?>
                                            <p class="description"><?php echo esc_html(wp_go_settings_blocked_text($own)); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php foreach ($fields[ $name ] ?? [] as $key => $field) : ?>
                                    <tr class="wp-go-row wp-go-row--setting">
                                        <th scope="row">
                                            <label for="<?php echo esc_attr("wp-go-$name-$key"); ?>"><?php echo esc_html($field['label']); ?></label>
                                        </th>
                                        <td><?php wp_go_settings_control($name, $key, $field); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </details>
            <?php endforeach; ?>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

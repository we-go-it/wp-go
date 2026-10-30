<?php

/**
 * WP-Go's site-texts feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen, and only on WPML.
 *
 * Site texts: the words the site prints on its own, written per language.
 *
 * Buttons ("Load more"), headings of fixed sections, form messages: texts
 * that aren't content of any page, but still need a value in every
 * language. The code prints a key instead of the text
 * (`wp_go_site_text('events.load_more')`, or the theme's Twig `t()`), and
 * editors write each key's text, per language, on the Site texts screen. Not
 * gettext, not WPML String Translation: the feature keeps the texts itself.
 *
 * In short:
 *
 *   - a key is dotted, `footer.contact_us`: its first part is the group the
 *     screen lists it under ("Footer"), and how the REST route nests it
 *   - a key with no text in the language being printed prints itself, so a
 *     gap shows on the site
 *   - administrators add and remove keys; "Scan code" reads the theme's code
 *     to offer the keys it prints and to point out the ones it doesn't
 *     print any more (nothing is ever removed on its own)
 *   - each text can be given a translation suggestion by the browser
 *     (Chrome's built-in Translator, on-device): typed into the field, never
 *     saved without the editor saving
 *   - the site's languages come from WPML, through WP-Go's includes/i18n.php;
 *     a language removed in WPML keeps its texts, and has them back if added
 *     again
 *
 * Where things are:
 *
 *   includes/texts.php    the stored texts: reading, writing, removing, what a key is
 *   includes/compiled.php the texts a page prints: a PHP file per language, from the option
 *   includes/scan.php     reading the code for the keys it prints
 *   includes/actions.php  what the screen's forms do (save, add a key, add those found)
 *   includes/screen.php   the screen; its styles and script are site-texts.css and site-texts.js, beside this file
 *   includes/rest.php     the texts over REST
 *
 * The same feature is in WP-Go Languages (src/SiteTexts), with the same
 * functions, hooks, option and REST route, so the theme and Cache Tags work
 * with either; this one stands down while that plugin is active (it
 * `requires` WPML). Its files are laid out the same way, function for
 * function, so a diff between the two shows only what really differs: the
 * plugin's domains (which role edits which keys). What one does, the other
 * must do too.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/texts.php';
require_once __DIR__ . '/includes/compiled.php';
require_once __DIR__ . '/includes/scan.php';
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/screen.php';
require_once __DIR__ . '/includes/rest.php';

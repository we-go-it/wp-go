<?php

/** Before WordPress: Nuxt picked with no NUXT_URL, and every piece but the page shell unticked. */

putenv('WG_TEST_EDITOR=' . json_encode(['mode' => 'nuxt', 'shell' => true, 'meta_box_sidebar' => false, 'post_title' => false, 'sidebar_resize' => false, 'acf_forms' => false]));
putenv('WG_TEST_NUXT_URL=');

require __DIR__ . '/small-editor-settings.php';

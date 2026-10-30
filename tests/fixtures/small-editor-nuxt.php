<?php

/** Before WordPress: Nuxt picked, NUXT_URL set, the page shell ticked. */

putenv('WG_TEST_EDITOR=' . json_encode(['mode' => 'nuxt', 'shell' => true, 'meta_box_sidebar' => true]));
putenv('WG_TEST_NUXT_URL=https://nuxt.test/');

require __DIR__ . '/small-editor-settings.php';

// The builder's fields, drawn after CF7's editor (includes/editor.php), moved
// into the Form tab's slot: back inside CF7's <form>, so they submit with it.
// Printed right after them, so it runs as the page parses, before ACF's
// `prepare` and DOM ready.
document.getElementById('wg-cf7-builder-slot')
  ?.replaceWith(document.getElementById('wg-cf7-builder'))

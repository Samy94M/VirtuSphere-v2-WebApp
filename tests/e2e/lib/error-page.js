'use strict';

// C5: the portal's own error renderer (virtusphere_render_error_page() in
// Docker/WebAPI/lib/errors.php) marks its <main> with this attribute. Specs find
// the error page by it, never by the page title: a title can be reworded or
// translated, and a spec matching the old words would then pass silently. The
// attribute also catches the page appended below an already sent portal page,
// which still answers 200 (VE1). tests/error-page-mirror.test.js holds this file,
// errors.php and the page sweeps together.
//
// No Playwright import here, so the node mirror test can load the file without
// node_modules.

const ERROR_PAGE_ATTRIBUTE = 'data-virtusphere-error-page';
const ERROR_PAGE_SELECTOR = `[${ERROR_PAGE_ATTRIBUTE}]`;

module.exports = { ERROR_PAGE_ATTRIBUTE, ERROR_PAGE_SELECTOR };

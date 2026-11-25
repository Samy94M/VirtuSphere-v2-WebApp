<?php

declare(strict_types=1);

// The style of the error page rendered by lib/errors.php, kept on its own so
// the error-handling module stays inside its ADR-0006 size allowance. Loaded
// by lib/errors.php and by nothing else.

/**
 * The style of the error page.
 *
 * The page stands alone on purpose: no layout, no catalog, no session and no
 * portal stylesheet, because any of them may be what failed, and its CSP admits
 * nothing but this one nonce'd block. It still has to look like the portal it
 * interrupts, so it repeats the theme tokens it needs under their own names and
 * with the values of base.css; ErrorPageDesignTest compares every one of them,
 * so the two cannot drift apart. The dark values follow the system setting: the
 * portal keeps its theme choice in localStorage, which a page without script
 * cannot read.
 *
 * Every rule is scoped to the marked main element. The page can be appended below an
 * already sent portal page (VE1), and unscoped rules restyled that page too.
 * Element selectors only: a class here would need a rule in portal/assets/css
 * (A30), which this page does not load.
 */
function virtusphere_error_page_css(): string
{
    $main = 'main[' . VIRTUSPHERE_ERROR_PAGE_ATTRIBUTE . ']';

    return 'body{margin:0}'
        . $main . '{--bg:#f3f5f9;--surface:#ffffff;--surface-muted:#eaeef5;--text:#1a2130;--muted:#555f73;--border:#ccd3e0;'
        . '--accent:#4f46e5;--accent-strong:#4338ca;--btn-bg:var(--accent-strong);--btn-fg:#ffffff}'
        . '@media (prefers-color-scheme:dark){' . $main . '{--bg:#0f131b;--surface:#171d28;--surface-muted:#212837;--text:#e9edf6;--muted:#a6b0c4;--border:#333c4e;'
        . '--accent:#8f8cf8;--accent-strong:#aba9fb;--btn-bg:var(--accent);--btn-fg:#111524}}'
        . $main . '{box-sizing:border-box;min-height:100vh;margin:0;padding:32px 24px;background:var(--bg);color:var(--text);'
        . 'font-family:system-ui,"Segoe UI",Roboto,Arial,Helvetica,sans-serif;font-size:15px;line-height:1.45}'
        . $main . ' *{box-sizing:border-box}'
        . $main . '>section{max-width:720px;margin:0 auto;padding:18px 20px;border:1px solid var(--border);border-radius:8px;background:var(--surface)}'
        . $main . ' h1{margin:0 0 14px;font-size:24px;line-height:1.1}'
        . $main . ' h2{margin:18px 0 14px;font-size:18px;line-height:1.25}'
        . $main . ' p{margin:0 0 14px}'
        . $main . ' dl{display:grid;grid-template-columns:minmax(120px,200px) minmax(0,1fr);gap:8px 16px;margin:0}'
        . $main . ' dt{color:var(--muted);font-weight:700}'
        . $main . ' dd{margin:0;overflow-wrap:anywhere}'
        . $main . ' nav{display:flex;flex-wrap:wrap;gap:10px;margin:18px 0 0}'
        . $main . ' nav a{display:inline-flex;align-items:center;min-height:38px;padding:8px 12px;border:1px solid var(--btn-bg);border-radius:6px;'
        . 'background:var(--surface-muted);color:var(--accent-strong);font-weight:700;text-decoration:none}'
        . $main . ' nav a:first-child{background:var(--btn-bg);color:var(--btn-fg)}'
        . $main . ' nav a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}'
        . $main . ' pre{white-space:pre-wrap;overflow:auto;margin:0;padding:12px;border:1px solid var(--border);border-radius:8px;background:var(--surface-muted)}'
        . '@media (max-width:520px){' . $main . '{padding:18px 14px}' . $main . ' dl{grid-template-columns:minmax(0,1fr)}}';
}

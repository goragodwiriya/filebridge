<?php

declare(strict_types=1);

use FileBridge\App;

require __DIR__ . '/vendor/autoload.php';

$app = App::boot(__DIR__);
$app->startSession();

$appName = htmlspecialchars((string) $app->config['app_name'], ENT_QUOTES);
$theme   = htmlspecialchars((string) $app->config['default_theme'], ENT_QUOTES);
$version = App::VERSION;

// Colour of the browser / OS window chrome before the stylesheets are in -
// the --surface token of each theme. Once the app is running, pwa.js reads that
// token straight from tokens.css and keeps this in step with the theme toggle.
$themeColours = ['dark' => '#14171e', 'light' => '#ffffff'];
$themeColour  = $themeColours[$theme] ?? $themeColours['dark'];

// One version for every module the browser loads. app.js carries the query in
// its script tag; the import map hands the same one to everything app.js pulls
// in - without it those files keep their plain URLs and a browser can end up
// running a fresh module against a cached copy of another, which fails to link
// and leaves a blank page.
$modules = ['api', 'app', 'dialogs', 'panel', 'transfer', 'ui', 'pwa'];
$imports = [];
foreach ($modules as $module) {
    $imports['./assets/js/' . $module . '.js'] = './assets/js/' . $module . '.js?v=' . $version;
}
?>
<!doctype html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark light">
<title><?= $appName ?> · Server to server transfers</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%236366f1'/><g fill='none' stroke='white' stroke-width='1.7' stroke-linecap='round'><path d='M6 8h8M18 16h-8'/><path d='m12 5 3 3-3 3M12 19l-3-3 3-3'/></g></svg>">
<meta name="theme-color" content="<?= $themeColour ?>">
<meta name="application-name" content="<?= $appName ?>">
<!-- Installable app: manifest.php names it, sw.php caches the shell. -->
<link rel="manifest" href="manifest.php?v=<?= $version ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= $appName ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png?v=<?= $version ?>">
<link rel="stylesheet" href="assets/css/tokens.css?v=<?= $version ?>">
<link rel="stylesheet" href="assets/css/components.css?v=<?= $version ?>">
<link rel="stylesheet" href="assets/css/layout.css?v=<?= $version ?>">
<script type="importmap"><?= json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES) ?></script>
<script>
    // Apply the stored theme before first paint so there is no flash - the
    // installed app shows this colour in its title bar, so it moves too.
    var colours = <?= json_encode($themeColours) ?>;
    try {
        var t = localStorage.getItem('fb.theme');
        if (t && colours[t]) {
            document.documentElement.dataset.theme = t;
            document.querySelector('meta[name="theme-color"]').setAttribute('content', colours[t]);
        }
    } catch (e) {}
</script>
</head>
<body>

<?php readfile(__DIR__ . '/assets/icons/sprite.svg'); ?>

<!-- ── Sign in / first run ─────────────────────────────────────────────── -->
<div class="auth" id="auth" hidden>
    <div class="auth-card">
        <div class="brand">
            <span class="mark"><svg class="icon icon-lg"><use href="#i-logo"></use></svg></span>
            <?= $appName ?>
        </div>
        <p class="lead" id="auth-lead">Sign in to manage your connections.</p>
        <div class="auth-error" id="auth-error" hidden>
            <svg class="icon icon-sm"><use href="#i-alert"></use></svg>
            <span></span>
        </div>
        <form id="auth-form" autocomplete="on">
            <div class="field">
                <label for="auth-user">Username</label>
                <input class="input" id="auth-user" name="username" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="auth-pass">Password</label>
                <input class="input" id="auth-pass" name="password" type="password" autocomplete="current-password" required>
            </div>
            <div class="field" id="auth-confirm-field" hidden>
                <label for="auth-confirm">Confirm password</label>
                <input class="input" id="auth-confirm" name="confirm" type="password" autocomplete="new-password">
                <span class="hint">At least 8 characters. This account is stored in config/users.php.</span>
            </div>
            <button class="btn btn-primary btn-block" id="auth-submit" type="submit">Sign in</button>
        </form>
        <p class="auth-foot">Every transfer is written to the audit log.</p>
    </div>
</div>

<!-- ── Application ─────────────────────────────────────────────────────── -->
<div id="app" hidden>

    <header class="topbar">
        <button class="icon-btn" id="btn-sidebar" title="Connections" hidden>
            <svg class="icon"><use href="#i-list"></use></svg>
        </button>
        <div class="brand">
            <span class="mark"><svg class="icon icon-lg"><use href="#i-logo"></use></svg></span>
            <?= $appName ?><span class="ver">v<?= $version ?></span>
        </div>
        <span class="spacer"></span>
        <div class="topbar-tools">
            <button class="queue-chip" id="btn-queue" title="Transfer queue">
                <svg class="icon icon-sm"><use href="#i-list"></use></svg>
                Queue
                <span class="count" id="queue-count" hidden>0</span>
            </button>
            <button class="icon-btn" id="btn-install" title="Install as an app" hidden>
                <svg class="icon"><use href="#i-install"></use></svg>
            </button>
            <button class="icon-btn" id="btn-help" title="Keyboard shortcuts (?)">
                <svg class="icon"><use href="#i-info"></use></svg>
            </button>
            <button class="icon-btn" id="btn-theme" title="Toggle theme">
                <svg class="icon" id="theme-icon"><use href="#i-moon"></use></svg>
            </button>
            <button class="user-chip" id="btn-user">
                <span class="avatar" id="user-initial">A</span>
                <span id="user-name">admin</span>
                <svg class="icon icon-sm"><use href="#i-chevron-down"></use></svg>
            </button>
        </div>
    </header>

    <div class="workspace">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-head">
                <h2>Connections</h2>
                <button class="icon-btn" id="btn-add-site" title="New connection">
                    <svg class="icon"><use href="#i-plus"></use></svg>
                </button>
            </div>
            <ul class="site-list" id="site-list"></ul>
            <div class="sidebar-foot" id="backend-info"></div>
        </aside>

        <div style="display:flex;flex-direction:column;min-height:0">
            <div class="mobile-tabs" id="mobile-tabs">
                <button data-side="left" aria-selected="true">Left</button>
                <button data-side="right" aria-selected="false">Right</button>
            </div>
            <main class="panels" id="panels">
                <!-- panels injected here -->
                <div class="rail" id="rail">
                    <button class="rail-btn" data-act="to-right" title="Copy to the right panel (F5)" disabled>
                        <svg class="icon icon-lg"><use href="#i-arrow-right"></use></svg>
                    </button>
                    <button class="rail-btn" data-act="to-left" title="Copy to the left panel (F5)" disabled>
                        <svg class="icon icon-lg"><use href="#i-arrow-left"></use></svg>
                    </button>
                    <span class="rail-sep"></span>
                    <button class="rail-btn small" data-act="move" title="Move selection (F6)" disabled>
                        <svg class="icon"><use href="#i-swap"></use></svg>
                    </button>
                    <button class="rail-btn small" data-act="sync-path" title="Open the same path on the other side">
                        <svg class="icon"><use href="#i-link"></use></svg>
                    </button>
                    <span class="rail-hint">F5 copy<br>F6 move</span>
                </div>
            </main>
        </div>
    </div>

    <footer class="queue" id="queue">
        <div class="queue-head" id="queue-head">
            <svg class="icon icon-sm"><use href="#i-list"></use></svg>
            <h2>Transfer queue</h2>
            <span class="badge" id="queue-summary">idle</span>
            <span class="spacer"></span>
            <button class="btn btn-sm btn-ghost" id="btn-clear-queue">Clear finished</button>
            <svg class="icon chev"><use href="#i-chevron-down"></use></svg>
        </div>
        <div class="queue-body" id="queue-body" hidden></div>
    </footer>
</div>

<div class="toasts" id="toasts"></div>

<!-- ── Panel template ──────────────────────────────────────────────────── -->
<template id="tpl-panel">
    <section class="panel" tabindex="0">
        <div class="panel-head">
            <div class="conn-row">
                <span class="dot" data-el="dot"></span>
                <select class="select" data-el="site"></select>
                <span class="desc" data-el="desc"></span>
            </div>
            <div class="path-row">
                <button class="icon-btn" data-act="home" title="Home"><svg class="icon icon-sm"><use href="#i-home"></use></svg></button>
                <button class="icon-btn" data-act="up" title="Up one level (Backspace)"><svg class="icon icon-sm"><use href="#i-arrow-up"></use></svg></button>
                <nav class="crumbs" data-el="crumbs"></nav>
                <button class="icon-btn" data-act="refresh" title="Refresh (Ctrl+R)"><svg class="icon icon-sm"><use href="#i-refresh"></use></svg></button>
            </div>
            <div class="tool-row">
                <div class="input-group">
                    <svg class="icon icon-sm"><use href="#i-search"></use></svg>
                    <input class="input" data-el="filter" placeholder="Filter this folder…" spellcheck="false">
                </div>
                <span class="divider"></span>
                <button class="icon-btn" data-act="mkdir" title="New folder (F7)"><svg class="icon icon-sm"><use href="#i-folder-plus"></use></svg></button>
                <button class="icon-btn" data-act="upload" title="Upload files"><svg class="icon icon-sm"><use href="#i-upload"></use></svg></button>
                <button class="icon-btn" data-act="download" title="Download selection"><svg class="icon icon-sm"><use href="#i-download"></use></svg></button>
                <button class="icon-btn" data-act="delete" title="Delete selection (Del)"><svg class="icon icon-sm"><use href="#i-trash"></use></svg></button>
                <button class="icon-btn" data-act="hidden" title="Show hidden files"><svg class="icon icon-sm"><use href="#i-eye-off"></use></svg></button>
            </div>
        </div>
        <div class="list-wrap" data-el="listWrap">
            <table class="files">
                <thead>
                    <tr>
                        <th class="col-check"><span class="checkbox" data-el="checkAll"><svg class="icon"><use href="#i-check"></use></svg></span></th>
                        <th class="col-name" data-sort="name">Name <svg class="icon"><use href="#i-chevron-down"></use></svg></th>
                        <th class="col-size" data-sort="size">Size <svg class="icon"><use href="#i-chevron-down"></use></svg></th>
                        <th class="col-date" data-sort="mtime">Modified <svg class="icon"><use href="#i-chevron-down"></use></svg></th>
                        <th class="col-perm" data-sort="perms">Perms <svg class="icon"><use href="#i-chevron-down"></use></svg></th>
                    </tr>
                </thead>
                <tbody data-el="rows"></tbody>
            </table>
            <div data-el="state"></div>
        </div>
        <div class="panel-foot">
            <span data-el="stats">—</span>
            <span class="spacer"></span>
            <span data-el="selstats"></span>
        </div>
        <input type="file" data-el="fileInput" multiple hidden>
    </section>
</template>

<script type="module" src="assets/js/app.js?v=<?= $version ?>"></script>
</body>
</html>

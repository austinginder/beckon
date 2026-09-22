<?php
/**
 * Beckon Changelog Generator
 *
 * Usage:
 * - Web: visit in a browser to see the changelog (cached for 24h).
 * - CLI: `php changelog.php` writes a static changelog.html from the changelog on GitHub main.
 *        `php changelog.php --local` reads ../changelog.md instead (preview unreleased entries).
 */

$sourceUrl = 'https://raw.githubusercontent.com/austinginder/beckon/refs/heads/main/changelog.md';
$localFile = dirname(__DIR__) . '/changelog.md';
$cacheFile = __DIR__ . '/cache/changelog.json';
$outputFile = __DIR__ . '/changelog.html';
$cacheDuration = 86400;
$isCli = php_sapi_name() === 'cli';
$useLocal = $isCli && in_array('--local', $argv ?? [], true);

if (!is_dir(dirname($cacheFile))) {
    mkdir(dirname($cacheFile), 0755, true);
}

function parseChangelog($raw) {
    if ($raw === false || $raw === null) return null;
    $raw = preg_replace('/^# Changelog\s+/', '', $raw);

    // "## [2.0.0] - 2026-10-01", "## [2.0.0] - Unreleased" and "## [Unreleased]" are all accepted.
    $versions = [];
    preg_match_all('/## \[([^\]]+)\](?:\s*-\s*(\S+))?(.*?)(?=\n## \[|$)/s', $raw, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $versionNum = $match[1];
        $date = $match[2] ?? '';
        $body = $match[3];
        $unreleased = strcasecmp($versionNum, 'Unreleased') === 0 || strcasecmp($date, 'Unreleased') === 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
        $sections = [];

        preg_match_all('/### (.*?)\n(.*?)(?=### |$)/s', $body, $sectionMatches, PREG_SET_ORDER);
        foreach ($sectionMatches as $section) {
            $title = trim($section[1]);
            $content = trim($section[2]);
            preg_match_all('/\*\s+(.*?)(?=\n\*|\n$|$)/s', $content, $listMatches);
            $items = array_map(function ($item) {
                $item = htmlspecialchars(trim($item), ENT_QUOTES, 'UTF-8');
                $item = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $item);
                $item = preg_replace('/`(.*?)`/', '<code>$1</code>', $item);
                return $item;
            }, $listMatches[1]);
            $sections[] = ['title' => $title, 'items' => $items];
        }

        $versions[] = [
            'version' => strcasecmp($versionNum, 'Unreleased') === 0 ? 'Unreleased' : $versionNum,
            'date' => $unreleased ? '' : $date,
            'unreleased' => $unreleased,
            'sections' => $sections,
        ];
    }
    return $versions;
}

$data = null;
if ($useLocal) {
    $data = parseChangelog(@file_get_contents($localFile));
    echo $data ? "Parsed local changelog.md (" . count($data) . " releases).\n" : "Could not read $localFile\n";
} else {
    $needsUpdate = true;
    if (file_exists($cacheFile)) {
        $cacheContent = json_decode(file_get_contents($cacheFile), true);
        if ($cacheContent && (time() - $cacheContent['timestamp'] < $cacheDuration)) {
            $data = $cacheContent['data'];
            $needsUpdate = false;
        }
    }
    if ($isCli && !file_exists($cacheFile)) $needsUpdate = true;
    if ($needsUpdate) {
        $context = stream_context_create(['http' => ['user_agent' => 'BeckonChangelogFetcher/1.0']]);
        $parsed = parseChangelog(@file_get_contents($sourceUrl, false, $context));
        if ($parsed) {
            $data = $parsed;
            file_put_contents($cacheFile, json_encode(['timestamp' => time(), 'data' => $data]));
            if ($isCli) echo "Fetched changelog from GitHub.\n";
        } elseif ($data === null && file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true)['data'];
            if ($isCli) echo "Fetch failed. Using cached data.\n";
        }
    } elseif ($isCli) {
        echo "Using cached data (expires in " . round(($cacheDuration - (time() - $cacheContent['timestamp'])) / 3600, 1) . "h).\n";
    }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Changelog - Beckon</title>
    <meta name="description" content="Every Beckon release, newest first.">
    <link rel="icon" href="https://beckon.run/content/16/beckon-icon.webp">
    <script src="/content/16/mu-plugins/captaincore-analytics.js" data-site="MKGSCHCT" defer></script>
    <script>(function(){try{var t=localStorage.getItem('beckon_site_theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
    <style>
        :root { --bg: #eef0f4; --bg-2: #e6e9ee; --surface: #ffffff; --surface-2: #f6f7f9; --surface-3: #eceef2; --text: #151a24; --text-2: #4b5563; --muted: #7b8494; --line: #dfe3e9; --line-strong: #c9cfd8; --accent: #3556f5; --accent-soft: #e6eaff; --accent-ink: #1f38b8; --brand: #f2b134; --brand-ink: #1a1f2b; --ok: #16a34a; --shadow: 0 30px 80px rgba(16,24,40,.18), 0 4px 12px rgba(16,24,40,.08); --font: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Inter", "Segoe UI", Roboto, Helvetica, Arial, sans-serif; --mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace; color-scheme: light; }
        @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --bg: #0f1218; --bg-2: #0a0d12; --surface: #171b23; --surface-2: #1d222c; --surface-3: #252b36; --text: #e8ebf0; --text-2: #b6bcc7; --muted: #8b93a1; --line: #2a303b; --line-strong: #3a4150; --accent: #6b83ff; --accent-soft: #232a4a; --accent-ink: #b9c4ff; --ok: #4ade80; --shadow: 0 40px 100px rgba(0,0,0,.55), 0 4px 12px rgba(0,0,0,.3); color-scheme: dark; } }
        :root[data-theme="dark"] { --bg: #0f1218; --bg-2: #0a0d12; --surface: #171b23; --surface-2: #1d222c; --surface-3: #252b36; --text: #e8ebf0; --text-2: #b6bcc7; --muted: #8b93a1; --line: #2a303b; --line-strong: #3a4150; --accent: #6b83ff; --accent-soft: #232a4a; --accent-ink: #b9c4ff; --ok: #4ade80; --shadow: 0 40px 100px rgba(0,0,0,.55), 0 4px 12px rgba(0,0,0,.3); color-scheme: dark; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text-2); font-family: var(--font); font-size: 16px; line-height: 1.6; -webkit-font-smoothing: antialiased; display: flex; flex-direction: column; min-height: 100vh; }
        a { color: var(--accent-ink); text-decoration: none; }
        h1, h2, h3 { color: var(--text); letter-spacing: -.02em; line-height: 1.15; margin: 0; }
        code { font-family: var(--mono); font-size: .9em; background: var(--surface-3); padding: .12em .4em; border-radius: 5px; color: var(--accent-ink); }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 24px; width: 100%; }
        .i { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        nav { position: sticky; top: 0; z-index: 20; background: color-mix(in srgb, var(--surface) 82%, transparent); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
        nav .wrap { display: flex; align-items: center; height: 64px; gap: 8px; }
        .logo { display: flex; align-items: center; gap: 10px; color: var(--text); font-weight: 800; font-size: 17px; }
        .logo svg { width: 30px; height: 30px; border-radius: 8px; }
        .links { margin-left: auto; display: flex; align-items: center; gap: 4px; }
        .links a, .links button { padding: 8px 12px; border-radius: 8px; color: var(--text-2); font-weight: 500; font-size: 14px; background: none; border: 0; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; }
        .links a:hover, .links button:hover { background: var(--surface-3); color: var(--text); }
        .links a.gh { border: 1px solid var(--line-strong); background: var(--surface); color: var(--text); font-weight: 700; margin-left: 8px; }
        .head { text-align: center; padding: 72px 24px 40px; position: relative; overflow: hidden; }
        .head::before { content: ""; position: absolute; inset: -30% -10% auto; height: 120%; background: radial-gradient(ellipse at 50% 20%, color-mix(in srgb, var(--accent) 18%, transparent), transparent 55%); pointer-events: none; }
        .head h1 { font-size: clamp(36px, 6vw, 52px); font-weight: 800; letter-spacing: -.035em; position: relative; }
        .head p { color: var(--muted); font-size: 18px; margin: 12px auto 0; max-width: 560px; position: relative; }
        .eyebrow { font-family: var(--mono); font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--brand); font-weight: 700; display: block; margin-bottom: 12px; position: relative; }
        main { flex: 1; padding-bottom: 80px; }
        .log { max-width: 860px; margin: 0 auto; display: grid; gap: 22px; position: relative; }
        .log::before { content: ""; position: absolute; left: 110px; top: 20px; bottom: 20px; width: 1px; background: var(--line); }
        .rel { display: grid; grid-template-columns: 90px 1fr; gap: 40px; position: relative; }
        .rel .v { text-align: right; padding-top: 24px; }
        .rel .v b { display: inline-block; font-family: var(--mono); font-size: 13px; font-weight: 700; color: var(--accent-ink); background: var(--accent-soft); padding: 3px 9px; border-radius: 7px; }
        .rel .v b.un { background: rgba(242,177,52,.18); color: var(--brand-ink); }
        :root[data-theme="dark"] .rel .v b.un { color: var(--brand); } @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .rel .v b.un { color: var(--brand); } }
        .rel .v small { display: block; margin-top: 6px; font-size: 12px; color: var(--muted); }
        .rel .dot { position: absolute; left: 105px; top: 30px; width: 11px; height: 11px; border-radius: 50%; background: var(--surface); border: 2px solid var(--line-strong); }
        .rel:first-child .dot { background: var(--brand); border-color: var(--brand); }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 24px 28px; box-shadow: 0 1px 2px rgba(16,24,40,.06); }
        .card h3 { font-size: 15px; margin: 0 0 12px; }
        .card h3 + ul { margin-top: 0; }
        .card ul { list-style: none; padding: 0; margin: 0 0 22px; display: grid; gap: 9px; }
        .card ul:last-child { margin-bottom: 0; }
        .card li { position: relative; padding-left: 18px; font-size: 14.5px; color: var(--text-2); }
        .card li::before { content: ""; position: absolute; left: 0; top: 10px; width: 6px; height: 6px; border-radius: 50%; background: var(--line-strong); }
        .card li strong { color: var(--text); }
        .empty { text-align: center; color: var(--muted); padding: 60px 20px; border: 1px dashed var(--line-strong); border-radius: 14px; max-width: 860px; margin: 0 auto; }
        footer { border-top: 1px solid var(--line); padding: 48px 0 36px; text-align: center; margin-top: auto; }
        footer p { color: var(--muted); font-size: 14px; max-width: 460px; margin: 0 auto 18px; }
        footer .row { display: flex; justify-content: center; gap: 22px; margin-bottom: 22px; }
        footer .row a { color: var(--text-2); font-weight: 600; font-size: 14px; }
        footer small { display: block; color: var(--muted); font-size: 12.5px; line-height: 1.9; }
        footer small a { color: var(--text-2); }
        @media (max-width: 720px) { .links a:not(.gh) { display: none; } .log::before { display: none; } .rel { grid-template-columns: 1fr; gap: 10px; } .rel .v { text-align: left; padding-top: 0; } .rel .dot { display: none; } .card { padding: 20px; } }
    </style>
</head>
<body>
    <nav>
        <div class="wrap">
            <a href="/" class="logo" aria-label="Beckon home"><svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#f2b134"/><path d="M13 24h6l-1.2-9h-3.6z" fill="#1a1f2b"/><path d="M12.5 13.5h7" stroke="#1a1f2b" stroke-width="2" stroke-linecap="round"/><circle cx="16" cy="10.5" r="2.2" fill="#1a1f2b"/><path d="M9.5 9l2.4 1.2M22.5 9l-2.4 1.2M8.2 13.2l2.6-.4M23.8 13.2l-2.6-.4" stroke="#1a1f2b" stroke-width="1.6" stroke-linecap="round"/><path d="M10 25.5h12" stroke="#1a1f2b" stroke-width="2" stroke-linecap="round"/></svg>Beckon</a>
            <div class="links">
                <a href="/#new">What's new</a>
                <a href="/#features">Features</a>
                <a href="/#install">Install</a>
                <a href="/changelog/">Changelog</a>
                <button id="theme-toggle" title="Toggle light / dark. Right-click for system" aria-label="Toggle theme"></button>
                <a href="https://github.com/austinginder/beckon" class="gh">GitHub</a>
            </div>
        </div>
    </nav>

    <div class="head">
        <span class="eyebrow">Ship log</span>
        <h1>Every release, newest first.</h1>
        <p>Beckon updates itself from GitHub. This is what each release changed.</p>
    </div>

    <main>
        <div class="wrap">
        <?php if (!$data): ?>
            <div class="empty">The changelog could not be loaded. It also lives in the <a href="https://github.com/austinginder/beckon/blob/main/changelog.md">repository</a>.</div>
        <?php else: ?>
            <div class="log">
            <?php foreach ($data as $release): ?>
                <div class="rel">
                    <span class="dot"></span>
                    <div class="v">
                        <b class="<?php echo $release['unreleased'] ? 'un' : ''; ?>"><?php echo $release['version'] === 'Unreleased' ? 'Next' : 'v' . htmlspecialchars($release['version']); ?></b>
                        <small><?php echo $release['unreleased'] ? 'Unreleased' : date('M j, Y', strtotime($release['date'])); ?></small>
                    </div>
                    <div class="card">
                        <?php foreach ($release['sections'] as $section): ?>
                            <h3><?php echo htmlspecialchars($section['title']); ?></h3>
                            <ul><?php foreach ($section['items'] as $item): ?><li><?php echo $item; ?></li><?php endforeach; ?></ul>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </main>

    <footer>
        <div class="wrap">
            <p>Beckon is a small, self-hosted project board made by <a href="https://austinginder.com">Austin Ginder</a>.</p>
            <div class="row"><a href="https://github.com/austinginder/beckon">GitHub</a><a href="/">Home</a><a href="https://github.com/austinginder/beckon/blob/main/cli.md">CLI docs</a><a href="https://x.com/austinginder">X</a></div>
            <small>&copy; 2026 <a href="https://austinginder.com">Austin Ginder</a>. MIT licensed.<br>Part of the fleet: <a href="https://captaincore.com">CaptainCore</a> · <a href="https://wpfreighter.com">WP Freighter</a> · <a href="https://cove.run">Cove</a></small>
        </div>
    </footer>
    <script>
    (function () {
        const isDark = () => { const t = document.documentElement.getAttribute('data-theme'); return t ? t === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches; };
        const paint = () => { document.getElementById('theme-toggle').innerHTML = isDark() ? '<svg class="i" viewBox="0 0 24 24"><path d="M12 16.5a4.5 4.5 0 100-9 4.5 4.5 0 000 9zM12 3v1.8M12 19.2V21M3 12h1.8M19.2 12H21M5.6 5.6l1.3 1.3M17.1 17.1l1.3 1.3M5.6 18.4l1.3-1.3M17.1 6.9l1.3-1.3"/></svg>' : '<svg class="i" viewBox="0 0 24 24"><path d="M20 14.2A8 8 0 019.8 4a8 8 0 1010.2 10.2z"/></svg>'; };
        const set = (m) => { if (m === 'system') { document.documentElement.removeAttribute('data-theme'); try { localStorage.removeItem('beckon_site_theme'); } catch (e) {} } else { document.documentElement.setAttribute('data-theme', m); try { localStorage.setItem('beckon_site_theme', m); } catch (e) {} } paint(); };
        paint();
        document.getElementById('theme-toggle').addEventListener('click', () => set(isDark() ? 'light' : 'dark'));
        document.getElementById('theme-toggle').addEventListener('contextmenu', (e) => { e.preventDefault(); set('system'); });
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', paint);
    })();
    </script>
</body>
</html>
<?php
$html = ob_get_clean();
if ($isCli) {
    file_put_contents($outputFile, $html);
    echo "Wrote " . basename($outputFile) . "\n";
} else {
    echo $html;
}

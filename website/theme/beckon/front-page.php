<?php
/**
 * Front page: the landing. The changelog popup is rendered server-side by beckon_changelog_popup().
 */
get_header(); ?>


    <header class="hero">
        <div class="wrap">
            <div class="hero-top">
                <button class="pill pill-btn" data-cl-open title="See what changed"><b>v<?php echo esc_html(beckon_latest_version()); ?></b> Rebuilt with zero dependencies <svg class="i sm" viewBox="0 0 24 24"><path d="M9.5 6l6 6-6 6"/></svg></button>
                <h1>Where Markdown <span>charts the course</span></h1>
                <p class="lead">A self-hosted Kanban board in a single PHP file. Boards are folders, cards are Markdown, and there is no database, no build step and no third-party JavaScript.</p>
                <div class="cta">
                    <a class="btn brand" href="https://github.com/austinginder/beckon/releases/latest/download/index.php"><svg class="i" viewBox="0 0 24 24"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M4 16.5v2A1.5 1.5 0 005.5 20h13a1.5 1.5 0 001.5-1.5v-2"/></svg> Download index.php</a>
                    <a class="btn" href="https://github.com/austinginder/beckon"><svg class="i" viewBox="0 0 24 24"><path d="M8.5 8L4 12l4.5 4M15.5 8l4.5 4-4.5 4M13.5 5.5l-3 13"/></svg> View source</a>
                </div>
                <p class="fine">MIT licensed · one file · around 270 KB · PHP 8</p>
            </div>

            <!-- Live board mock -->
            <div class="frame" id="frame">
                <div class="frame-bar"><i></i><i></i><i></i><span>beckon.run/?board=voyage</span><span class="try"><i></i> Live demo: drag a card, open one, or right-click it</span></div>
                <div class="mb" id="mb"></div>
                <div class="mb-hint">Nothing here is saved. Reload to reset.</div>
                <div class="mw" id="mw">
                    <div class="mw-win">
                        <div class="mw-head">
                            <div style="flex:1;min-width:0"><input id="mw-title" spellcheck="false"><div class="mw-sub" id="mw-sub"></div></div>
                            <div class="seg" id="mw-seg"><button data-v="edit"><svg class="i sm" viewBox="0 0 24 24"><path d="M12 20h8M16.5 3.5a2.1 2.1 0 013 3L8 18l-4 1 1-4L16.5 3.5z"/></svg>Edit</button><button data-v="split" class="on"><svg class="i sm" viewBox="0 0 24 24"><path d="M4 6.5A1.5 1.5 0 015.5 5h13A1.5 1.5 0 0120 6.5v11a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5v-11zM12 5v14"/></svg>Split</button><button data-v="preview"><svg class="i sm" viewBox="0 0 24 24"><path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6zM14.8 12a2.8 2.8 0 11-5.6 0 2.8 2.8 0 015.6 0z"/></svg>Preview</button></div>
                            <button class="xbtn" id="mw-close" aria-label="Close"><svg class="i" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                        </div>
                        <div class="mw-body">
                            <div class="pane" id="mw-editor"><div class="pane-head">Markdown<span class="stat" id="mw-stat"></span></div><textarea id="mw-ta" spellcheck="false"></textarea></div>
                            <div class="pane" id="mw-preview"><div class="pane-head">Preview</div><div class="md" id="mw-md"></div></div>
                            <aside class="mw-side" id="mw-side"></aside>
                        </div>
                        <div class="mw-foot">Comments <span style="opacity:.6">0</span> &nbsp; Activity <span style="opacity:.6">1</span> &nbsp; Revisions <span style="opacity:.6">0</span><span class="prog" id="mw-prog"></span></div>
                    </div>
                </div>
            </div>

            <div class="facts">
                <div class="fact"><b>1</b><span>file to deploy</span></div>
                <div class="fact"><b>0</b><span>databases, frameworks or CDNs</span></div>
                <div class="fact"><b>.md</b><span>one per card, readable anywhere</span></div>
                <div class="fact"><b>MIT</b><span>free and open source</span></div>
            </div>
        </div>
    </header>

    <section class="new" id="new">
        <div class="wrap new-grid">
            <div>
                <span class="eyebrow">New in 2.0</span>
                <h2>A new interface, built from scratch.</h2>
                <p class="lead">Version 2 replaces Vue and Tailwind with hand-written CSS and JavaScript, a custom icon set, and Beckon's own Markdown engine. Everything the page needs ships inside <code>index.php</code>. Nothing is fetched from a CDN, so the board works on an airplane and inside a locked-down network.</p>
                <ul>
                    <li><svg class="i" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg><span><b>Search across every board.</b> Full-text search over titles, descriptions, comments and labels. Press <kbd>/</kbd>.</span></li>
                    <li><svg class="i" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg><span><b>Live reload.</b> Edit a card file on disk, or from another tab, and the board updates itself.</span></li>
                    <li><svg class="i" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg><span><b>Publish to WordPress.</b> Send a card to any WordPress site as a draft, images and cover included.</span></li>
                    <li><svg class="i" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg><span><b>Light and dark.</b> Follows your system, or pin one. No flash on load.</span></li>
                    <li><svg class="i" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg><span><b>Emoji everywhere.</b> Type <code>:</code> in the editor or a comment. React to comments with a picker.</span></li>
                    <li><svg class="i" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg><span><b>A command line.</b> <code>beckon-cli.php</code> creates, lists, imports and exports cards for scripts.</span></li>
                    <li><svg class="i" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg><span><b>Built for phones too.</b> Snap-scrolling columns, a full-screen card view, long-press menus.</span></li>
                </ul>
            </div>
            <div class="card-shot"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/img/shot-card-dark.webp" alt="The card editor with a Markdown pane, live preview, checklist and labels" width="1440" height="900" loading="lazy"></div>
        </div>
    </section>

    <section class="center" id="search">
        <div class="wrap">
            <span class="eyebrow">Search</span>
            <h2 style="font-size:36px">Find anything, on any board.</h2>
            <p class="lead" style="margin-top:12px">Every title, description, comment and label is indexed the moment it changes. This one searches a handful of demo cards. Try <kbd>release</kbd> or <kbd>import</kbd>.</p>
            <div class="search-demo">
                <div class="sd-in"><svg class="i" viewBox="0 0 24 24" style="color:var(--muted)"><path d="M16.5 16.5L21 21M18 10.5a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z"/></svg><input id="sd-q" placeholder="Search cards across all boards…" autocomplete="off"><kbd>esc</kbd></div>
                <div class="sd-chips" id="sd-chips"></div>
                <div class="sd-list" id="sd-list"></div>
                <div class="sd-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> navigate &nbsp; <kbd>↵</kbd> open</span><span>SQLite FTS5</span></div>
            </div>
        </div>
    </section>

    <section class="features" id="features">
        <div class="wrap">
            <h2>Everything a board needs. Nothing it doesn't.</h2>
            <p class="lead">Beckon is small on purpose. Each feature earns its place by making the plain files more useful.</p>
            <div class="grid">
                <div class="feat amber"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M4 6.5h16M4 12h16M4 17.5h9"/></svg></div><h3>Markdown native</h3><p>Every card is a <code>.md</code> file. Split-pane editing with live preview, task lists that roll up into progress on the board, tables, code and images.</p></div>
                <div class="feat"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M4 13l2.2-7.4A1.5 1.5 0 017.6 4.5h8.8a1.5 1.5 0 011.4 1.1L20 13v5.5a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 18.5V13zM4 13h4.5l1.5 2.5h4l1.5-2.5H20"/></svg></div><h3>Flat files, no database</h3><p>A board is a folder. Back it up by copying it, sync it with Dropbox or Nextcloud, or keep it in git. You can read every card with a text editor.</p></div>
                <div class="feat"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M12 16V5M7.5 9.5L12 5l4.5 4.5M4 16.5v2A1.5 1.5 0 005.5 20h13a1.5 1.5 0 001.5-1.5v-2"/></svg></div><h3>One file to deploy</h3><p>Upload <code>index.php</code> to any host with PHP and open it. No composer, no npm, no build. Updates install themselves from GitHub in one click.</p></div>
                <div class="feat"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M16.5 16.5L21 21M18 10.5a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z"/></svg></div><h3>Search</h3><p>Full-text search across all boards, backed by SQLite FTS5. Filter by board, jump straight to a card, keyboard all the way.</p></div>
                <div class="feat"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M4 12a8 8 0 108-8 8 8 0 00-6.3 3.1M4 4v4h4M12 8v4l2.5 2"/></svg></div><h3>Revision history</h3><p>Every change to a description is kept. Scrub back through old versions with a slider and restore any of them.</p></div>
                <div class="feat"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M4 12h16M14 6l6 6-6 6"/></svg></div><h3>Drag and drop</h3><p>Reorder cards and lists. Drop or paste images into the editor and they upload beside the board. Set any upload as a card cover.</p></div>
                <div class="feat"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M4 5.5h16v10H4zM12 15.5V19M8.5 19h7M9 12l2.5-2.5 2 2L16 8"/></svg></div><h3>Present and export</h3><p>Show a card full screen for a meeting, or export it as a standalone HTML file with the images embedded.</p></div>
                <div class="feat"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M20 12a8 8 0 01-14.5 4.6M4 12a8 8 0 0114.5-4.6M18.5 3.5v4h-4M5.5 20.5v-4h4"/></svg></div><h3>Trello import</h3><p>Bring over lists, cards, labels, checklists, comments, members and attachments. Private boards work too with a pasted cURL command.</p></div>
                <div class="feat amber"><div class="ic"><svg class="i" viewBox="0 0 24 24"><path d="M4 12l16-8-4 16-4-6-8-2z"/></svg></div><h3>Publish to WordPress</h3><p>Draft a post as a card, then send it to any WordPress site. Images are uploaded first and the cover becomes the featured image.</p></div>
            </div>
        </div>
    </section>

    <section class="themes">
        <div class="wrap">
            <h2>Two themes, one system.</h2>
            <p class="lead">Design tokens drive both looks. Beckon follows your operating system by default, and a right-click on the toggle lets you pin light, dark or system. This page works the same way.</p>
            <div class="duo">
                <figure><div class="frame shot"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/img/shot-light.webp" alt="Beckon board in light mode" width="1440" height="900"></div><figcaption>Light</figcaption></figure>
                <figure><div class="frame shot"><img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/img/shot-dark.webp" alt="Beckon board in dark mode" width="1440" height="900"></div><figcaption>Dark</figcaption></figure>
            </div>
        </div>
    </section>

    <section class="install" id="install">
        <div class="wrap">
            <h2>All hands on deck.</h2>
            <p class="lead">Any server with PHP 8 works. Here is the whole install.</p>
            <div class="term">
                <div class="frame-bar"><i></i><i></i><i></i><span>Terminal</span></div>
<pre><span class="p">$</span> mkdir beckon &amp;&amp; cd beckon
<span class="p">$</span> curl -OL https://github.com/austinginder/beckon/releases/latest/download/index.php
<span class="c"># any web server works; the PHP built-in one is fine for a laptop</span>
<span class="p">$</span> php -S localhost:8000</pre>
            </div>
            <div class="install-cols">
                <div class="note"><b>Running Cove?</b><code>cove add beckon --plain</code>, then clone the repo into the site folder and open <code>https://beckon.localhost</code>.</div>
                <div class="note"><b>Keep it private.</b> Beckon has no login of its own. Put it behind HTTP basic auth, a VPN, or a host you control. Search needs the SQLite PDO extension, which most PHP builds include.</div>
            </div>
            <div class="tree" style="max-width:780px;margin:24px auto 0">boards/
  my-project/
    layout.json            <span>board title, lists, card order, archive</span>
    2026-09-22_&lt;id&gt;.md     <span>the card, in Markdown</span>
    2026-09-22_&lt;id&gt;.json   <span>comments, checklists, dates, history</span>
    uploads/               <span>images and attachments</span></div>
        </div>
    </section>


<?php beckon_changelog_popup(); ?>
<?php get_footer(); ?>

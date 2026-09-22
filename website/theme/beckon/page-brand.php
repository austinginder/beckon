<?php
/**
 * Template for the /brand/ page (matched by slug): the mark, the lockup,
 * the palette and type, and the downloadable kit.
 *
 * Asset files live in assets/brand/ and are generated from the mark's
 * source paths (the same drawing the app and the nav use); the wordmark is
 * outlined from Inter ExtraBold so the lockup renders identically everywhere.
 * The palette documented here mirrors the tokens at the top of style.css.
 */
get_header();
$kit  = beckon_brand_kit();
$base = get_template_directory_uri() . '/assets/brand';
?>
<div class="head">
    <span class="eyebrow">Brand</span>
    <h1>The beacon, the wordmark, the amber.</h1>
    <p><?php echo esc_html($kit['lede']); ?></p>
</div>

<main class="bk">
    <div class="wrap">

        <section class="bk-section">
            <h2 class="bk-h">Logo</h2>
            <p class="bk-note">One drawing, three treatments: the lockup where there is room, the bare beacon where there is not, the amber tile wherever a square icon is required.</p>
            <div class="bk-stages">
                <div class="bk-stage bk-stage--light"><img src="<?php echo esc_url("$base/beckon-lockup.svg"); ?>" alt="Beckon lockup on light" class="bk-stage__lockup"></div>
                <div class="bk-stage bk-stage--dark"><img src="<?php echo esc_url("$base/beckon-lockup-dark.svg"); ?>" alt="Beckon lockup on dark" class="bk-stage__lockup"></div>
                <div class="bk-stage bk-stage--light bk-stage--half"><img src="<?php echo esc_url("$base/beckon-mark.svg"); ?>" alt="The bare beacon mark" class="bk-stage__mark"></div>
                <div class="bk-stage bk-stage--light bk-stage--half"><img src="<?php echo esc_url("$base/beckon-icon.svg"); ?>" alt="The app icon tile" class="bk-stage__mark bk-stage__mark--tile"></div>
            </div>
        </section>

        <section class="bk-section">
            <div class="bk-head-row">
                <h2 class="bk-h">Downloads</h2>
                <a class="btn brand" href="<?php echo esc_url("$base/beckon-brand-kit.zip"); ?>">Download everything (.zip)</a>
            </div>
            <div class="bk-dl-grid">
                <?php foreach ($kit['downloads'] as $group): ?>
                    <div class="bk-dl">
                        <div class="bk-dl__preview <?php echo $group['dark'] ? 'bk-dl__preview--dark' : ''; ?>"><img src="<?php echo esc_url($base . '/' . $group['preview']); ?>" alt="" loading="lazy"></div>
                        <h3 class="bk-dl__title"><?php echo esc_html($group['title']); ?></h3>
                        <p class="bk-dl__desc"><?php echo esc_html($group['desc']); ?></p>
                        <div class="bk-dl__files">
                            <?php foreach ($group['files'] as $file): ?>
                                <a class="bk-dl__file" href="<?php echo esc_url($base . '/' . $file[0]); ?>" download>
                                    <span class="bk-dl__name"><?php echo esc_html($file[0]); ?></span>
                                    <span class="bk-dl__kind"><?php echo esc_html($file[1]); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="bk-section">
            <h2 class="bk-h">Colour</h2>
            <p class="bk-note">Amber is the one constant: it does not change between schemes. Everything else is a token defined twice, once for light and once for dark, and nothing in the app or on this site is hardcoded.</p>
            <?php foreach (['light' => 'Light', 'dark' => 'Dark'] as $scheme => $label): ?>
                <h3 class="bk-sub"><?php echo esc_html($label); ?></h3>
                <div class="bk-swatches">
                    <?php foreach ($kit['colors'][$scheme] as $c): ?>
                        <div class="bk-swatch">
                            <span class="bk-swatch__chip" style="background: <?php echo esc_attr($c['hex']); ?>"></span>
                            <span class="bk-swatch__name"><?php echo esc_html($c['name']); ?></span>
                            <span class="bk-swatch__hex"><?php echo esc_html($c['hex']); ?> · <?php echo esc_html($c['var']); ?></span>
                            <span class="bk-swatch__use"><?php echo esc_html($c['use']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="bk-section">
            <h2 class="bk-h">Type</h2>
            <p class="bk-note">Beckon ships as one file and bundles no fonts, so it reads in the system face of whatever opens it. The wordmark is the exception: it is outlined from Inter ExtraBold and never retyped.</p>
            <div class="bk-type-grid">
                <div class="bk-specimen">
                    <div class="bk-specimen__sample">Where Markdown charts the course.</div>
                    <div class="bk-specimen__name">System sans</div>
                    <div class="bk-specimen__meta">SF Pro on Apple, Inter, Segoe UI or Roboto elsewhere. Headings at 800, body at 400, tight tracking on display sizes.</div>
                    <div class="bk-specimen__weights"><span style="font-weight:400">Aa</span><span style="font-weight:500">Aa</span><span style="font-weight:600">Aa</span><span style="font-weight:700">Aa</span><span style="font-weight:800">Aa</span></div>
                </div>
                <div class="bk-specimen bk-specimen--mono">
                    <div class="bk-specimen__sample">2026-09-22_a1b2 · 500 cards · v1.0.0</div>
                    <div class="bk-specimen__name">System mono</div>
                    <div class="bk-specimen__meta">SF Mono, Menlo or Consolas. Card ids, dates, version tags, eyebrows and anything terminal-flavoured. Weights 400 to 700.</div>
                    <div class="bk-specimen__weights"><span style="font-weight:400">0123</span><span style="font-weight:500">0123</span><span style="font-weight:700">0123</span></div>
                </div>
            </div>
        </section>

        <section class="bk-section">
            <h2 class="bk-h">Using the mark</h2>
            <div class="bk-usage-grid">
                <div class="bk-usage">
                    <h3 class="bk-usage__title bk-usage__title--do">Do</h3>
                    <ul><?php foreach ($kit['usage']['do'] as $rule): ?><li><?php echo esc_html($rule); ?></li><?php endforeach; ?></ul>
                </div>
                <div class="bk-usage">
                    <h3 class="bk-usage__title bk-usage__title--dont">Don't</h3>
                    <ul><?php foreach ($kit['usage']['dont'] as $rule): ?><li><?php echo esc_html($rule); ?></li><?php endforeach; ?></ul>
                </div>
            </div>
        </section>

    </div>
</main>
<?php get_footer(); ?>

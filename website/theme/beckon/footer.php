    <footer>
        <div class="wrap">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="logo" aria-label="Beckon home"><svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#f2b134"/><path d="M13 24h6l-1.2-9h-3.6z" fill="#1a1f2b"/><path d="M12.5 13.5h7" stroke="#1a1f2b" stroke-width="2" stroke-linecap="round"/><circle cx="16" cy="10.5" r="2.2" fill="#1a1f2b"/><path d="M9.5 9l2.4 1.2M22.5 9l-2.4 1.2M8.2 13.2l2.6-.4M23.8 13.2l-2.6-.4" stroke="#1a1f2b" stroke-width="1.6" stroke-linecap="round"/><path d="M10 25.5h12" stroke="#1a1f2b" stroke-width="2" stroke-linecap="round"/></svg></a>
            <p>Beckon is a small, self-hosted project board made by <a href="https://austinginder.com">Austin Ginder</a>.</p>
            <div class="row">
                <a href="https://github.com/austinginder/beckon">GitHub</a>
                <a href="<?php echo esc_url(home_url('/changelog/')); ?>">Changelog</a>
                <a href="https://github.com/austinginder/beckon/blob/main/cli.md">CLI docs</a>
                <a href="<?php echo esc_url(home_url('/brand/')); ?>">Brand</a>
                <a href="https://x.com/austinginder">X</a>
            </div>
            <small>&copy; 2026 <a href="https://austinginder.com">Austin Ginder</a>. MIT licensed.<br>Part of the fleet: <a href="https://captaincore.com">CaptainCore</a> · <a href="https://wpfreighter.com">WP Freighter</a> · <a href="https://cove.run">Cove</a></small>
        </div>
    </footer>
    <div class="toast" id="toast"></div>
    <?php wp_footer(); ?>

</body>
</html>

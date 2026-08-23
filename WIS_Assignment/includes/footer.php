</main>

<footer>
    <div class="footer-inner">
        <div>
            <div class="logo">
                <a href="<?= $base_path ?>index.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M6 10h11v5a5 5 0 0 1-5 5H10a5 5 0 0 1-5-5v-5z"/><path d="M17 11.5h1.2a2.3 2.3 0 0 1 0 4.6H17"/></svg>
                    TAR C<em>offee</em>
                </a>
            </div>
            <p>A small roastery and coffee shop. Beans roasted weekly, brewed every day.</p>
            <div class="footer-social">
                <a href="#" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1"/></svg></a>
                <a href="#" aria-label="Facebook"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M15 8h-2a2 2 0 0 0-2 2v2H9v3h2v6h3v-6h2.2l.8-3H14v-1.5c0-.5.3-1 1-1h1V8z"/></svg></a>
            </div>
        </div>
        <div>
            <h4>Shop</h4>
            <ul class="footer-links">
                <li><a href="<?= $base_path ?>index.php">All Menu</a></li>
                <li><a href="<?= $base_path ?>cart.php">Cart</a></li>
                <?php if (is_logged_in()): ?>
                    <li><a href="<?= $base_path ?>orders.php">My Orders</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <div>
            <h4>Visit</h4>
            <p>21 Jalan Kopi, Petaling Jaya</p>
            <p>Mon&ndash;Fri, 8am&ndash;6pm<br>Sat&ndash;Sun, 9am&ndash;4pm</p>
        </div>
        <div>
            <h4>Newsletter</h4>
            <p>One email a week when a new roast drops.</p>
            <form class="footer-signup" id="newsletter-form">
                <input type="email" placeholder="you@email.com" aria-label="Email address">
                <button type="submit">Join</button>
            </form>
        </div>
    </div>
    <div class="footer-base">
        <span>&copy; <?= date('Y') ?> TAR Coffee. All rights reserved.</span>
    </div>
</footer>

<!-- Include jQuery via CDN -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

<!-- Include Custom JS -->
<script src="<?= $base_path ?>assets/js/app.js"></script>

</body>
</html>

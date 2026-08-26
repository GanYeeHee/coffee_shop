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
    </div>
    <div class="footer-base">
        <span>&copy; <?= date('Y') ?> TAR Coffee. All rights reserved.</span>
    </div>
</footer>

<!-- Include jQuery (hosted locally, no external dependency) -->
<script src="<?= $base_path ?>assets/js/jquery-3.7.1.min.js"></script>

<!-- Include Custom JS -->
<script src="<?= $base_path ?>assets/js/app.js"></script>

</body>
</html>

$(document).ready(function() {

    // 0. Homepage nav: starts transparent over the hero photo, turns solid on scroll
    var $header = $('#site-header');
    if ($header.hasClass('nav-transparent')) {
        $(window).on('scroll', function () {
            $header.toggleClass('solid', $(window).scrollTop() > 40);
        }).trigger('scroll');
    }

    // 0c. The sticky header still reserves its own space in normal flow, so a
    // CSS-only negative margin can't make the hero sit truly behind it - only
    // *next to* it. Pull the hero up by exactly the header's height (plus the
    // page's own top padding) so the header genuinely overlays the photo.
    var $hero = $('.hero');
    if ($hero.length) {
        var applyHeroOverlap = function () {
            var headerHeight = $header.outerHeight();
            var mainPaddingTop = parseFloat($('main').css('padding-top')) || 0;
            $hero.css('margin-top', -(headerHeight + mainPaddingTop) + 'px');
        };
        applyHeroOverlap();
        var resizeTimer;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(applyHeroOverlap, 150);
        });
    }

    // 1. AJAX: Cart Quantity Stepper (+/-) with Auto-Save
    $(document).on('click', '.qty-btn', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;

        var $stepper = $btn.closest('.qty-stepper');
        var cartId = $stepper.data('cart-id');
        var change = $btn.hasClass('qty-increase') ? 1 : -1;

        $stepper.find('.qty-btn').prop('disabled', true);

        $.ajax({
            url: 'cart.php',
            type: 'POST',
            data: { action: 'ajax_update_qty', cart_id: cartId, change: change },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    $stepper.find('.qty-value').text(response.quantity);
                    $('#subtotal-' + cartId).text('RM' + response.subtotal.toFixed(2));
                    $('#cart-subtotal-value, #cart-total-value').text('RM' + response.grand_total.toFixed(2));

                    var $badge = $('.cart-count');
                    if ($badge.length) {
                        $badge.text(response.cart_count);
                    }

                    $stepper.find('.qty-decrease').prop('disabled', response.at_min);
                    $stepper.find('.qty-increase').prop('disabled', response.at_max);
                } else {
                    alert(response.message || 'Could not update quantity.');
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else {
                        $stepper.find('.qty-decrease').prop('disabled', false);
                        $stepper.find('.qty-increase').prop('disabled', false);
                    }
                }
            },
            error: function () {
                alert('An error occurred while updating quantity.');
                $stepper.find('.qty-decrease').prop('disabled', false);
                $stepper.find('.qty-increase').prop('disabled', false);
            }
        });
    });

    // 2. Photo Upload Preview
    // Listen for file changes and show immediate client-side preview
    $(document).on('change', '.image-upload-input', function() {
        var input = this;
        var $preview = $('.photo-preview');
        
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            
            reader.onload = function(e) {
                if ($preview.length) {
                    $preview.attr('src', e.target.result);
                }
            };
            
            reader.readAsDataURL(input.files[0]);
        }
    });

    // 3. Form Submission Confirmations
    $(document).on('click', '.confirm-action', function(e) {
        var message = $(this).data('confirm-message') || 'Are you sure you want to perform this action?';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });

    // 4. Product Gallery Thumbnail Switching
    $(document).on('click', '.gallery-thumb', function () {
        $('.detail-img').attr('src', $(this).data('full'));
        $('.gallery-thumb').removeClass('active');
        $(this).addClass('active');
    });

    // 5. Client-side Card Input Masking and Check (UX Enhancement)
    var $cardInput = $('#field-card_number');
    if ($cardInput.length) {
        $cardInput.on('input', function() {
            // Remove non-digit characters
            var val = this.value.replace(/\D/g, '');
            // Limit to 16 digits
            val = val.substring(0, 16);
            // Format with spaces every 4 digits
            var formatted = val.match(/.{1,4}/g);
            this.value = formatted ? formatted.join(' ') : '';
        });
    }

    // 6. Checkout: Fulfillment Type Toggle (Delivery vs Pickup)
    $(document).on('change', 'input[name=fulfillment_type]', function () {
        $('#delivery-section').toggle($(this).val() === 'delivery');
    });

    // 7. Checkout: Payment Method Toggle (Card fields shown only for Card)
    $(document).on('change', 'input[name=payment_method]', function () {
        $('#card-payment-section').toggle($(this).val() === 'card');
    });

    // 8. Checkout: Saved Address vs New Address Toggle
    $(document).on('change', '#field-address_id', function () {
        $('#new-address-section').toggle($(this).val() === 'new');
    });

    // 9. Password fields: show/hide toggle (generated by html_input() for every type="password" field)
    $(document).on('click', '.password-toggle', function () {
        var $btn = $(this).toggleClass('is-visible');
        var isVisible = $btn.hasClass('is-visible');
        $btn.siblings('input').attr('type', isVisible ? 'text' : 'password');
        $btn.attr('aria-label', isVisible ? 'Hide password' : 'Show password');
    });

    // 10. Register/Profile: Toggle a security question's answer field when its checkbox is checked
    $(document).on('change', '.sec-question-checkbox', function () {
        var $answer = $(this).closest('.sec-question-row').find('.sec-question-answer');
        if (this.checked) {
            $answer.show();
        } else {
            $answer.hide().val('');
        }
    });

    // 11. Checkout: Apply Voucher Code (AJAX preview - server always re-validates on submit)
    $(document).on('click', '#apply-voucher-btn', function (e) {
        e.preventDefault();
        var code = $('#field-voucher_code').val();
        var $msg = $('#voucher-message');

        $.ajax({
            url: 'checkout.php',
            type: 'POST',
            data: { action: 'validate_voucher', code: code },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    $('#discount-line').css('display', 'flex');
                    $('#discount-amount').text('-RM' + response.discount_amount.toFixed(2));
                    $('#grand-total-amount').text('RM' + response.new_total.toFixed(2));
                    $msg.text('Voucher applied!').css('color', 'var(--success)');
                } else {
                    $('#discount-line').hide();
                    $msg.text(response.message || 'Invalid voucher.').css('color', 'var(--danger)');
                }
            },
            error: function () {
                $msg.text('Could not validate voucher.').css('color', 'var(--danger)');
            }
        });
    });

    // 12. AJAX shop filtering: category links, the search form, and pagination
    //     refresh only the product list instead of reloading the whole page.
    //     The plain links/form still work when JavaScript is disabled.
    if ($('.shop-layout').length) {

        // Keep the sidebar "active" state and the search box's category scope
        // in sync with whichever URL we just loaded.
        var syncShopControls = function (url) {
            var catId = (url.match(/[?&]cat_id=(\d+)/) || [])[1] || '';

            $('.filter-list a').removeClass('active').each(function () {
                var linkCat = (($(this).attr('href') || '').match(/[?&]cat_id=(\d+)/) || [])[1] || '';
                if (linkCat === catId) {
                    $(this).addClass('active');
                }
            });

            var $catField = $('.search-form input[name=cat_id]');
            if (catId) {
                if ($catField.length) {
                    $catField.val(catId);
                } else {
                    $('<input type="hidden" name="cat_id">').val(catId).prependTo('.search-form');
                }
            } else {
                $catField.remove();
            }
        };

        var loadProducts = function (url, addToHistory) {
            var $section = $('.products-section').addClass('is-loading');

            $.ajax({
                url: url,
                type: 'GET',
                data: { ajax: 'products' },
                success: function (html) {
                    $section.html(html);
                    if (addToHistory) {
                        history.pushState({ shopUrl: url }, '', url);
                    }
                    syncShopControls(url);
                },
                error: function () {
                    window.location.href = url; // fall back to a normal page load
                },
                complete: function () {
                    $section.removeClass('is-loading');
                }
            });
        };

        // Remember the first-load URL so the Back button can restore it.
        history.replaceState({ shopUrl: window.location.href }, '', window.location.href);

        $(document).on('click', '.filter-list a, .products-section .pagination a', function (e) {
            e.preventDefault();
            loadProducts($(this).attr('href'), true);
        });

        $(document).on('submit', '.search-form', function (e) {
            e.preventDefault();
            var $form = $(this);
            var query = $form.serialize();
            loadProducts($form.attr('action') + (query ? '?' + query : ''), true);
        });

        $(window).on('popstate', function (e) {
            var state = e.originalEvent && e.originalEvent.state;
            if (state && state.shopUrl) {
                loadProducts(state.shopUrl, false);
            }
        });
    }

    // 13. Admin tables: per-row "..." (kebab) action menu.
    //     The panel is position:fixed so it is not clipped by the table's
    //     horizontal scroll container; it is re-positioned each time it opens.
    if ($('.row-menu').length) {

        var closeRowMenus = function () {
            $('.row-menu-panel').prop('hidden', true);
            $('.row-menu-toggle').attr('aria-expanded', 'false');
        };

        $(document).on('click', '.row-menu-toggle', function () {
            var $toggle = $(this);
            var $panel = $toggle.next('.row-menu-panel');
            var willOpen = $panel.prop('hidden');

            closeRowMenus();
            if (!willOpen) {
                return;
            }

            $panel.prop('hidden', false);
            $toggle.attr('aria-expanded', 'true');

            var rect = this.getBoundingClientRect();
            var panelW = $panel.outerWidth();
            var panelH = $panel.outerHeight();

            var left = Math.max(8, Math.min(rect.right - panelW, window.innerWidth - panelW - 8));
            var top = rect.bottom + 4;
            if (top + panelH > window.innerHeight - 8) {
                top = Math.max(8, rect.top - panelH - 4); // flip above when no room below
            }

            $panel.css({ left: left + 'px', top: top + 'px' });
        });

        // Close when clicking anywhere outside a row menu.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.row-menu').length) {
                closeRowMenus();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                closeRowMenus();
            }
        });
        $(window).on('resize scroll', closeRowMenus);
        $('.table-responsive').on('scroll', closeRowMenus);
    }

    // 14. Admin drawer: lock the background from scrolling while it's open, and
    //     let Esc close it (same target as clicking the scrim).
    var $drawerScrim = $('.admin-drawer-scrim');
    if ($drawerScrim.length) {
        $('body').css('overflow', 'hidden');
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                window.location.href = $drawerScrim.attr('href');
            }
        });
    }

});

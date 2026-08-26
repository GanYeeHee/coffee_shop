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
            // cat_id is usually numeric but can be the string "best" (Best Sellers).
            var catId = (url.match(/[?&]cat_id=([^&]+)/) || [])[1] || '';

            $('.filter-list a').removeClass('active').each(function () {
                var linkCat = (($(this).attr('href') || '').match(/[?&]cat_id=([^&]+)/) || [])[1] || '';
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

    // 15. Row selection wiring shared by the admin products list and the cart:
    //     select-all checkbox, a live "N selected" count, and showing the bulk bar
    //     only while something is selected.
    var wireBulkSelection = function (opts) {
        if (!$(opts.selectAll).length) {
            return null;
        }

        var refresh = function () {
            var $boxes = $(opts.rowCb);
            var checked = $boxes.filter(':checked').length;
            $(opts.count).text(checked + ' selected');
            $(opts.bar).toggleClass('is-hidden', checked === 0);
            $(opts.selectAll).prop('checked', checked > 0 && checked === $boxes.length);
            $(opts.selectAll).prop('indeterminate', checked > 0 && checked < $boxes.length);
        };

        $(document).on('change', opts.selectAll, function () {
            $(opts.rowCb).prop('checked', this.checked);
            refresh();
        });
        $(document).on('change', opts.rowCb, refresh);

        return {
            refresh: refresh,
            checkedIds: function () {
                return $(opts.rowCb).filter(':checked').map(function () { return this.value; }).get();
            }
        };
    };

    // 15a. Admin > Products: batch price update ("increase prices") + batch delete (AJAX).
    if ($('#bulk-form').length) {
        var productSel = wireBulkSelection({
            selectAll: '#bulk-select-all',
            rowCb: '#bulk-form .bulk-cb',
            bar: '#bulk-bar',
            count: '#bulk-count'
        });

        var showBulkMsg = function (type, text) {
            $('#bulk-msg').html('<div class="alert alert-' + type + '">' + text + '</div>');
        };

        // The bulk bar drives everything through $.ajax; never let an Enter key
        // in the amount field submit the wrapping form as a normal page POST.
        $('#bulk-form').on('submit', function (e) { e.preventDefault(); });

        $('#bulk-price-apply').on('click', function () {
            var ids = productSel.checkedIds();
            if (!ids.length) { showBulkMsg('danger', 'Select at least one product first.'); return; }

            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: 'products.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'batch_price',
                    ids: ids,
                    mode: $('#bulk-mode').val(),
                    kind: $('#bulk-kind').val(),
                    value: $('#bulk-value').val()
                },
                success: function (res) {
                    if (res.success) {
                        $.each(res.prices, function (id, price) {
                            $('tr[data-product-id="' + id + '"] .product-price-cell')
                                .text('RM' + Number(price).toFixed(2));
                        });
                        showBulkMsg('success', res.message);
                    } else {
                        showBulkMsg('danger', res.message || 'Update failed.');
                        if (res.redirect) { window.location.href = res.redirect; }
                    }
                },
                error: function () { showBulkMsg('danger', 'An error occurred while updating prices.'); },
                complete: function () { $btn.prop('disabled', false); }
            });
        });

        $('#bulk-delete').on('click', function () {
            var ids = productSel.checkedIds();
            if (!ids.length) { showBulkMsg('danger', 'Select at least one product first.'); return; }
            if (!confirm('Delete ' + ids.length + ' selected product(s)? This also permanently removes their image files and cannot be undone.')) {
                return;
            }

            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: 'products.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'batch_delete', ids: ids },
                success: function (res) {
                    if (res.success) {
                        $.each(res.deleted, function (i, id) {
                            $('tr[data-product-id="' + id + '"]').remove();
                        });
                        showBulkMsg('success', res.message);
                        if (!$('#bulk-form .bulk-cb').length) {
                            window.location.reload();
                        } else {
                            productSel.refresh();
                        }
                    } else {
                        showBulkMsg('danger', res.message || 'Delete failed.');
                        if (res.redirect) { window.location.href = res.redirect; }
                    }
                },
                error: function () { showBulkMsg('danger', 'An error occurred while deleting.'); },
                complete: function () { $btn.prop('disabled', false); }
            });
        });
    }

    // 15b. Member > Cart: remove several selected items in one request (AJAX).
    if ($('#cart-bulk-bar').length) {
        var cartSel = wireBulkSelection({
            selectAll: '#cart-select-all',
            rowCb: '.cart-cb',
            bar: '#cart-bulk-bar',
            count: '#cart-bulk-count'
        });

        $('#cart-bulk-remove').on('click', function () {
            var ids = cartSel.checkedIds();
            if (!ids.length) { return; }
            if (!confirm('Remove ' + ids.length + ' selected item(s) from your cart?')) { return; }

            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: 'cart.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'ajax_batch_remove', ids: ids },
                success: function (res) {
                    if (res.success) {
                        $.each(res.removed, function (i, id) {
                            $('tr[data-cart-row="' + id + '"]').remove();
                        });
                        if (!$('.cart-cb').length) {
                            window.location.reload();
                            return;
                        }
                        $('#cart-subtotal-value, #cart-total-value').text('RM' + res.grand_total.toFixed(2));
                        var $badge = $('.cart-count');
                        if ($badge.length) { $badge.text(res.cart_count); }
                        cartSel.refresh();
                    } else {
                        alert(res.message || 'Could not remove items.');
                        if (res.redirect) { window.location.href = res.redirect; }
                    }
                },
                error: function () { alert('An error occurred while removing items.'); },
                complete: function () { $btn.prop('disabled', false); }
            });
        });
    }

    // 16. Admin > Orders registry: accept / complete / cancel an order without a
    //     full page reload. Only the affected row - and the receipt panel, if it
    //     is open for that order - is updated. Without JavaScript the same forms
    //     still POST normally and the server redirects back.
    if ($('.order-actions-cell').length || $('.order-detail-actions').length) {

        // Preserve the active status/search/date filters when the fallback
        // form markup is rebuilt from an AJAX response.
        var ordersBaseQs = window.location.search.replace(/^\?/, '');
        var activeStatusFilter = (window.location.search.match(/[?&]status=([^&]+)/) || [])[1] || 'All';
        if (activeStatusFilter !== 'All') {
            activeStatusFilter = decodeURIComponent(activeStatusFilter);
        }

        var showOrdersFlash = function (message, type) {
            var $flash = $('#orders-ajax-flash');
            if (!$flash.length) {
                $flash = $('<div id="orders-ajax-flash"></div>').insertBefore('.list-detail-columns');
            }
            $flash
                .attr('class', 'alert alert-' + (type === 'success' ? 'success' : 'danger'))
                .text(message)
                .show();
            clearTimeout($flash.data('hideTimer'));
            $flash.data('hideTimer', setTimeout(function () { $flash.fadeOut(); }, 4000));
        };

        $(document).on('submit', '.order-action-form', function (e) {
            e.preventDefault();
            var $form = $(this);
            var orderId = $form.find('input[name=order_id]').val();
            var $buttons = $form.closest('.order-actions-cell, .order-detail-actions').find('button');

            // The Cancel button's confirm() already ran on click (.confirm-action);
            // if it was declined this submit handler never fires.
            $buttons.prop('disabled', true);

            $.ajax({
                url: 'orders.php',
                type: 'POST',
                dataType: 'json',
                data: $form.serialize() + '&ajax=order_action&base_qs=' + encodeURIComponent(ordersBaseQs),
                success: function (res) {
                    if (!res || !res.success) {
                        alert((res && res.message) || 'Could not update the order.');
                        $buttons.prop('disabled', false);
                        return;
                    }

                    // Update the registry row.
                    var $cell = $('.order-actions-cell[data-order-id="' + orderId + '"]');
                    var $row = $cell.closest('tr');
                    $row.find('.order-status-badge').replaceWith(res.badge_html);
                    $cell.find('.order-action-buttons').html(res.row_actions_html);

                    // Update the receipt panel too, if it is open for this order.
                    var $detail = $('.order-detail-actions[data-order-id="' + orderId + '"]');
                    if ($detail.length) {
                        $detail.closest('.admin-panel').find('.order-status-badge').replaceWith(res.badge_html);
                        $detail.html(res.detail_actions_html);
                    }

                    showOrdersFlash(res.message, 'success');

                    // If a status tab is active and the order no longer belongs
                    // in it, drop the row from view (matches the old full reload).
                    if (activeStatusFilter !== 'All' && res.status !== activeStatusFilter) {
                        $row.fadeOut(400, function () { $(this).remove(); });
                    }
                },
                error: function () {
                    alert('An error occurred while updating the order. Please try again.');
                    $buttons.prop('disabled', false);
                }
            });
        });
    }

});

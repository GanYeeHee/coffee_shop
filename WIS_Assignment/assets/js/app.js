$(document).ready(function() {

    function escapeHtml(text) {
        return $('<div>').text(text == null ? '' : text).html();
    }

    // 1. AJAX: Add to Cart from Product Cards (Homepage)
    $(document).on('click', '.ajax-add-to-cart', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var productId = $btn.data('product-id');
        
        // Disable button temporarily to prevent double clicks
        $btn.prop('disabled', true).text('Adding...');

        $.ajax({
            url: 'cart.php',
            type: 'POST',
            data: {
                action: 'ajax_add',
                product_id: productId,
                quantity: 1
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update cart count badge in header
                    var $badge = $('.cart-count');
                    if ($badge.length) {
                        $badge.text(response.cart_count).show();
                    } else {
                        // If no badge existed (count was 0), insert one
                        $('.cart-link').append('<span class="cart-count">' + response.cart_count + '</span>');
                    }
                    
                    // Flash feedback message
                    $btn.text('Added! ✓').addClass('btn-success').removeClass('btn-accent');
                    setTimeout(function() {
                        $btn.prop('disabled', false).text('Add to Cart').removeClass('btn-success').addClass('btn-accent');
                    }, 1500);
                } else {
                    // Show error message
                    alert(response.message || 'Failed to add item to cart. Please log in first.');
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else {
                        $btn.prop('disabled', false).text('Add to Cart');
                    }
                }
            },
            error: function() {
                alert('An error occurred. Please check if you are logged in.');
                $btn.prop('disabled', false).text('Add to Cart');
            }
        });
    });

    // 2. AJAX: Cart Quantity Stepper (+/-) with Auto-Save
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

    // 3. Photo Upload Preview
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

    // 4. Form Submission Confirmations
    $(document).on('click', '.confirm-action', function(e) {
        var message = $(this).data('confirm-message') || 'Are you sure you want to perform this action?';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });

    // 5. Product Gallery Thumbnail Switching
    $(document).on('click', '.gallery-thumb', function () {
        $('.detail-img').attr('src', $(this).data('full'));
        $('.gallery-thumb').removeClass('active');
        $(this).addClass('active');
    });

    // 6. Client-side Card Input Masking and Check (UX Enhancement)
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

    // 7. Checkout: Fulfillment Type Toggle (Delivery vs Pickup)
    $(document).on('change', 'input[name=fulfillment_type]', function () {
        $('#delivery-section').toggle($(this).val() === 'delivery');
    });

    // 8. Checkout: Payment Method Toggle (Card fields shown only for Card)
    $(document).on('change', 'input[name=payment_method]', function () {
        $('#card-payment-section').toggle($(this).val() === 'card');
    });

    // 9. Checkout: Saved Address vs New Address Toggle
    $(document).on('change', '#field-address_id', function () {
        $('#new-address-section').toggle($(this).val() === 'new');
    });

    // 10. Checkout: Apply Voucher Code (AJAX preview - server always re-validates on submit)
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
                    $('#discount-amount').text('-$' + response.discount_amount.toFixed(2));
                    $('#grand-total-amount').text('$' + response.new_total.toFixed(2));
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

    // 11. Cart: Edit Item (Options + Customization Note) via Modal
    $(document).on('click', '.edit-item-btn', function () {
        var cartId = $(this).data('cart-id');
        var $overlay = $('#edit-item-overlay').data('cart-id', cartId);
        var $body = $('#edit-item-body');

        $body.html('<p style="color: var(--text-muted);">Loading...</p>');
        $overlay.show();

        $.ajax({
            url: 'cart.php',
            type: 'POST',
            data: { action: 'ajax_get_edit_options', cart_id: cartId },
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $body.html('<p style="color: var(--danger);">' + escapeHtml(response.message || 'Could not load item.') + '</p>');
                    return;
                }

                var html = '';
                response.groups.forEach(function (group) {
                    html += '<div class="form-group"><label>' + escapeHtml(group.name) + (group.is_required == 1 ? ' *' : '') + '</label>';
                    html += '<select class="form-control" name="options[' + group.id + ']"' + (group.is_required == 1 ? ' required' : '') + '>';
                    html += '<option value="">' + (group.is_required == 1 ? '-- Select --' : 'None') + '</option>';
                    group.values.forEach(function (value) {
                        var label = escapeHtml(value.label);
                        var priceDelta = parseFloat(value.price_delta);
                        if (priceDelta > 0) {
                            label += ' (+RM' + priceDelta.toFixed(2) + ')';
                        }
                        html += '<option value="' + value.id + '"' + (value.selected ? ' selected' : '') + '>' + label + '</option>';
                    });
                    html += '</select></div>';
                });

                html += '<div class="form-group"><label for="edit-item-customization">Customization (Optional)</label>';
                html += '<input type="text" class="form-control" id="edit-item-customization" value="' + escapeHtml(response.customization) + '" placeholder="e.g. Oat milk, Extra shot, Less ice"></div>';

                $body.html(html);
            },
            error: function () {
                $body.html('<p style="color: var(--danger);">An error occurred while loading this item.</p>');
            }
        });
    });

    $(document).on('click', '#edit-item-close, #edit-item-cancel', function () {
        $('#edit-item-overlay').hide();
    });

    $(document).on('click', '#edit-item-overlay', function (e) {
        if (e.target.id === 'edit-item-overlay') {
            $('#edit-item-overlay').hide();
        }
    });

    $(document).on('click', '#edit-item-save', function () {
        var $btn = $(this);
        var cartId = $('#edit-item-overlay').data('cart-id');
        var requiredMissing = false;

        var data = {
            action: 'ajax_save_edit',
            cart_id: cartId,
            customization: $('#edit-item-customization').val()
        };

        $('#edit-item-body select').each(function () {
            var match = $(this).attr('name').match(/options\[(\d+)\]/);
            if (!match) return;
            if ($(this).prop('required') && !$(this).val()) {
                requiredMissing = true;
            }
            data['options[' + match[1] + ']'] = $(this).val();
        });

        if (requiredMissing) {
            alert('Please select an option for all required fields.');
            return;
        }

        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: 'cart.php',
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.message || 'Could not save changes.');
                    $btn.prop('disabled', false).text('Save Changes');
                }
            },
            error: function () {
                alert('An error occurred while saving changes.');
                $btn.prop('disabled', false).text('Save Changes');
            }
        });
    });

});

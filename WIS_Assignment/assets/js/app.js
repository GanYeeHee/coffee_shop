$(document).ready(function() {

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

    // 4. Client-side Card Input Masking and Check (UX Enhancement)
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

});

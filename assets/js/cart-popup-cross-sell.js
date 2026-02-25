/* global woodmart_settings, jQuery */
(function ($) {
    'use strict';

    /**
     * JustB2B Cart Popup Cross-Sell
     *
     * Injects cross-sell products into Woodmart's "added to cart" popup
     * by making an AJAX call to get the cross-sell HTML.
     */
    $(document.body).on('added_to_cart', function (e, data) {
        // --- Popup mode ---
        var injectIntoPopup = function () {
            var $popup = $('.wd-popup-added-cart .added-to-cart');
            if ($popup.length) {
                // Remove any previous cross-sell block
                $popup.find('.justb2b-popup-cross-sell').remove();

                // Make AJAX call to get cross-sell HTML
                $.ajax({
                    url: woodmart_settings.ajaxurl || wc_add_to_cart_params.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'justb2b_get_cross_sell_html',
                        nonce: justb2b_cross_sell ? justb2b_cross_sell.nonce : ''
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            // Insert after the title (h3)
                            $popup.find('h3').after(response.data);
                        }
                    }
                });
            }
        };

        if (woodmart_settings.add_to_cart_action === 'popup') {
            // MagnificPopup may not have rendered yet; wait a tick
            setTimeout(injectIntoPopup, 80);
        }

        // --- Widget / sidebar mode ---
        if (woodmart_settings.add_to_cart_action === 'widget') {
            var $cart = $('.widget_shopping_cart_content');
            if ($cart.length) {
                $cart.find('.justb2b-popup-cross-sell').remove();

                // Make AJAX call to get cross-sell HTML
                $.ajax({
                    url: woodmart_settings.ajaxurl || wc_add_to_cart_params.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'justb2b_get_cross_sell_html',
                        nonce: justb2b_cross_sell ? justb2b_cross_sell.nonce : ''
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            $cart.prepend(response.data);
                        }
                    }
                });
            }
        }
    });
})(jQuery);

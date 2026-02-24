/* global woodmart_settings, jQuery */
(function ($) {
    'use strict';

    /**
     * JustB2B Cart Popup Cross-Sell
     *
     * Injects cross-sell products into Woodmart's "added to cart" popup
     * using the fragment returned by PHP via woocommerce_add_to_cart_fragments.
     */
    $(document.body).on('added_to_cart', function (e, fragments) {
        if (!fragments || !fragments['.justb2b-popup-cross-sell']) {
            return;
        }

        var crossSellHtml = fragments['.justb2b-popup-cross-sell'];

        // --- Popup mode ---
        var injectIntoPopup = function () {
            var $popup = $('.wd-popup-added-cart .added-to-cart');
            if ($popup.length) {
                // Remove any previous cross-sell block
                $popup.find('.justb2b-popup-cross-sell').remove();
                // Insert after the title (h3)
                $popup.find('h3').after(crossSellHtml);
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
                $cart.prepend(crossSellHtml);
            }
        }
    });
})(jQuery);

/* global woodmart_settings, jQuery */
(function ($) {
    'use strict';

    var SKELETON_HTML = [
        '<div class="justb2b-popup-cross-sell justb2b-cross-sell-skeleton">',
        '<h4 class="justb2b-cross-sell-title justb2b-skeleton-block justb2b-skeleton-title"></h4>',
        '<div class="justb2b-cross-sell-products">',
        '<div class="justb2b-cross-sell-item">',
        '<div class="justb2b-cross-sell-image justb2b-skeleton-block"></div>',
        '<div class="justb2b-skeleton-block justb2b-skeleton-line justb2b-skeleton-name"></div>',
        '<div class="justb2b-skeleton-block justb2b-skeleton-line justb2b-skeleton-price"></div>',
        '</div>',
        '<div class="justb2b-cross-sell-item">',
        '<div class="justb2b-cross-sell-image justb2b-skeleton-block"></div>',
        '<div class="justb2b-skeleton-block justb2b-skeleton-line justb2b-skeleton-name"></div>',
        '<div class="justb2b-skeleton-block justb2b-skeleton-line justb2b-skeleton-price"></div>',
        '</div>',
        '<div class="justb2b-cross-sell-item">',
        '<div class="justb2b-cross-sell-image justb2b-skeleton-block"></div>',
        '<div class="justb2b-skeleton-block justb2b-skeleton-line justb2b-skeleton-name"></div>',
        '<div class="justb2b-skeleton-block justb2b-skeleton-line justb2b-skeleton-price"></div>',
        '</div>',
        '</div>',
        '</div>'
    ].join('');

    function doAjax(onSuccess) {
        return $.ajax({
            url: woodmart_settings.ajaxurl || wc_add_to_cart_params.ajax_url,
            type: 'POST',
            data: {
                action: 'justb2b_get_cross_sell_html',
                nonce: justb2b_cross_sell ? justb2b_cross_sell.nonce : ''
            },
            success: function (response) {
                if (response.success && response.data) {
                    onSuccess(response.data);
                } else {
                    // No products — remove skeleton silently
                    onSuccess(null);
                }
            },
            error: function () {
                onSuccess(null);
            }
        });
    }

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

                // Show skeleton immediately to reserve height
                $popup.find('h3').after(SKELETON_HTML);

                doAjax(function (html) {
                    var $skeleton = $popup.find('.justb2b-cross-sell-skeleton');
                    if (html) {
                        $skeleton.replaceWith(html);
                    } else {
                        $skeleton.remove();
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

                // Show skeleton immediately to reserve height
                $cart.prepend(SKELETON_HTML);

                doAjax(function (html) {
                    var $skeleton = $cart.find('.justb2b-cross-sell-skeleton');
                    if (html) {
                        $skeleton.replaceWith(html);
                    } else {
                        $skeleton.remove();
                    }
                });
            }
        }
    });
})(jQuery);

<?php

namespace JustB2B;

/**
 * Checkout NIP / Invoice Fields
 *
 * Adds "Chcę otrzymać fakturę VAT" checkbox and "Numer NIP" text field
 * to the WooCommerce checkout for B2B-pending users only.
 *
 * Meta keys stored on the order:
 *   _billing_faktura  — '1' (checked) / '0' (unchecked)
 *   _billing_nip      — VAT / NIP number string
 */
class Checkout_NIP_Fields
{
    private static $instance = null;

    /** Checkout field IDs */
    private const FIELD_FAKTURA = 'billing_faktura';
    private const FIELD_NIP     = 'billing_nip';

    /** Order meta keys */
    private const META_FAKTURA = '_billing_faktura';
    private const META_NIP     = '_billing_nip';

    /* ------------------------------------------------------------------
     * Singleton
     * ----------------------------------------------------------------*/

    public static function instance(): self
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        /* Front-end: add fields to checkout */
        add_filter('woocommerce_billing_fields', [ $this, 'append_fields_to_billing' ], 100);

        /* Front-end: toggle NIP visibility based on checkbox */
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_toggle_script' ]);

        /* Server-side validation: NIP required when faktura is checked */
        add_action('woocommerce_after_checkout_validation', [ $this, 'validate_nip_field' ], 10, 2);

        /* Save meta on order creation */
        add_action('woocommerce_checkout_create_order', [ $this, 'add_meta_data_to_order' ], 10, 2);

        /* Admin: show fields in order billing section */
        add_filter('woocommerce_admin_billing_fields', [ $this, 'add_admin_billing_fields' ]);

        /* Address formatting */
        add_filter('woocommerce_order_formatted_billing_address', [ $this, 'add_to_formatted_billing_address' ], 10, 2);
        add_filter('woocommerce_localisation_address_formats', [ $this, 'add_to_address_formats' ]);
        add_filter('woocommerce_formatted_address_replacements', [ $this, 'add_address_replacements' ], 10, 2);
    }

    /* ------------------------------------------------------------------
     * 0. Inline JS — toggle NIP field visibility
     * ----------------------------------------------------------------*/

    /**
     * Enqueue a small inline script on checkout that hides the NIP field
     * until the "Chcę otrzymać fakturę VAT" checkbox is checked.
     */
    public function enqueue_toggle_script(): void
    {
        if (! is_checkout() || Helper::is_b2b_accepted_user()) {
            return;
        }

        $js = <<<'JS'
(function($){
    function toggleNip(){
        var checked = $('#billing_faktura').is(':checked');
        $('#billing_nip_field').toggle(checked);
        $('#billing_nip').prop('required', checked);
    }
    $(document.body).on('change','#billing_faktura',toggleNip);
    $(document).on('updated_checkout',toggleNip);
    $(toggleNip);
})(jQuery);
JS;

        wp_register_script('justb2b-nip-toggle', '', [], JUSTB2B_VERSION, true);
        wp_enqueue_script('justb2b-nip-toggle');
        wp_add_inline_script('justb2b-nip-toggle', $js);

        /* Hide NIP field by default until the checkbox activates it */
        wp_add_inline_style('woocommerce-general', '.justb2b-nip-hidden { display: none; }');
    }

    /* ------------------------------------------------------------------
     * 1. Checkout billing fields
     * ----------------------------------------------------------------*/

    /**
     * Append the "faktura" checkbox and "NIP" text field to billing fields.
     *
     * Fields are only rendered for b2b_pending users (b2b_accepted users
     * have the entire billing form replaced by Billing_Handler).
     *
     * @param array $fields Billing fields.
     * @return array
     */
    public function append_fields_to_billing(array $fields): array
    {

        if (is_admin()) {
            return $fields;
        }

        /* Only for non-accepted users (b2b_pending, b2c, guests) */
        if (Helper::is_b2b_accepted_user()) {
            return $fields;
        }

        /* Determine priority — right after billing_company */
        $company_priority = isset($fields['billing_company']['priority'])
            ? (int) $fields['billing_company']['priority']
            : 30;

        $fields[ self::FIELD_FAKTURA ] = [
            'label'    => __('Chcę otrzymać fakturę VAT', 'justb2b-larose'),
            'required' => false,
            'class'    => [ 'form-row-wide' ],
            'type'     => 'checkbox',
            'clear'    => true,
            'priority' => $company_priority + 1,
        ];

        $fields[ self::FIELD_NIP ] = [
            'label'       => __('NIP', 'justb2b-larose'),
            'placeholder' => __('Numer NIP', 'justb2b-larose'),
            'required'    => false,
            'class'       => [ 'form-row-wide', 'justb2b-nip-hidden' ],
            'clear'       => true,
            'priority'    => $company_priority + 2,
        ];

        return $fields;
    }

    /* ------------------------------------------------------------------
     * 1b. Server-side validation
     * ----------------------------------------------------------------*/

    /**
     * Validate that NIP is provided when the faktura checkbox is checked.
     *
     * @param array     $data   Posted checkout data.
     * @param \WP_Error $errors Validation errors.
     */
    public function validate_nip_field(array $data, \WP_Error $errors): void
    {
        if (Helper::is_b2b_accepted_user()) {
            return;
        }

        $faktura = ! empty($data[ self::FIELD_FAKTURA ]);
        $nip     = isset($data[ self::FIELD_NIP ]) ? trim($data[ self::FIELD_NIP ]) : '';

        if ($faktura && empty($nip)) {
            $errors->add(
                'billing_nip_required',
                __('Proszę podać numer NIP, jeśli chcesz otrzymać fakturę VAT.', 'justb2b-larose')
            );
        }
    }

    /* ------------------------------------------------------------------
     * 2. Save order meta
     * ----------------------------------------------------------------*/

    /**
     * Persist field values as order meta.
     *
     * @param \WC_Order $order Order object.
     * @param array     $data  Posted checkout data.
     */
    public function add_meta_data_to_order(\WC_Order $order, array $data): void
    {

        /* faktura checkbox — WooCommerce sends '1' when checked, absent otherwise */
        $faktura = isset($data[ self::FIELD_FAKTURA ]) ? '1' : '0';
        $order->update_meta_data(self::META_FAKTURA, $faktura);

        /* NIP text field — only save when faktura is requested */
        if ($faktura === '1' && ! empty($data[ self::FIELD_NIP ])) {
            $order->update_meta_data(self::META_NIP, sanitize_text_field($data[ self::FIELD_NIP ]));
        } else {
            $order->update_meta_data(self::META_NIP, '');
        }
    }

    /* ------------------------------------------------------------------
     * 3. Admin order billing section
     * ----------------------------------------------------------------*/

    /**
     * Add NIP and faktura fields to the admin billing section.
     *
     * @param array $fields Admin billing fields.
     * @return array
     */
    public function add_admin_billing_fields(array $fields): array
    {

        $fields['faktura'] = [
            'label'   => __('Faktura VAT', 'justb2b-larose'),
            'show'    => false,
            'type'    => 'select',
            'options' => [
                '0' => __('No', 'justb2b-larose'),
                '1' => __('Yes', 'justb2b-larose'),
            ],
        ];

        $fields['nip'] = [
            'label' => __('NIP', 'justb2b-larose'),
            'show'  => true,
        ];

        return $fields;
    }

    /* ------------------------------------------------------------------
     * 4. Address formatting
     * ----------------------------------------------------------------*/

    /**
     * Inject NIP & faktura values into the formatted billing address array.
     *
     * @param array     $address Address components.
     * @param \WC_Order $order   Order object.
     * @return array
     */
    public function add_to_formatted_billing_address(array $address, \WC_Order $order): array
    {
        $address['nip']     = $order->get_meta(self::META_NIP) ?: '';
        $address['faktura'] = $order->get_meta(self::META_FAKTURA) ?: '';
        return $address;
    }

    /**
     * Add {nip} and {faktura} placeholders to the PL address format.
     *
     * @param array $formats Localisation address formats keyed by country.
     * @return array
     */
    public function add_to_address_formats(array $formats): array
    {

        /* Default format as fallback */
        $default = $formats['default'] ?? "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{state}\n{postcode}\n{country}";

        $pl_format = $formats['PL'] ?? $default;

        /* Append placeholders if not already present */
        if (strpos($pl_format, '{nip}') === false) {
            $pl_format .= "\n{nip}";
        }
        if (strpos($pl_format, '{faktura}') === false) {
            $pl_format .= "\n{faktura}";
        }

        $formats['PL'] = $pl_format;

        return $formats;
    }

    /**
     * Replace {nip} / {faktura} placeholders with real values.
     *
     * @param array $replacements Placeholder => value map.
     * @param array $args         Address components.
     * @return array
     */
    public function add_address_replacements(array $replacements, array $args): array
    {

        $nip     = $args['nip'] ?? '';
        $faktura = $args['faktura'] ?? '';

        $replacements['{nip}']     = $nip ? sprintf(__('NIP: %s', 'justb2b-larose'), $nip) : '';
        $replacements['{faktura}'] = $faktura === '1' ? __('Faktura VAT: Tak', 'justb2b-larose') : '';

        return $replacements;
    }
}

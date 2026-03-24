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
class Checkout_NIP_Fields {
	private static $instance = null;

	/** Checkout field IDs */
	private const FIELD_FAKTURA = 'billing_faktura';
	private const FIELD_NIP = 'billing_nip';

	/** Order meta key for snapshotted customer status */
	private const META_KLIENT = '_justb2b_client_status';


	/* ------------------------------------------------------------------
	 * Singleton
	 * ----------------------------------------------------------------*/

	public static function instance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		/* Front-end: add fields to checkout */
		add_filter( 'woocommerce_billing_fields', [ $this, 'append_fields_to_billing' ], 100 );

		/* Front-end: toggle NIP visibility based on checkbox */
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_toggle_script' ] );

		/* Server-side validation: NIP required when faktura is checked */
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_nip_field' ], 10, 2 );

		/* Save meta on order creation */
		add_action( 'woocommerce_checkout_create_order', [ $this, 'add_meta_data_to_order' ], 10, 2 );

		/* Admin: show fields in order billing section */
		add_filter( 'woocommerce_admin_billing_fields', [ $this, 'add_admin_billing_fields' ] );

		/* Admin: display NIP / Faktura / Klient meta after billing address */
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_order_meta_fields' ] );

		/* Admin: B2B column in orders list — HPOS */
		add_filter( 'woocommerce_shop_order_list_table_columns', [ $this, 'add_b2b_column' ] );
		add_action( 'woocommerce_shop_order_list_table_custom_column', [ $this, 'render_b2b_column' ], 10, 2 );

		/* Admin: B2B column in orders list — legacy post table */
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_b2b_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_b2b_column_legacy' ], 10, 2 );
	}

	/* ------------------------------------------------------------------
	 * 0. Inline JS — toggle NIP field visibility
	 * ----------------------------------------------------------------*/

	/**
	 * Enqueue a small inline script on checkout that hides the NIP field
	 * until the "Chcę otrzymać fakturę VAT" checkbox is checked.
	 */
	public function enqueue_toggle_script(): void {
		if ( ! is_checkout() || Helper::is_b2b_accepted_user() ) {
			return;
		}

		$js = <<<'JS'
(function($){
    function toggleNip(){
        var checked = $('#billing_faktura').is(':checked');
        var $field  = $('#billing_nip_field');
        var $label  = $field.find('label');
        var $input  = $('#billing_nip');

        $field.toggle(checked);

        if (checked) {
            $field.addClass('validate-required');
            $input.prop('required', true);
            $label.find('.optional').hide();
            if (!$label.find('abbr.required').length) {
                $label.append('<abbr class="required" title="required">&nbsp;*</abbr>');
            } else {
                $label.find('abbr.required').show();
            }
        } else {
            $field.removeClass('validate-required woocommerce-invalid woocommerce-invalid-required-field');
            $input.prop('required', false).val('');
            $label.find('abbr.required').hide();
            $label.find('.optional').show();
        }
    }
    $(document.body).on('change','#billing_faktura',toggleNip);
    $(document).on('updated_checkout',toggleNip);
    $(toggleNip);
})(jQuery);
JS;

		wp_register_script( 'justb2b-nip-toggle', '', [], JUSTB2B_VERSION, true );
		wp_enqueue_script( 'justb2b-nip-toggle' );
		wp_add_inline_script( 'justb2b-nip-toggle', $js );

		/* Hide NIP field by default until the checkbox activates it */
		wp_add_inline_style( 'woocommerce-general', '.justb2b-nip-hidden { display: none; }' );
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
	public function append_fields_to_billing( array $fields ): array {

		if ( is_admin() ) {
			return $fields;
		}

		/* Only for non-accepted users (b2b_pending, b2c, guests) */
		if ( Helper::is_b2b_accepted_user() ) {
			return $fields;
		}

		/* Determine priority — right after billing_company */
		$company_priority = isset( $fields['billing_company']['priority'] )
			? (int) $fields['billing_company']['priority']
			: 30;

		$fields[ self::FIELD_FAKTURA ] = [
			'label' => __( 'Chcę otrzymać fakturę VAT', 'justb2b-larose' ),
			'required' => false,
			'class' => [ 'form-row-wide' ],
			'type' => 'checkbox',
			'clear' => true,
			'priority' => $company_priority + 1,
		];

		$fields[ self::FIELD_NIP ] = [
			'label' => __( 'NIP', 'justb2b-larose' ),
			'placeholder' => __( 'Numer NIP', 'justb2b-larose' ),
			'required' => false,
			'class' => [ 'form-row-wide', 'justb2b-nip-hidden' ],
			'clear' => true,
			'priority' => $company_priority + 2,
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
	public function validate_nip_field( array $data, \WP_Error $errors ): void {
		if ( Helper::is_b2b_accepted_user() ) {
			return;
		}

		$faktura = ! empty( $data[ self::FIELD_FAKTURA ] );
		$nip = isset( $data[ self::FIELD_NIP ] ) ? trim( $data[ self::FIELD_NIP ] ) : '';

		if ( $faktura && empty( $nip ) ) {
			$errors->add(
				'billing_nip_required',
				__( 'Proszę podać numer NIP, jeśli chcesz otrzymać fakturę VAT.', 'justb2b-larose' )
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
	public function add_meta_data_to_order( \WC_Order $order, array $data ): void {

		/* faktura checkbox — WooCommerce sends '1' when checked, empty otherwise */
		$faktura = ! empty( $data[ self::FIELD_FAKTURA ] ) ? '1' : '0';
		$order->update_meta_data( self::FIELD_FAKTURA, $faktura );

		/* NIP text field — only save when faktura is requested */
		if ( $faktura === '1' && ! empty( $data[ self::FIELD_NIP ] ) ) {
			$order->update_meta_data( self::FIELD_NIP, sanitize_text_field( $data[ self::FIELD_NIP ] ) );
		} else {
			$order->update_meta_data( self::FIELD_NIP, '' );
		}

		/* Snapshot the customer's B2B status at order time */
		$user_id = $order->get_customer_id();
		$status = $user_id ? Helper::get_user_status( $user_id ) : 'guest';
		$order->update_meta_data( self::META_KLIENT, $status );
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
	public function add_admin_billing_fields( array $fields ): array {

		/* show: false — keeps the field editable in the admin billing form
		   but suppresses WooCommerce's own read-only rendering so our
		   display_order_meta_fields block is the single place it appears. */
		$fields['faktura'] = [
			'label'   => __( 'Faktura VAT', 'justb2b-larose' ),
			'show'    => false,
			'type'    => 'select',
			'options' => [
				'0' => __( 'No', 'justb2b-larose' ),
				'1' => __( 'Yes', 'justb2b-larose' ),
			],
		];

		$fields['nip'] = [
			'label' => __( 'NIP', 'justb2b-larose' ),
			'show'  => false, /* displayed by display_order_meta_fields, not here */
		];

		return $fields;
	}

	/* ------------------------------------------------------------------
	 * 4. Admin orders list — B2B column
	 * ----------------------------------------------------------------*/

	/**
	 * Register the "B2B" column in the admin orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_b2b_column( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'order_total' ) {
				$new['b2b_meta'] = __( 'B2B', 'justb2b-larose' );
			}
		}
		return $new;
	}

	/**
	 * Render the B2B column for HPOS orders list.
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  Order object.
	 */
	public function render_b2b_column( string $column, \WC_Order $order ): void {
		if ( $column !== 'b2b_meta' ) {
			return;
		}
		$this->output_b2b_column_html( $order );
	}

	/**
	 * Render the B2B column for the legacy post-based orders list.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post / order ID.
	 */
	public function render_b2b_column_legacy( string $column, int $post_id ): void {
		if ( $column !== 'b2b_meta' ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			$this->output_b2b_column_html( $order );
		}
	}

	/**
	 * Shared HTML output for the B2B column.
	 *
	 * @param \WC_Order $order Order object.
	 */
	private function output_b2b_column_html( \WC_Order $order ): void {
		[ 'nip' => $nip, 'faktura_label' => $faktura_label, 'klient_label' => $klient_label ] = $this->get_order_display_data( $order );
		?>
		<small style="display:block;line-height:1.6;">
			<strong>NIP:</strong> <?php echo esc_html( $nip ?: '—' ); ?><br>
			<strong>Faktura:</strong> <?php echo esc_html( $faktura_label ); ?><br>
			<strong>Klient:</strong> <?php echo esc_html( $klient_label ); ?>
		</small>
		<?php
	}

	/* ------------------------------------------------------------------
	 * 5. Admin order meta display
	 * ----------------------------------------------------------------*/

	/**
	 * Render NIP, Faktura and Klient fields after the billing address
	 * in the admin order edit screen.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function display_order_meta_fields( \WC_Order $order ): void {
		[ 'nip' => $nip, 'faktura_label' => $faktura_label, 'klient_label' => $klient_label ] = $this->get_order_display_data( $order );
		?>
		<div class="justb2b-order-meta" style="margin-top:10px;">
			<p><strong><?php esc_html_e( 'NIP:', 'justb2b-larose' ); ?></strong> <?php echo esc_html( $nip ?: '—' ); ?></p>
			<p><strong><?php esc_html_e( 'Faktura:', 'justb2b-larose' ); ?></strong> <?php echo esc_html( $faktura_label ); ?></p>
			<p><strong><?php esc_html_e( 'Klient:', 'justb2b-larose' ); ?></strong> <?php echo esc_html( $klient_label ); ?></p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Read and resolve display-ready B2B meta for an order.
	 * Single source of truth used by both the order detail panel and the orders list column.
	 *
	 * @param \WC_Order $order Order object.
	 * @return array{ nip: string, faktura_label: string, klient_label: string }
	 */
	private function get_order_display_data( \WC_Order $order ): array {
		$faktura = $order->get_meta( self::FIELD_FAKTURA );
		$status  = $order->get_meta( self::META_KLIENT ) ?: 'guest';

		$status_labels = [
			'b2b_accepted' => __( 'B2B', 'justb2b-larose' ),
			'b2b_pending'  => __( 'Waiting for B2B', 'justb2b-larose' ),
			'b2c'          => __( 'B2C', 'justb2b-larose' ),
			'guest'        => __( 'Guest', 'justb2b-larose' ),
		];

		return [
			'nip'          => (string) $order->get_meta( self::FIELD_NIP ),
			'faktura_label' => $faktura === '1' ? __( 'Tak', 'justb2b-larose' ) : __( 'Nie', 'justb2b-larose' ),
			'klient_label'  => $status_labels[ $status ] ?? $status,
		];
	}
}

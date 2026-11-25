<?php
/**
 * Ukrainian phone authority (TASK-045a, F9): the SERVER decides what a valid billing / shipping phone is; the JS blur normalisation of the
 * theme is only a courtesy.
 *
 * `normalise()` accepts 10 digits starting `0` or 12 digits starting `380` (spaces, dashes, dots, parentheses and one leading `+` stripped) and returns
 * `+380XXXXXXXXX`; anything else (letters, other countries, wrong length) is null.
 *
 * Wiring (every path stores the normalised value, an invalid one is a FIELD error and no order is created):
 * - Store API (block checkout): `woocommerce_store_api_checkout_update_order_from_request` normalises the order's billing / shipping phone (runs before the
 *   order is validated); the documented `woocommerce_blocks_validate_location_address_fields` rejects an unusable phone with the address-field error
 *   code `vx_invalid_phone` (Woo prefixes it with its own "problem with the provided billing address" text).
 * - Classic checkout: `woocommerce_process_checkout_field_{billing,shipping}_phone` normalises, `woocommerce_after_checkout_validation` adds the field error.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Checkout;

final class Phone {

	public const ERROR_CODE = 'vx_invalid_phone';

	public function register(): void {
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'normalise_order' ], 5, 2 );
		add_action( 'woocommerce_blocks_validate_location_address_fields', [ $this, 'validate_address' ], 10, 3 );
		add_filter( 'woocommerce_process_checkout_field_billing_phone', [ $this, 'filter_field' ] );
		add_filter( 'woocommerce_process_checkout_field_shipping_phone', [ $this, 'filter_field' ] );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_classic' ], 10, 2 );
	}

	/**
	 * `+380XXXXXXXXX` or null.
	 *
	 * @param string $raw Phone as typed.
	 */
	public static function normalise( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 40 ) {
			return null;
		}
		// Only digits and the usual separators; `+` only at the very start.
		if ( 1 !== preg_match( '/^\+?[0-9\s\-\.\(\)]+$/', $raw ) ) {
			return null;
		}
		$digits = (string) preg_replace( '/\D+/', '', $raw );
		$plus   = str_starts_with( $raw, '+' );

		if ( 10 === strlen( $digits ) && '0' === $digits[0] && '00' !== substr( $digits, 0, 2 ) && ! $plus ) {
			return '+38' . $digits;
		}
		if ( 12 === strlen( $digits ) && str_starts_with( $digits, '380' ) ) {
			return '+' . $digits;
		}
		return null;
	}

	/**
	 * Localised copy of the field error.
	 */
	public static function message(): string {
		return __( 'Введіть номер телефону у форматі +38 0XX XXX XX XX', 'vextra-core' );
	}

	/**
	 * Store API: write the normalised phones to the order being processed.
	 *
	 * @param mixed $order   Order.
	 * @param mixed $request Request.
	 */
	public function normalise_order( $order, $request = null ): void {
		unset( $request );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$billing = self::normalise( (string) $order->get_billing_phone() );
		if ( null !== $billing && $billing !== $order->get_billing_phone() ) {
			$order->set_billing_phone( $billing );
		}
		$shipping = self::normalise( (string) $order->get_shipping_phone() );
		if ( null !== $shipping && $shipping !== $order->get_shipping_phone() ) {
			$order->set_shipping_phone( $shipping );
		}
	}

	/**
	 * Store API address validation (location `address`, group `billing` / `shipping`).
	 *
	 * @param mixed $errors WP_Error collecting field errors.
	 * @param mixed $fields Address fields (key => value).
	 * @param mixed $group  billing|shipping|other.
	 */
	public function validate_address( $errors, $fields = [], $group = '' ): void {
		if ( ! $errors instanceof \WP_Error || ! is_array( $fields ) || ! in_array( $group, [ 'billing', 'shipping' ], true ) ) {
			return;
		}
		$phone = isset( $fields['phone'] ) && is_string( $fields['phone'] ) ? $fields['phone'] : '';
		if ( '' === trim( $phone ) ) {
			return; // Woo's own required check reports an empty phone.
		}
		if ( null === self::normalise( $phone ) ) {
			$errors->add( self::ERROR_CODE, self::message() );
		}
	}

	/**
	 * Classic checkout: stored value is the normalised one (an invalid value is left for the validation step below).
	 *
	 * @param mixed $value Posted value.
	 * @return mixed
	 */
	public function filter_field( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$normalised = self::normalise( $value );
		return null !== $normalised ? $normalised : $value;
	}

	/**
	 * Classic checkout validation.
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors WP_Error.
	 */
	public function validate_classic( $data, $errors = null ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
			return;
		}
		foreach ( [ 'billing_phone', 'shipping_phone' ] as $key ) {
			$phone = isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
			if ( '' !== trim( $phone ) && null === self::normalise( $phone ) ) {
				$errors->add(
					$key . '_validation',
					self::message(),
					[ 'id' => $key ]
				);
			}
		}
	}
}

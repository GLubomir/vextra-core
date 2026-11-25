<?php
/**
 * Ukrainian address fields (TASK-045a, F9): Woo's country locale for `UA` through `woocommerce_get_country_locale` (the block checkout reads the
 * same locale through `wcSettings`, the classic form through the same filter), so one definition feeds both.
 *
 * Result: country hidden (single country, value stays `UA`), postcode hidden and not required, region = `state` (label "Область", required, Woo's UA
 * states: Kyiv city is its own state), city "Місто або село", street "Вулиця та номер будинку", apartment "Квартира, офіс" (optional), phone required.
 * Order: first name, last name, phone, region, city, street, apartment. Labels are msgids in Ukrainian (`vextra-core` text domain, RU in the .po).
 * Order summary / admin order screen print no postcode line (an empty address part is dropped by Woo's formatter).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Checkout;

final class Fields {

	public function register(): void {
		add_filter( 'woocommerce_get_country_locale', [ $this, 'locale' ], 20 );
	}

	/**
	 * Locale of `UA` (all other countries untouched).
	 *
	 * @param mixed $locale Country => field overrides.
	 * @return mixed
	 */
	public function locale( $locale ) {
		if ( ! is_array( $locale ) ) {
			return $locale;
		}
		$ua = is_array( $locale['UA'] ?? null ) ? $locale['UA'] : [];

		$ua['first_name'] = array_merge(
			is_array( $ua['first_name'] ?? null ) ? $ua['first_name'] : [],
			[
				'label'    => __( 'Ім\'я', 'vextra-core' ),
				'priority' => 10,
			]
		);
		$ua['last_name']  = array_merge(
			is_array( $ua['last_name'] ?? null ) ? $ua['last_name'] : [],
			[
				'label'    => __( 'Прізвище', 'vextra-core' ),
				'priority' => 20,
			]
		);
		$ua['phone']      = array_merge(
			is_array( $ua['phone'] ?? null ) ? $ua['phone'] : [],
			[
				'label'    => __( 'Телефон', 'vextra-core' ),
				'required' => true,
				'priority' => 30,
			]
		);
		$ua['state']      = array_merge(
			is_array( $ua['state'] ?? null ) ? $ua['state'] : [],
			[
				'label'    => __( 'Область', 'vextra-core' ),
				'required' => true,
				'priority' => 40,
			]
		);
		$ua['city']       = array_merge(
			is_array( $ua['city'] ?? null ) ? $ua['city'] : [],
			[
				'label'    => __( 'Місто або село', 'vextra-core' ),
				'required' => true,
				'priority' => 50,
			]
		);
		$ua['address_1']  = array_merge(
			is_array( $ua['address_1'] ?? null ) ? $ua['address_1'] : [],
			[
				'label'       => __( 'Вулиця та номер будинку', 'vextra-core' ),
				'placeholder' => '',
				'required'    => true,
				'priority'    => 60,
			]
		);
		$ua['address_2']  = array_merge(
			is_array( $ua['address_2'] ?? null ) ? $ua['address_2'] : [],
			[
				'label'       => __( 'Квартира, офіс', 'vextra-core' ),
				'placeholder' => '',
				'required'    => false,
				'priority'    => 70,
			]
		);
		$ua['postcode']   = array_merge(
			is_array( $ua['postcode'] ?? null ) ? $ua['postcode'] : [],
			[
				'required' => false,
				'hidden'   => true,
				'priority' => 90,
			]
		);
		$ua['country']    = array_merge(
			is_array( $ua['country'] ?? null ) ? $ua['country'] : [],
			[
				'hidden'   => true,
				'priority' => 5,
			]
		);

		$locale['UA'] = $ua;
		return $locale;
	}
}

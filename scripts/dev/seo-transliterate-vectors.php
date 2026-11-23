<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	exit( 1 ); // Local-only dev script (WP-CLI + local env).
}
/**
 * Local check of the transliteration vectors of TASK-009e (AC1-AC3 + RU registry). Pure PHP, no WordPress needed:
 * `php scripts/dev/seo-transliterate-vectors.php` (exit 1 on a mismatch). Dev only, excluded from deploy archives.
 *
 * @package Vextra\Core
 */

require dirname( __DIR__, 2 ) . '/src/Seo/Transliterator.php';

$t   = new Vextra\Core\Seo\Transliterator();
$bad = 0;
$uk  = [
	'Київ'                      => 'kyiv',
	'Єнот'                      => 'yenot',
	'Зелений'                   => 'zelenyi',
	"Краса і здоров'я"          => 'krasa-i-zdorovia',
	'Згода'                     => 'zghoda',
	'Ґанок'                     => 'ganok',
	'Щастя'                     => 'shchastia',
	'Юрій'                      => 'yurii',
	'Яблуко'                    => 'yabluko',
	'Дрібна кухонна техніка'    => 'dribna-kukhonna-tekhnika',
	'iPhone 15 Про'             => 'iphone-15-pro',
	'Їжа'                       => 'yizha',
	'Україна'                   => 'ukraina',
	'Мій'                       => 'mii',
	'Ювілей'                    => 'yuvilei',
	'Горілка'                   => 'horilka',
	'Samsung Galaxy S24 Ультра' => 'samsung-galaxy-s24-ultra',
];
$ru  = [
	'Бытовая техника'           => 'bytovaya-tekhnika',
	'Одежда для детей'          => 'odezhda-dlya-detey',
	'Щётка'                     => 'shchetka',
	'Объектив'                  => 'obektiv',
	'Юла'                       => 'yula',
	'Электроника'               => 'elektronika',
	'Samsung Galaxy S24 Ультра' => 'samsung-galaxy-s24-ultra',
];
foreach ( [ 'uk' => $uk, 'ru' => $ru ] as $lang => $set ) {
	foreach ( $set as $in => $expected ) {
		$got = $t->transliterate( $in, $lang );
		if ( $got !== $expected ) {
			++$bad;
			echo "FAIL [$lang] $in => $got (expected $expected)\n";
		}
	}
}

$long = $t->transliterate( str_repeat( 'Дуже довге слово ', 10 ), 'uk' );
if ( strlen( $long ) > 60 || ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $long ) ) {
	++$bad;
	echo "FAIL length: $long\n";
}
if ( '' !== $t->transliterate( '!!! ???', 'uk' ) ) {
	++$bad;
	echo "FAIL punctuation-only must be empty\n";
}

$registry = dirname( __DIR__, 4 ) . '/themes/vextra/docs/seo/ru-category-slugs.md';
$checked  = 0;
if ( is_readable( $registry ) ) {
	foreach ( explode( "\n", (string) file_get_contents( $registry ) ) as $line ) {
		if ( preg_match( '/^\| \d+ \| (.+?) \| `(.+?)` \| (.+?) \| `(.+?)` \| `.+?` \| (.*?) \|$/u', $line, $m ) && '' === trim( $m[5] ) ) {
			++$checked;
			$got = $t->transliterate( $m[3], 'ru' );
			if ( $got !== $m[4] ) {
				++$bad;
				echo "FAIL registry {$m[3]} => $got (registered {$m[4]})\n";
			}
		}
	}
}
echo "RU registry rows compared: $checked (rows with a hand-picked note are skipped)\n", $bad ? "FAILED: $bad\n" : "OK\n";
exit( $bad ? 1 : 0 );

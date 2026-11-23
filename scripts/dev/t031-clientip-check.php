<?php
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 ); // Local-only dev script: command line only, never over HTTP.
}
// Local-only: prints deterministic ClientIp outputs (diff before/after TASK-031). Run: wp eval-file.
require_once dirname( __DIR__, 2 ) . '/src/Guard/ClientIp.php';
use Vextra\Core\Guard\ClientIp;
$ips = [ '1.2.3.4', '2001:db8:1:2:3:4:5:6', '::ffff:1.2.3.4', '::1', 'garbage', '', '999.1.1.1', '2001:db8::' ];
foreach ( $ips as $ip ) {
	echo json_encode( [ $ip, ClientIp::mask( $ip ), ClientIp::normalize( $ip ), ClientIp::in_list( $ip, [ '1.2.3.0/24', '2001:db8::/32', '::ffff:1.2.3.4', '', 'bad', '10.0.0.1/33' ] ) ] ), "\n";
}
echo md5( ClientIp::mask( '2001:db8:1:2:ffff::1' ) ), "\n";

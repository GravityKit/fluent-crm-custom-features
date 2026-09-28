<?php
// Prints Cookie headers for an administrator and a throwaway subscriber (created here, deleted by the caller).
$admin = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 0 );
$sub   = username_exists( 't402sub' ) ?: wp_insert_user( [ 'user_login' => 't402sub', 'user_email' => 't402sub@gravitykit-t402.dev', 'user_pass' => wp_generate_uuid4(), 'role' => 'subscriber' ] );
foreach ( [ 'ADMIN' => $admin, 'SUB' => (int) $sub ] as $label => $id ) {
	$exp  = time() + HOUR_IN_SECONDS;
	$auth = wp_generate_auth_cookie( $id, $exp, 'auth' );
	$in   = wp_generate_auth_cookie( $id, $exp, 'logged_in' );
	echo $label, '=', AUTH_COOKIE, '=', $auth, '; ', LOGGED_IN_COOKIE, '=', $in, "\n";
}

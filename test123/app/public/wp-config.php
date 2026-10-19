<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          '4,.ZGb(sai5S0f<(ovo2~ReBsn>NGGia?pcyyeHCA|U;}=V)Hu3j@g.whzrVMZz8' );
define( 'SECURE_AUTH_KEY',   'e<PcHgH.C*q:;Ql/)*4(&IvY)7(z^aDbL#{kd+U~sv2hc:^z7?S0=@v*_i3V!wva' );
define( 'LOGGED_IN_KEY',     '6A#=+CzL*W(:!_{?RRvz#,v JC5eVrX#PZ1Tg3}4-oJHphTj+73^, q7T|4 gyZC' );
define( 'NONCE_KEY',         'jS+=N!H<$c.e12S,ti-W{s?$/of)nG0(d7I{oNtLV_^?(vl_3}Y57(tJG Huc/][' );
define( 'AUTH_SALT',         '7Ck+X=~5iA?@`0E>/px)<eck3DPs)&}%dgOnl)a*]HnGYagLEQr>2SyiJV>#|]&&' );
define( 'SECURE_AUTH_SALT',  'T(5e{^z5O Bf>1v31wpP$`~^FU0$,OM>hwsk@n~#>bb6DAZ%vt=|mjM&(q:Ft]2a' );
define( 'LOGGED_IN_SALT',    '1tvB`H|DyCk-q%Fwo`F_k&1?YXS!cI=/bta!Hv_($emt6=k{=_sA;o^c&n&~$(rF' );
define( 'NONCE_SALT',        '0nWQI2:C::@u`du0JBSkr^)rFn#F-}Gy<PH%*ujdd]tTB5?r.N$$!+N!i#XA&y((' );
define( 'WP_CACHE_KEY_SALT', 'k@U=Pc7u$wCm!2lBW.$v6n8>2C{TQU]9EqOEhXp2lx#:+_rzo}(3njERn(`/T7FA' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'WP_ENVIRONMENT_TYPE', 'local' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

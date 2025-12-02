<?php
// This drop-in executes before regular plugins load. It removes plugins
// according to per-user cookies set by Dan's Debloater.

if ( ! defined( 'ABSPATH' ) ) {
    return;
}

if ( ! function_exists( 'dans_debloater_decode_cookie_list' ) ) {
    function dans_debloater_decode_cookie_list( $cookie_name ) {
        if ( empty( $_COOKIE[ $cookie_name ] ) ) {
            return array();
        }

        $raw = base64_decode( $_COOKIE[ $cookie_name ], true );
        if ( false === $raw ) {
            return array();
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['plugins'] ) || ! is_array( $data['plugins'] ) ) {
            return array();
        }

        $plugins = array_values( array_filter( $data['plugins'], 'is_string' ) );

        if ( empty( $data['sig'] ) ) {
            return array();
        }

        $expected = hash_hmac( 'sha256', wp_json_encode( $plugins ), defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
        if ( ! hash_equals( $expected, $data['sig'] ) ) {
            return array();
        }

        return $plugins;
    }

    function dans_debloater_decode_legacy_cookie( $cookie_name ) {
        if ( empty( $_COOKIE[ $cookie_name ] ) ) {
            return array();
        }
        $raw = base64_decode( $_COOKIE[ $cookie_name ], true );
        if ( false === $raw ) {
            return array();
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return array();
        }

        $plugins = array();
        foreach ( $data as $plugin_file => $conf ) {
            if ( ! empty( $conf['complete'] ) || ! empty( $conf['admin_only'] ) ) {
                $plugins[] = $plugin_file;
            } elseif ( true === $conf ) { // very early versions stored booleans
                $plugins[] = $plugin_file;
            }
        }
        return $plugins;
    }

    function dans_debloater_get_plugin_blocks() {
        $complete = dans_debloater_decode_cookie_list( 'dans_debloater_complete' );
        $admin_only = dans_debloater_decode_cookie_list( 'dans_debloater_admin' );

        if ( empty( $complete ) && ! empty( $_COOKIE['dans_debloater_blocks'] ) ) {
            $complete = dans_debloater_decode_legacy_cookie( 'dans_debloater_blocks' );
        }

        if ( empty( $admin_only ) && ! empty( $_COOKIE['dans_debloater_admin_blocks'] ) ) {
            $admin_only = dans_debloater_decode_legacy_cookie( 'dans_debloater_admin_blocks' );
        }

        return array(
            'complete'   => $complete,
            'admin_only' => $admin_only,
        );
    }

    function dans_debloater_filter_plugins( $active_plugins ) {
        // Allow bypass using ?safemode in the URL (be permissive: check GET, query string, and request URI)
        $safemode = false;
        if ( isset( $_GET['safemode'] ) ) {
            $safemode = true;
        } else {
            $qs = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '';
            $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
            $extras = '';
            if ( isset( $_SERVER['HTTP_X_ORIGINAL_URI'] ) ) {
                $extras .= $_SERVER['HTTP_X_ORIGINAL_URI'];
            }
            if ( isset( $_SERVER['HTTP_X_REWRITE_URL'] ) ) {
                $extras .= $_SERVER['HTTP_X_REWRITE_URL'];
            }

            if ( strpos( $qs, 'safemode' ) !== false || strpos( $uri, 'safemode' ) !== false || strpos( $extras, 'safemode' ) !== false ) {
                $safemode = true;
            }
        }

        if ( $safemode ) {
            return $active_plugins;
        }
        if ( ! is_array( $active_plugins ) || empty( $active_plugins ) ) {
            return $active_plugins;
        }

        $blocks = dans_debloater_get_plugin_blocks();
        if ( empty( $blocks['complete'] ) && empty( $blocks['admin_only'] ) ) {
            return $active_plugins;
        }

        $should_remove = $blocks['complete'];

        if ( is_admin() ) {
            $should_remove = array_merge( $should_remove, $blocks['admin_only'] );
        }

        if ( empty( $should_remove ) ) {
            return $active_plugins;
        }

        $should_remove = array_unique( $should_remove );

        $filtered = array();
        foreach ( $active_plugins as $key => $plugin_file ) {
            if ( in_array( $plugin_file, $should_remove, true ) ) {
                continue;
            }
            $filtered[ $key ] = $plugin_file;
        }

        return array_values( $filtered );
    }
}

add_filter( 'option_active_plugins', 'dans_debloater_filter_plugins', PHP_INT_MAX );

add_filter( 'site_option_active_sitewide_plugins', function( $active ) {
    // Respect safemode bypass (check GET and raw querystring)
    $safemode = false;
    if ( isset( $_GET['safemode'] ) ) {
        $safemode = true;
    } else {
        $qs = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '';
        if ( strpos( $qs, 'safemode' ) !== false ) {
            $safemode = true;
        }
    }

    if ( $safemode ) {
        return $active;
    }
    if ( empty( $active ) || ! is_array( $active ) ) {
        return $active;
    }

    $blocks = dans_debloater_get_plugin_blocks();
    if ( empty( $blocks['complete'] ) && empty( $blocks['admin_only'] ) ) {
        return $active;
    }

    $should_remove = $blocks['complete'];
    if ( is_admin() ) {
        $should_remove = array_merge( $should_remove, $blocks['admin_only'] );
    }

    if ( empty( $should_remove ) ) {
        return $active;
    }

    $should_remove = array_unique( $should_remove );

    foreach ( $should_remove as $plugin_file ) {
        if ( isset( $active[ $plugin_file ] ) ) {
            unset( $active[ $plugin_file ] );
        }
    }

    return $active;
}, PHP_INT_MAX );

<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dans_Debloater_Admin {
    private static $instance;
    protected static $user_meta_key = 'dans_debloater_blocks';
    protected $should_block = array();
    protected $filters_registered = false;

    const COOKIE_COMPLETE = 'dans_debloater_complete';
    const COOKIE_ADMIN = 'dans_debloater_admin';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        // Register instance REST routes too (safe duplicate when instantiated in admin)
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'plugins_loaded', array( $this, 'apply_user_blocks' ), PHP_INT_MAX );
    }

    /**
     * Determine which plugins should be blocked for the current user and
     * register request-scoped filters to remove them from the active lists.
     */
    public function apply_user_blocks() {
        // If safemode is present in the URL or query string, do not block any plugins for this request.
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
            return;
        }

        if ( $this->filters_registered ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( empty( $user_id ) ) {
            return;
        }

        $settings = get_user_meta( $user_id, self::$user_meta_key, true );
        if ( ! is_array( $settings ) || empty( $settings ) ) {
            return;
        }

        $block_always = array();
        $block_admin = array();

        foreach ( $settings as $plugin_file => $config ) {
            if ( ! is_array( $config ) ) {
                continue;
            }

            if ( ! empty( $config['complete'] ) ) {
                $block_always[] = $plugin_file;
                continue;
            }

            if ( ! empty( $config['admin_only'] ) ) {
                $block_admin[] = $plugin_file;
            }
        }

        $block_list = $block_always;
        if ( is_admin() ) {
            $block_list = array_merge( $block_list, $block_admin );
        }

        $block_list = array_diff( array_unique( $block_list ), array( plugin_basename( DANS_DEBLOATER_PLUGIN_FILE ) ) );

        if ( empty( $block_list ) ) {
            return;
        }

        $this->should_block = $block_list;

        add_filter( 'option_active_plugins', array( $this, 'filter_active_plugins' ), PHP_INT_MAX );
        add_filter( 'site_option_active_sitewide_plugins', array( $this, 'filter_sitewide_plugins' ), PHP_INT_MAX );

        $this->filters_registered = true;
    }

    /**
     * Filter the per-site active plugin list for the current request.
     *
     * @param array $active_plugins
     * @return array
     */
    public function filter_active_plugins( $active_plugins ) {
        if ( empty( $this->should_block ) || ! is_array( $active_plugins ) ) {
            return $active_plugins;
        }

        $filtered = array();
        foreach ( $active_plugins as $plugin_file ) {
            if ( in_array( $plugin_file, $this->should_block, true ) ) {
                continue;
            }
            $filtered[] = $plugin_file;
        }

        return $filtered;
    }

    /**
     * Filter the network-active plugin list for the current request.
     *
     * @param array $active_plugins
     * @return array
     */
    public function filter_sitewide_plugins( $active_plugins ) {
        if ( empty( $this->should_block ) || ! is_array( $active_plugins ) ) {
            return $active_plugins;
        }

        foreach ( $this->should_block as $plugin_file ) {
            if ( isset( $active_plugins[ $plugin_file ] ) ) {
                unset( $active_plugins[ $plugin_file ] );
            }
        }

        return $active_plugins;
    }

    public function register_menus() {
        $parent_slug = 'dans-tools';
        // Add top-level Dan's Tools only if it doesn't already exist
        $parent_exists = false;
        if ( isset( $GLOBALS['menu'] ) && is_array( $GLOBALS['menu'] ) ) {
            foreach ( $GLOBALS['menu'] as $m ) {
                if ( isset( $m[2] ) && $m[2] === $parent_slug ) {
                    $parent_exists = true;
                    break;
                }
            }
        }

        if ( ! $parent_exists ) {
            add_menu_page( "Dan's Tools", "Dan's Tools", 'manage_options', $parent_slug, '', 'dashicons-hammer', 60 );
        }

        // Add our Debloater submenu under Dan's Tools (whether newly created or pre-existing)
        // Menu label is shortened to "Debloater" so the admin menu reads: Dan's Tools -> Debloater
        add_submenu_page( $parent_slug, "Dan's Debloater", "Debloater", 'manage_options', 'dans-debloater', array( $this, 'render_admin_page' ) );
    }

    public function enqueue_assets( $hook ) {
        // only enqueue for our page
        if ( isset( $_GET['page'] ) && 'dans-debloater' === $_GET['page'] ) {
            wp_enqueue_style( 'dans-debloater-admin', plugins_url( '../assets/css/admin.css', __FILE__ ), array(), '0.1.0' );
            wp_enqueue_script( 'dans-debloater-admin', plugins_url( '../assets/js/admin.js', __FILE__ ), array( 'jquery' ), '0.1.0', true );
            // generate a short-lived signed token so REST requests can be authenticated without relying on cookies
            $uid = get_current_user_id();
            $exp = time() + 300; // 5 minutes
            $sig = hash_hmac( 'sha256', $uid . '|' . $exp, AUTH_SALT );
            $token = base64_encode( wp_json_encode( array( 'uid' => $uid, 'exp' => $exp, 'sig' => $sig ) ) );

            wp_localize_script( 'dans-debloater-admin', 'DansDebloater', array(
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'rest_base' => esc_url_raw( rest_url( '/dans-debloater/v1/settings' ) ),
                'rest_dropin' => esc_url_raw( rest_url( '/dans-debloater/v1/dropin' ) ),
                'auth_token' => $token,
                'dropinInstalled' => self::is_dropin_installed(),
                'dropinWritable' => self::dropin_is_writable(),
            ) );
        }
    }

    public function register_rest_routes() {
        // Delegate to static registration to avoid duplication of route definitions
        self::register_rest_routes_static();
    }

    public static function register_rest_routes_static() {
        register_rest_route( 'dans-debloater/v1', '/settings', array(
            array(
                'methods' => 'GET',
                'callback' => array( __CLASS__, 'rest_get_settings_static' ),
                'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
            ),
            array(
                'methods' => 'POST',
                'callback' => array( __CLASS__, 'rest_save_settings_static' ),
                'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
            ),
        ) );

        register_rest_route( 'dans-debloater/v1', '/dropin', array(
            array(
                'methods' => 'POST',
                'callback' => array( __CLASS__, 'rest_manage_dropin_static' ),
                'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
            ),
        ) );
    }

    public static function rest_permission_check( $request ) {
        // Try multiple locations for the nonce: server header, request header, and request params/body
        $nonce = null;

        if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
            $nonce = wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] );
        }

        // Try REST-request header access (case-insensitive)
        if ( empty( $nonce ) && method_exists( $request, 'get_header' ) ) {
            $h = $request->get_header( 'x-wp-nonce' );
            if ( $h ) {
                $nonce = wp_unslash( $h );
            }
        }

        // Try common param names in body/query
        if ( empty( $nonce ) ) {
            $possible = array( '_wpnonce', 'nonce', 'wpnonce', 'x_wp_nonce' );
            foreach ( $possible as $p ) {
                $val = $request->get_param( $p );
                if ( $val ) {
                    $nonce = wp_unslash( $val );
                    break;
                }
            }
        }

        // If a valid nonce is present, accept it (provided the current user has capability)
        if ( ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            if ( current_user_can( 'manage_options' ) ) {
                return true;
            }
            return new WP_Error( 'rest_forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
        }

        // Check for our short-lived signed auth token to allow stateless auth without cookies
        $token = $request->get_param( 'auth_token' );
        if ( $token ) {
            $decoded = json_decode( base64_decode( $token ), true );
            if ( is_array( $decoded ) && isset( $decoded['uid'], $decoded['exp'], $decoded['sig'] ) ) {
                // verify signature and expiry
                $expected = hash_hmac( 'sha256', $decoded['uid'] . '|' . $decoded['exp'], AUTH_SALT );
                if ( hash_equals( $expected, $decoded['sig'] ) && $decoded['exp'] >= time() ) {
                    // set current user for this request
                    wp_set_current_user( intval( $decoded['uid'] ) );
                    if ( current_user_can( 'manage_options' ) ) {
                        return true;
                    }
                    return new WP_Error( 'rest_forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
                }
            }
        }

        // Fallback: if cookie-based authentication already identifies the current user and they have capability, allow.
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Debug logging to help diagnose nonce/auth failures. Remove in production.
        $debug = array(
            'server_header' => isset($_SERVER['HTTP_X_WP_NONCE']) ? wp_unslash($_SERVER['HTTP_X_WP_NONCE']) : null,
            'request_header' => method_exists($request,'get_header') ? $request->get_header('x-wp-nonce') : null,
            'params' => $request->get_params(),
            'current_user_id' => get_current_user_id(),
            'is_user_logged_in' => is_user_logged_in(),
        );
        // Check individual nonce verification results for helpfulness
        if ( ! empty( $debug['server_header'] ) ) {
            $debug['server_header_verify'] = wp_verify_nonce( $debug['server_header'], 'wp_rest' ) ? true : false;
        }
        if ( ! empty( $debug['request_header'] ) ) {
            $debug['request_header_verify'] = wp_verify_nonce( $debug['request_header'], 'wp_rest' ) ? true : false;
        }
        $possible = array( '_wpnonce', 'nonce', 'wpnonce', 'x_wp_nonce' );
        foreach ( $possible as $p ) {
            $v = $request->get_param( $p );
            if ( $v ) {
                $debug['param_'.$p] = $v;
                $debug['param_'.$p.'_verify'] = wp_verify_nonce( $v, 'wp_rest' ) ? true : false;
            }
        }

        if ( function_exists( 'error_log' ) ) {
            error_log( 'DansDebloater REST auth debug: ' . wp_json_encode( $debug ) );
        }

        return new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );
    }

    public static function rest_get_settings_static( $request ) {
        $user_id = get_current_user_id();
        $data = get_user_meta( $user_id, self::$user_meta_key, true );
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        return rest_ensure_response( $data );
    }

    public static function rest_save_settings_static( $request ) {
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            return new WP_Error( 'invalid', 'Invalid payload', array( 'status' => 400 ) );
        }

        $user_id = get_current_user_id();

        // Normalize and enforce mutual exclusivity between 'complete' and 'admin_only'
        $normalized = array();
        foreach ( $params as $plugin => $conf ) {
            $complete = ! empty( $conf['complete'] );
            $admin_only = ! empty( $conf['admin_only'] );
            if ( $complete ) {
                $admin_only = false;
            } elseif ( $admin_only ) {
                $complete = false;
            }
            $normalized[ $plugin ] = array( 'complete' => (bool) $complete, 'admin_only' => (bool) $admin_only );
        }

        update_user_meta( $user_id, self::$user_meta_key, $normalized );

        self::refresh_block_cookies( $normalized );

        return rest_ensure_response( $normalized );
    }

    public static function rest_manage_dropin_static( $request ) {
        $action = $request->get_param( 'action' );
        if ( 'install' === $action ) {
            $result = self::install_dropin();
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( array( 'installed' => true ) );
        } elseif ( 'remove' === $action ) {
            $result = self::remove_dropin();
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( array( 'installed' => false ) );
        }

        return new WP_Error( 'invalid_action', 'Unknown drop-in action.', array( 'status' => 400 ) );
    }

    protected static function refresh_block_cookies( $normalized ) {
        if ( headers_sent() ) {
            return;
        }

        $complete = array();
        $admin = array();
        $self_basename = plugin_basename( DANS_DEBLOATER_PLUGIN_FILE );

        foreach ( $normalized as $plugin => $conf ) {
            if ( $plugin === $self_basename ) {
                continue;
            }
            if ( ! empty( $conf['complete'] ) ) {
                $complete[] = $plugin;
            } elseif ( ! empty( $conf['admin_only'] ) ) {
                $admin[] = $plugin;
            }
        }

        self::set_cookie_payload( self::COOKIE_COMPLETE, $complete, '/' );
        self::set_cookie_payload( self::COOKIE_ADMIN, $admin, '/wp-admin' );
    }

    protected static function set_cookie_payload( $name, $plugins, $path ) {
        $domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

        if ( empty( $plugins ) ) {
            setcookie( $name, '', time() - 3600, $path, $domain, is_ssl(), true );
            return;
        }

        $payload = array(
            'plugins' => array_values( $plugins ),
        );
        $payload['sig'] = hash_hmac( 'sha256', wp_json_encode( $payload['plugins'] ), AUTH_SALT );
        $encoded = base64_encode( wp_json_encode( $payload ) );
        setcookie( $name, $encoded, time() + MONTH_IN_SECONDS, $path, $domain, is_ssl(), true );
    }

    protected static function dropin_path() {
        return trailingslashit( WPMU_PLUGIN_DIR ) . 'dans-debloater-mu.php';
    }

    protected static function dropin_is_writable() {
        $dir = dirname( self::dropin_path() );

        if ( is_dir( $dir ) ) {
            return is_writable( $dir );
        }

        $parent = dirname( $dir );
        return is_dir( $parent ) && is_writable( $parent );
    }

    public static function is_dropin_installed() {
        return file_exists( self::dropin_path() );
    }

    protected static function install_dropin() {
        $dir = dirname( self::dropin_path() );
        if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'mkdir_failed', 'Unable to create mu-plugins directory.' );
        }

        if ( ! is_writable( $dir ) ) {
            return new WP_Error( 'not_writable', 'mu-plugins directory is not writable.' );
        }

        $template = DANS_DEBLOATER_PLUGIN_DIR . 'includes/mu-plugin-template.php';
        if ( ! file_exists( $template ) ) {
            return new WP_Error( 'missing_template', 'Missing drop-in template.' );
        }

        $contents = file_get_contents( $template );
        if ( false === $contents ) {
            return new WP_Error( 'read_failed', 'Unable to read drop-in template.' );
        }

        $result = file_put_contents( self::dropin_path(), $contents );
        if ( false === $result ) {
            return new WP_Error( 'write_failed', 'Unable to write drop-in file.' );
        }

        return true;
    }

    protected static function remove_dropin() {
        if ( ! self::is_dropin_installed() ) {
            return true;
        }

        if ( ! is_writable( self::dropin_path() ) ) {
            return new WP_Error( 'not_writable', 'Drop-in file is not writable.' );
        }

        $result = unlink( self::dropin_path() );
        if ( ! $result ) {
            return new WP_Error( 'unlink_failed', 'Unable to remove drop-in file.' );
        }

        return true;
    }

    public function rest_get_settings( $request ) {
        return self::rest_get_settings_static( $request );
    }

    public function rest_save_settings( $request ) {
        return self::rest_save_settings_static( $request );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Access denied', 'dans-debloater' ) );
        }

        $all = get_plugins();
        // Retrieve the raw active_plugins option from the database to avoid mu-plugin filtering
        global $wpdb;
        $option_value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
        $active = maybe_unserialize( $option_value );
        if ( ! is_array( $active ) ) {
            $active = array();
        }

        $user_id = get_current_user_id();
        $settings = get_user_meta( $user_id, self::$user_meta_key, true );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        self::refresh_block_cookies( $settings );

        $dropin_installed = self::is_dropin_installed();
        $dropin_writable = self::dropin_is_writable();

        ?>
        <div class="wrap dans-debloater-wrap">
            <header class="dd-header">
                <div class="dd-header-left">
                    <h1>Dan's Debloater</h1>
                    <p class="dd-sub">Per-user plugin blocking for admin scope. Use the controls below to temporarily prevent plugins from loading for your account.</p>
                </div>
            </header>
            <div style="margin:12px 0">
                <input type="search" id="dans-debloater-search" placeholder="Search plugins by name or description..." style="width:360px; padding:6px" />
                <span style="margin-left:12px; color:#666; font-size:12px">Filter the table as you type.</span>
            </div>
            <p class="description">Toggle blocking for plugins in your admin scope. Complete prevents the plugin from loading for you in the admin (when possible). Blocked plugins will still appear here so you can toggle them back.</p>

                <div class="notice notice-info">
                    <p><strong>Safe Mode:</strong> Append <code>?safemode=1</code> to the URL to temporarily disable all Debloater blocking for this request. Use this when troubleshooting or when you need to access functionality from a blocked plugin without changing your saved settings.</p>
                </div>

                <?php if ( isset( $_GET['safemode'] ) ) : ?>
                    <div class="notice notice-warning">
                        <p><strong>Safemode active:</strong> Blocking is disabled for this page load because the <code>safemode</code> GET parameter is present.</p>
                    </div>
                <?php endif; ?>

            <div class="dans-debloater-dropin-status notice <?php echo $dropin_installed ? 'notice-success' : 'notice-warning'; ?>">
                <p>
                    <?php if ( $dropin_installed ) : ?>
                        The early-load drop-in is installed. Blocking should take effect immediately.
                    <?php else : ?>
                        To stop plugins from loading before WordPress boots, install the drop-in. Without it, blocking can't take effect early enough.
                    <?php endif; ?>
                </p>
                <p>
                    <button class="button <?php echo $dropin_installed ? '' : 'button-primary'; ?>" id="dans-debloater-dropin-toggle" data-action="<?php echo $dropin_installed ? 'remove' : 'install'; ?>" <?php disabled( ! $dropin_writable && ! $dropin_installed ); ?>>
                        <?php echo $dropin_installed ? 'Remove Drop-in' : 'Install Drop-in'; ?>
                    </button>
                    <span id="dans-debloater-dropin-status"></span>
                    <?php if ( ! $dropin_writable && ! $dropin_installed ) : ?>
                        <em>mu-plugins directory is not writable. Create <code><?php echo esc_html( self::dropin_path() ); ?></code> manually or adjust permissions.</em>
                    <?php endif; ?>
                </p>
            </div>

            <table class="widefat fixed dans-debloater-table">
                <thead>
                            <tr>
                                <th>Plugin</th>
                                <th style="width:120px">Status</th>
                                <th>Complete</th>
                                <th>Admin Only</th>
                            </tr>
                </thead>
                <tbody>
                <?php foreach ( $all as $plugin_file => $p ) :
                    // Do not list the debloater plugin itself in the table
                    if ( $plugin_file === plugin_basename( DANS_DEBLOATER_PLUGIN_FILE ) ) {
                        continue;
                    }
                    $state = isset( $settings[ $plugin_file ] ) ? $settings[ $plugin_file ] : array( 'complete' => false, 'admin_only' => false );
                    $is_active = in_array( $plugin_file, $active, true );
                ?>
                    <?php
                        // Determine status label for UX
                        $status_label = 'Active';
                        $row_classes = array();
                        if ( isset( $settings[ $plugin_file ] ) ) {
                            $conf = $settings[ $plugin_file ];
                            if ( ! empty( $conf['complete'] ) ) {
                                $status_label = 'Blocked (Complete)';
                                $row_classes[] = 'dd-blocked dd-blocked-complete';
                            } elseif ( ! empty( $conf['admin_only'] ) ) {
                                $status_label = 'Blocked (Admin-only)';
                                $row_classes[] = 'dd-blocked dd-blocked-admin';
                            }
                        }

                    ?>

                    <tr data-plugin="<?php echo esc_attr( $plugin_file ); ?>" class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>">
                        <td class="plugin-name"><strong><?php echo esc_html( $p['Name'] ); ?></strong>
                            <div class="row-description"><?php echo esc_html( $p['Description'] ); ?>
                            <?php if ( ! $is_active ) : ?>
                                <em style="color:#999; display:block; margin-top:6px">(inactive)</em>
                            <?php endif; ?>
                            </div>
                        </td>
                        <td class="plugin-status"><span class="dd-badge <?php echo esc_attr( $row_classes ? 'dd-badge-blocked' : 'dd-badge-active' ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
                        <td class="plugin-toggle"><label class="switch"><input type="checkbox" class="toggle-complete" <?php checked( $state['complete'], true ); ?>><span class="slider"></span></label></td>
                        <td class="plugin-toggle"><label class="switch"><input type="checkbox" class="toggle-adminonly" <?php checked( $state['admin_only'], true ); ?>><span class="slider"></span></label></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p><button class="button button-primary" id="dans-debloater-save">Save changes</button> <span id="dans-debloater-status"></span></p>
        </div>
        <?php
    }

}


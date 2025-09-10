<?php
/**
 * Plugin Name: Prometheus Metrics
 * Plugin URI: https://github.com/slydlake/wordpress-prometheus-metrics
 * Text Domain: prometheus-metrics
 * Description: Plugin to export metrics for prometheus
 * Author: Timon Först
 * Author URI: https://github.com/slydlake
 * Version: 1.0.1
 * License: MIT
 */

if ( ! class_exists( 'WP_Prometheus_Metrics' ) ) {

    /**
     * Simple class encapsulating exporter logic.
     * Keeps global functions minimal and makes testing easier.
     */
    class WP_Prometheus_Metrics {

        // cache metrics for a short time to avoid DB load (seconds)
        const CACHE_TTL = 10;
        const CACHE_KEY = 'wppe_metrics_cache';

        /**
         * Attach hooks.
         */
        public static function init() {
            add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
            // English comment: ensure we can short‑circuit the REST response and send raw text
            add_filter( 'rest_pre_serve_request', array( __CLASS__, 'rest_pre_serve_request' ), 10, 4 );
            register_activation_hook( __FILE__, array( __CLASS__, 'on_activate' ) );
            register_deactivation_hook( __FILE__, array( __CLASS__, 'on_deactivate' ) );
            
            // Admin interface
            add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
            add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
            
            // Add settings link to plugin page
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_links' ) );
            
            // Register custom rewrite rules for prometheus endpoints (always register)
            add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
            add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
            add_action( 'template_redirect', array( __CLASS__, 'handle_prometheus_request' ) );
            
            // Alternative URL handling for environments without proper REST API rewrites
            add_action( 'init', array( __CLASS__, 'handle_alternative_urls' ) );
            add_filter( 'request', array( __CLASS__, 'handle_request_filter' ) );
            
            // Check and refresh rewrite rules if needed (admin only)
            add_action( 'admin_init', array( __CLASS__, 'maybe_flush_rewrite_rules' ) );
        }

        /**
         * Register REST route for metrics.
         * Protected endpoint with authentication.
         */
        public static function register_routes() {
            register_rest_route(
                'wp-prometheus/v1',
                '/metrics',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_callback' ),
                    'permission_callback' => array( __CLASS__, 'check_auth' ),
                )
            );
        }

        /**
         * Check authentication for metrics endpoint.
         * Supports multiple auth methods.
         *
         * @param WP_REST_Request $request
         * @return bool|WP_Error
         */
        public static function check_auth( $request ) {
            // Option 1: Bearer Token in Authorization header
            $auth_header = $request->get_header( 'authorization' );
            
            // Also try alternative header methods that some servers use
            if ( ! $auth_header ) {
                $auth_header = $request->get_header( 'HTTP_AUTHORIZATION' );
            }
            if ( ! $auth_header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
                $auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
            }
            if ( ! $auth_header && function_exists( 'getallheaders' ) ) {
                $headers = getallheaders();
                if ( isset( $headers['Authorization'] ) ) {
                    $auth_header = $headers['Authorization'];
                }
            }
            
            if ( $auth_header && preg_match( '/Bearer\s+(.+)/', $auth_header, $matches ) ) {
                $token = trim( $matches[1] );
                $valid_token = self::get_auth_token( 'wp_prometheus_auth_token' );
                if ( $valid_token && hash_equals( $valid_token, $token ) ) {
                    return true;
                }
            }

            // Option 2: API Key as query parameter
            $api_key = $request->get_param( 'api_key' );
            if ( $api_key ) {
                $valid_api_key = self::get_auth_token( 'wp_prometheus_api_key' );
                if ( $valid_api_key && hash_equals( $valid_api_key, $api_key ) ) {
                    return true;
                }
            }

            // Option 3: WordPress user capabilities
            if ( current_user_can( 'manage_options' ) ) {
                return true;
            }

            return new WP_Error( 
                'rest_forbidden', 
                __( 'Authentication required for metrics endpoint.', 'prometheus-metrics' ), 
                array( 'status' => 401 ) 
            );
        }

        /**
         * REST callback that returns plain-text Prometheus metrics.
         * We return a WP_REST_Response and explicitly set Content-Type to text/plain
         * so Prometheus receives raw metrics (no JSON quoting).
         *
         * @param WP_REST_Request $request
         * @return WP_REST_Response
         */
        public static function rest_callback( $request ) {
            // try cache first
            $metrics = get_transient( self::CACHE_KEY );
            if ( false === $metrics ) {
                $metrics = self::build_metrics();
                set_transient( self::CACHE_KEY, $metrics, self::CACHE_TTL );
            }

            $response = new WP_REST_Response( $metrics, 200 );
            // ensure Prometheus sees plain text and not JSON
            $response->header( 'Content-Type', 'text/plain; charset=' . get_option( 'blog_charset' ) );

            return $response;
        }

        /**
         * Intercept REST framework final output for our metrics route and send raw text.
         *
         * @param bool                    $served  Whether the request has been served.
         * @param WP_REST_Response|mixed  $result  The result to be served.
         * @param WP_REST_Request         $request Current request.
         * @param WP_REST_Server          $server  Server instance.
         * @return bool
         */
        public static function rest_pre_serve_request( $served, $result, $request, $server ) {
            // English comment: only handle our metrics route
            $route = is_callable( array( $request, 'get_route' ) ) ? $request->get_route() : '';
            if ( $route !== '/wp-prometheus/v1/metrics' ) {
                return $served;
            }

            // Determine the raw payload (handle WP_REST_Response, string, WP_Error)
            $payload = null;
            if ( $result instanceof WP_REST_Response ) {
                $payload = $result->get_data();
            } elseif ( is_string( $result ) ) {
                $payload = $result;
            } elseif ( is_wp_error( $result ) ) {
                // fallback to error JSON for errors
                return $served;
            } else {
                // last resort: try to json encode
                $payload = wp_json_encode( $result );
            }

            // If payload is an array/object, try to extract string; otherwise cast
            if ( is_array( $payload ) || is_object( $payload ) ) {
                // if someone accidentally set data to an array, convert to string
                $payload = is_string( $payload ) ? $payload : wp_json_encode( $payload );
            }

            // English comment: send plain text and stop the REST framework from wrapping it in JSON
            if ( ! headers_sent() ) {
                header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw metrics output for Prometheus format
            echo (string) $payload;

            // Returning true tells WP REST to stop further processing
            return true;
        }

        /**
         * Build Prometheus-formatted metrics string.
         *
         * @return string
         */
        private static function build_metrics() : string {
            $out = '';

            // Name der Webseite für Labels
            $site_name = get_bloginfo( 'name' );


            // Users
            $users = count_users();
            $total_users = isset( $users['total_users'] ) ? (int) $users['total_users'] : 0;
            $avail_roles = isset( $users['avail_roles'] ) && is_array( $users['avail_roles'] ) ? $users['avail_roles'] : array();

            $out .= "# HELP wp_users Number of users per role.\n";
            $out .= "# TYPE wp_users counter\n";

            // Ausgabe pro Rolle als Label role="..."
            foreach ( $avail_roles as $role => $count ) {
                $out .= 'wp_users{wp_site="' . self::escape_label_value( $site_name ) . '",role="' . self::escape_label_value( $role ) . '"} ' . (int) $count . "\n";
            }

            // Gesamtzahl als eigene Zeile (optional)
            $out .= 'wp_users{wp_site="' . self::escape_label_value( $site_name ) . '",role="total"} ' . $total_users . "\n";

            // Posts (default post type)
            $posts = wp_count_posts();
            $posts_pub = isset( $posts->publish ) ? (int) $posts->publish : 0;
            $posts_draft = isset( $posts->draft ) ? (int) $posts->draft : 0;

            // Gesamtzahl aller Posts aus allen Status ermitteln
            $posts_total = 0;
            if ( is_object( $posts ) ) {
                foreach ( get_object_vars( $posts ) as $cnt ) {
                    $posts_total += (int) $cnt;
                }
            }

            $out .= "# HELP wp_posts number of posts.\n";
            $out .= "# TYPE wp_posts counter\n";
            $out .= 'wp_posts{wp_site="' . self::escape_label_value( $site_name ) . '",status="published"} ' . $posts_pub . "\n";
            $out .= 'wp_posts{wp_site="' . self::escape_label_value( $site_name ) . '",status="draft"} ' . $posts_draft . "\n";
            $out .= 'wp_posts{wp_site="' . self::escape_label_value( $site_name ) . '",status="all"} ' . $posts_total . "\n";

            // Pages
            $pages = wp_count_posts( 'page' );
            $pages_pub = isset( $pages->publish ) ? (int) $pages->publish : 0;
            $pages_draft = isset( $pages->draft ) ? (int) $pages->draft : 0;
            $out .= "# HELP wp_pages number of pages.\n";
            $out .= "# TYPE wp_pages counter\n";
            $out .= 'wp_pages{wp_site="' . self::escape_label_value( $site_name ) . '",status="published"} ' . $pages_pub . "\n";
            $out .= 'wp_pages{wp_site="' . self::escape_label_value( $site_name ) . '",status="draft"} ' . $pages_draft . "\n";
            $out .= 'wp_pages{wp_site="' . self::escape_label_value( $site_name ) . '",status="all"} ' . ($pages_pub + $pages_draft) . "\n";

            // Active plugins
            // Plugins: installierte, aktive und inaktive korrekt ermitteln
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
            $total_installed_plugins = is_array( $all_plugins ) ? count( $all_plugins ) : 0;

            $active_plugins = get_option( 'active_plugins', array() );
            $active_count = is_array( $active_plugins ) ? count( $active_plugins ) : 0;

            // inaktive (installierte aber nicht aktivierte) Plugins
            $inactive_plugins = array_diff( array_keys( $all_plugins ), $active_plugins );
            $inactive_count = count( $inactive_plugins );

            // Metriken: active / inactive
            $out .= "# HELP wp_plugins number of active and inactive plugins.\n";
            $out .= "# TYPE wp_plugins counter\n";
            $out .= 'wp_plugins{wp_site="' . self::escape_label_value( $site_name ) . '",status="active"} ' . $active_count . "\n";
            $out .= 'wp_plugins{wp_site="' . self::escape_label_value( $site_name ) . '",status="inactive"} ' . $inactive_count . "\n";
            $out .= 'wp_plugins{wp_site="' . self::escape_label_value( $site_name ) . '",status="all"} ' . $total_installed_plugins . "\n";

            // Plugins mit verfügbarem Update (Transients prüfen)
            $updates = get_site_transient( 'update_plugins' );
            $updates_response = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
            $plugins_with_updates = array_keys( $updates_response );

            // Zähle ALLE installierten Plugins (aktive + inaktive), die ein Update brauchen
            $all_installed_plugins = array_keys( $all_plugins );
            $plugins_needing_update = array_intersect( $all_installed_plugins, $plugins_with_updates );

            // Alle installierte Plugins, die aktuell sind
            $plugins_up_to_date = array_diff( $all_installed_plugins, $plugins_needing_update );

            $out .= "# HELP wp_plugins_update Plugin update status.\n";
            $out .= "# TYPE wp_plugins_update counter\n";
            $out .= 'wp_plugins_update{wp_site="' . self::escape_label_value( $site_name ) . '",status="available"} ' . count( $plugins_needing_update ) . "\n";
            $out .= 'wp_plugins_update{wp_site="' . self::escape_label_value( $site_name ) . '",status="uptodate"} ' . count( $plugins_up_to_date ) . "\n";

            // Themes (installed)
            $themes = wp_get_themes();
            $child_count = 0;
            $parent_count = 0;

            if ( is_array( $themes ) ) {
                foreach ( $themes as $theme ) {
                    if ( $theme instanceof WP_Theme && method_exists( $theme, 'is_child_theme' ) && $theme->is_child_theme() ) {
                        $child_count++;
                    } else {
                        $parent_count++;
                    }
                }
            } elseif ( $themes instanceof WP_Theme ) {
                if ( method_exists( $themes, 'is_child_theme' ) && $themes->is_child_theme() ) {
                    $child_count = 1;
                } else {
                    $parent_count = 1;
                }
            }

            $out .= "# HELP wp_themes Number of installed themes.\n";
            $out .= "# TYPE wp_themes counter\n";
            $out .= 'wp_themes{wp_site="' . self::escape_label_value( $site_name ) . '",type="child"} ' . $child_count . "\n";
            $out .= 'wp_themes{wp_site="' . self::escape_label_value( $site_name ) . '",type="parent"} ' . $parent_count . "\n";

            // Comments
            $comments = wp_count_comments();
            // sicherstellen, dass wir ein Array von Status => count haben
            $counts = is_object( $comments ) ? get_object_vars( $comments ) : (array) $comments;

            $out .= "# HELP wp_comments Total number of comments by status.\n";
            $out .= "# TYPE wp_comments counter\n";

            // Ausgabe pro Status: wp_comments{wp_site="...",status="approved"} 123
            foreach ( $counts as $status => $count ) {
                // Skip non-numeric and the total field for now
                if ( $status === 'total_comments' || ! is_numeric( $count ) ) {
                    continue;
                }

                // Normalisierungen für gängige WP-Feldnamen
                if ( $status === 'awaiting_moderation' ) {
                    $label = 'moderated';
                } elseif ( $status === 'post-trashed' ) {
                    $label = 'post_trashed';
                } else {
                    $label = $status;
                }

                $out .= 'wp_comments{wp_site="' . self::escape_label_value( $site_name ) . '",status="' . $label . '"} ' . (int) $count . "\n";
            }


            // Categories
            $category_count = (int) wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
            $out .= "# HELP wp_categories Total number of categories.\n";
            $out .= "# TYPE wp_categories counter\n";
            $out .= 'wp_categories{wp_site="' . self::escape_label_value( $site_name ) . '"} ' . $category_count . "\n";

            // Media (attachments)
            $attachments = wp_count_posts( 'attachment' );
            $media_count = 0;

            if ( is_object( $attachments ) ) {
                // wp_count_posts gibt ein Objekt mit Status-Eigenschaften zurück (z. B. inherit, publish, trash ...)
                foreach ( get_object_vars( $attachments ) as $count ) {
                    $media_count += (int) $count;
                }
            } else {
                // fallback falls wp_count_posts unerwartet kein Objekt liefert
                $media_count = (int) count( get_posts( array(
                    'post_type'   => 'attachment',
                    'post_status' => 'any',
                    'numberposts' => -1,
                ) ) );
            }

            // zusätzlicher defensiver Fallback, falls Summierung 0 ergibt (optional)
            if ( $media_count === 0 ) {
                $media_count = (int) count( get_posts( array(
                    'post_type'   => 'attachment',
                    'post_status' => 'any',
                    'numberposts' => -1,
                ) ) );
            }

            $out .= "# HELP wp_media Total number of media items.\n";
            $out .= "# TYPE wp_media counter\n";
            $out .= 'wp_media{wp_site="' . self::escape_label_value( $site_name ) . '"} ' . $media_count . "\n";

            // Tags
            $tag_count = (int) wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
            $out .= "# HELP wp_tags Total number of tags.\n";
            $out .= "# TYPE wp_tags counter\n";
            $out .= 'wp_tags{wp_site="' . self::escape_label_value( $site_name ) . '"} ' . $tag_count . "\n";

            // WordPress Version (safe version)
            $wp_version = get_bloginfo( 'version' );
            $update_available = 0;
            
            // Check for core updates safely
            if ( ! wp_installing() && function_exists( 'get_core_updates' ) ) {
                try {
                    $core_updates = get_core_updates();
                    if ( is_array( $core_updates ) && ! empty( $core_updates ) ) {
                        $latest_update = $core_updates[0];
                        if ( isset( $latest_update->response ) && $latest_update->response === 'upgrade' ) {
                            $update_available = 1;
                        }
                    }
                } catch ( Exception $e ) {
                    // Ignore errors in update check
                }
            }
            
            $out .= "# HELP wp_version WordPress version information.\n";
            $out .= "# TYPE wp_version gauge\n";
            $out .= 'wp_version{wp_site="' . self::escape_label_value( $site_name ) . '",version="' . self::escape_label_value( $wp_version ) . '",update_available="' . $update_available . '"} 1' . "\n";

            // Autoload Options
            global $wpdb;
            
            try {
                // Autoload count
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metrics data, cached at application level via transients
                $autoload_count = (int) $wpdb->get_var( "SELECT count(*) FROM $wpdb->options WHERE `autoload` = 'yes'" );
                $out .= "# HELP wp_autoload_count Number of autoloaded options.\n";
                $out .= "# TYPE wp_autoload_count gauge\n";
                $out .= 'wp_autoload_count{wp_site="' . self::escape_label_value( $site_name ) . '"} ' . $autoload_count . "\n";
                
                // Autoload size in KB
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metrics data, cached at application level via transients
                $autoload_size = (int) $wpdb->get_var( "SELECT ROUND(SUM(LENGTH(option_value))/ 1024) FROM $wpdb->options WHERE `autoload` = 'yes'" );
                $out .= "# HELP wp_autoload_size Size of autoloaded options in KB.\n";
                $out .= "# TYPE wp_autoload_size gauge\n";
                $out .= 'wp_autoload_size{wp_site="' . self::escape_label_value( $site_name ) . '"} ' . $autoload_size . "\n";
                
                // Autoload transient count
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metrics data, cached at application level via transients
                $autoload_transients = (int) $wpdb->get_var( "SELECT count(*) FROM $wpdb->options WHERE `autoload` = 'yes' AND `option_name` LIKE '%transient%'" );
                $out .= "# HELP wp_autoload_transients Number of autoloaded transients.\n";
                $out .= "# TYPE wp_autoload_transients gauge\n";
                $out .= 'wp_autoload_transients{wp_site="' . self::escape_label_value( $site_name ) . '"} ' . $autoload_transients . "\n";
            } catch ( Exception $e ) {
                // Ignore database errors
            }

            // PHP Information
            try {
                $out .= "# HELP wp_php_info PHP configuration information.\n";
                $out .= "# TYPE wp_php_info gauge\n";
                
                // PHP Version - with numeric ID for backwards compatibility
                $out .= 'wp_php_info{wp_site="' . self::escape_label_value( $site_name ) . '",type="version",label="' . self::escape_label_value( PHP_VERSION ) . '"} ' . PHP_VERSION_ID . "\n";
                $out .= 'wp_php_info{wp_site="' . self::escape_label_value( $site_name ) . '",type="major_version",label="' . PHP_MAJOR_VERSION . '"} ' . PHP_MAJOR_VERSION . "\n";
                $out .= 'wp_php_info{wp_site="' . self::escape_label_value( $site_name ) . '",type="minor_version",label="' . PHP_MINOR_VERSION . '"} ' . PHP_MINOR_VERSION . "\n";
                $out .= 'wp_php_info{wp_site="' . self::escape_label_value( $site_name ) . '",type="release_version",label="' . PHP_RELEASE_VERSION . '"} ' . PHP_RELEASE_VERSION . "\n";

                // Add new metric for PHP version string display
                $out .= "# HELP wp_php_version PHP version as readable string.\n";
                $out .= "# TYPE wp_php_version gauge\n";
                $out .= 'wp_php_version{wp_site="' . self::escape_label_value( $site_name ) . '",php_version="' . self::escape_label_value( PHP_VERSION ) . '"} 1' . "\n";

                // Add simple configuration metrics for table display
                $out .= "# HELP wp_config WordPress and PHP configuration values.\n";
                $out .= "# TYPE wp_config gauge\n";
                
                // Add specific metrics for table display
                $out .= "# HELP wp_memory_limit_display Memory limit for table display.\n";
                $out .= "# TYPE wp_memory_limit_display gauge\n";
                
                $out .= "# HELP wp_upload_max_display Upload max filesize for table display.\n";
                $out .= "# TYPE wp_upload_max_display gauge\n";
                
                $out .= "# HELP wp_post_max_display Post max size for table display.\n";
                $out .= "# TYPE wp_post_max_display gauge\n";
                
                $out .= "# HELP wp_exec_time_display Max execution time for table display.\n";
                $out .= "# TYPE wp_exec_time_display gauge\n";

                // PHP Configuration values
                if ( function_exists( 'ini_get' ) ) {
                    $php_configs = array( 'max_input_vars', 'max_execution_time', 'memory_limit', 'max_input_time', 'upload_max_filesize', 'post_max_size' );
                    
                    foreach ( $php_configs as $php_variable ) {
                        $value = ini_get( $php_variable );
                        if ( $value !== false ) {
                            $numeric_value = (float) preg_replace( '/\D/', '', $value );
                            
                            // Convert memory values from different units to bytes for consistency
                            if ( in_array( $php_variable, array( 'memory_limit', 'upload_max_filesize', 'post_max_size' ) ) ) {
                                $numeric_value = self::convert_to_bytes( $value );
                            }
                            
                            // Original detailed metric
                            $out .= 'wp_php_info{wp_site="' . self::escape_label_value( $site_name ) . '",type="' . $php_variable . '",label="' . self::escape_label_value( $value ) . '"} ' . $numeric_value . "\n";
                            
                            // Simple config metric for table display
                            $out .= 'wp_config{wp_site="' . self::escape_label_value( $site_name ) . '",config="' . $php_variable . '",value="' . self::escape_label_value( $value ) . '"} ' . $numeric_value . "\n";
                            
                            // Specific metrics for easy table display
                            switch ( $php_variable ) {
                                case 'memory_limit':
                                    $out .= 'wp_memory_limit_display{wp_site="' . self::escape_label_value( $site_name ) . '",memory_limit="' . self::escape_label_value( $value ) . '"} 1' . "\n";
                                    break;
                                case 'upload_max_filesize':
                                    $out .= 'wp_upload_max_display{wp_site="' . self::escape_label_value( $site_name ) . '",upload_max="' . self::escape_label_value( $value ) . '"} 1' . "\n";
                                    break;
                                case 'post_max_size':
                                    $out .= 'wp_post_max_display{wp_site="' . self::escape_label_value( $site_name ) . '",post_max="' . self::escape_label_value( $value ) . '"} 1' . "\n";
                                    break;
                                case 'max_execution_time':
                                    $out .= 'wp_exec_time_display{wp_site="' . self::escape_label_value( $site_name ) . '",exec_time="' . self::escape_label_value( $value ) . '"} 1' . "\n";
                                    break;
                            }
                        }
                    }
                }
            } catch ( Exception $e ) {
                // Ignore PHP info errors
            }

            // Database Size
            try {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metrics data, cached at application level via transients
                $db_size = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(ROUND(((data_length + index_length) / 1024 / 1024), 2)) as value FROM information_schema.TABLES WHERE table_schema = %s", DB_NAME ) );
                if ( $db_size > 0 ) {
                    $out .= "# HELP wp_database_size Database size in MB.\n";
                    $out .= "# TYPE wp_database_size gauge\n";
                    $out .= 'wp_database_size{wp_site="' . self::escape_label_value( $site_name ) . '"} ' . $db_size . "\n";
                }
            } catch ( Exception $e ) {
                // Ignore database size errors
            }

            // Directory Sizes (simplified and safe)
            $directory_sizes = self::get_directory_sizes();
            if ( ! empty( $directory_sizes ) ) {
                $out .= "# HELP wp_directory_size Directory sizes in MB.\n";
                $out .= "# TYPE wp_directory_size gauge\n";
                
                foreach ( $directory_sizes as $dir_type => $size_mb ) {
                    $out .= 'wp_directory_size{wp_site="' . self::escape_label_value( $site_name ) . '",directory="' . $dir_type . '"} ' . $size_mb . "\n";
                }
            }

            // Site Health Check Results (safe implementation)
            $health_check = self::get_health_check_results();
            if ( ! empty( $health_check ) ) {
                $out .= "# HELP wp_health_check Site health check results.\n";
                $out .= "# TYPE wp_health_check gauge\n";
                
                foreach ( $health_check as $category => $count ) {
                    $out .= 'wp_health_check{wp_site="' . self::escape_label_value( $site_name ) . '",category="' . $category . '"} ' . $count . "\n";
                }
            }

            // Site Health Check Details (individual test results)
            $health_details = self::get_health_check_details();
            if ( ! empty( $health_details ) ) {
                $out .= "# HELP wp_health_check_detail Individual health check test results.\n";
                $out .= "# TYPE wp_health_check_detail gauge\n";
                
                foreach ( $health_details as $test_detail ) {
                    $status_value = 0;
                    switch ( $test_detail['status'] ) {
                        case 'good':
                            $status_value = 1;
                            break;
                        case 'recommended':
                            $status_value = 0;
                            break;
                        case 'critical':
                            $status_value = -1;
                            break;
                    }
                    
                    $out .= 'wp_health_check_detail{wp_site="' . self::escape_label_value( $site_name ) . '",test_name="' . self::escape_label_value( $test_detail['test'] ) . '",status="' . self::escape_label_value( $test_detail['status'] ) . '",category="' . self::escape_label_value( $test_detail['category'] ) . '",description="' . self::escape_label_value( $test_detail['description'] ) . '"} ' . $status_value . "\n";
                }
            }

            return $out;
        }

        /**
         * Add admin menu for Prometheus settings.
         */
        public static function add_admin_menu() {
            add_options_page(
                'Prometheus Metrics Settings',
                'Prometheus Metrics',
                'manage_options',
                'wp-prometheus-metrics',
                array( __CLASS__, 'admin_page' )
            );
        }

        /**
         * Add settings link to plugin actions on plugins page.
         *
         * @param array $links Existing plugin action links.
         * @return array Modified plugin action links.
         */
        public static function add_plugin_action_links( $links ) {
            $settings_link = sprintf(
                '<a href="%s">%s</a>',
                admin_url( 'options-general.php?page=wp-prometheus-metrics' ),
                __( 'Settings', 'prometheus-metrics' )
            );
            
            // Add the settings link at the beginning of the array
            array_unshift( $links, $settings_link );
            
            return $links;
        }

        /**
         * Initialize admin settings.
         */
        public static function admin_init() {
            // Handle form submissions
            if ( isset( $_POST['wp_prometheus_action'] ) && check_admin_referer( 'wp_prometheus_settings' ) ) {
                if ( $_POST['wp_prometheus_action'] === 'regenerate_tokens' ) {
                    // Regenerate API key (always allowed)
                    self::set_encrypted_option( 'wp_prometheus_api_key', self::generate_secure_token() );
                    
                    // Only regenerate Bearer token if not using environment key
                    if ( ! self::is_encryption_key_from_env() ) {
                        self::set_encrypted_option( 'wp_prometheus_auth_token', self::generate_secure_token() );
                        add_settings_error( 'wp_prometheus_messages', 'tokens_regenerated', 'Bearer Token and API Key successfully regenerated!', 'updated' );
                    } else {
                        add_settings_error( 'wp_prometheus_messages', 'api_key_regenerated', 'API Key successfully regenerated! Bearer Token is managed via environment variable.', 'updated' );
                    }
                }
            }
        }

        /**
         * Render admin page.
         */
        public static function admin_page() {
            $auth_settings = self::get_auth_settings();
            $endpoint_url = home_url( '/wp-json/wp-prometheus/v1/metrics' );
            $is_env_key = self::is_encryption_key_from_env();
            
            ?>
            <div class="wrap">
                <h1>Prometheus Metrics Settings</h1>
                
                <?php settings_errors( 'wp_prometheus_messages' ); ?>
                
                <div class="card">
                    <h2>Endpoint Information</h2>
                    <p><strong>Primary Metrics Endpoint (Clean URL):</strong> <code><?php echo esc_url( home_url( '/prometheus/metrics' ) ); ?></code></p>
                    <p><em>Recommended endpoint with clean URL structure. Works out-of-the-box with WordPress permalinks enabled.</em></p>
                    
                    <h3>Alternative Endpoints (Fallback)</h3>
                    <p>If the primary endpoint is not working, use these alternative URLs:</p>
                    <ul>
                        <li><strong>REST API Endpoint:</strong> <code><?php echo esc_url( $endpoint_url ); ?></code></li>
                        <li><strong>REST API Fallback:</strong> <code><?php echo esc_url( home_url( '/index.php?rest_route=/wp-prometheus/v1/metrics' ) ); ?></code> <em>(always works)</em></li>
                        <li><strong>Query Parameter:</strong> <code><?php echo esc_url( home_url( '/?wp_prometheus_metrics=1' ) ); ?></code> <em>(always works)</em></li>
                        <li><strong>Short Path:</strong> <code><?php echo esc_url( home_url( '/metrics' ) ); ?></code> <em>(alternative clean URL)</em></li>
                    </ul>
                    
                    <p><em>All endpoints are protected by authentication and return the same metrics data in Prometheus format.</em></p>
                    <?php if ( $is_env_key ): ?>
                        <p><strong>🔐 Security:</strong> <em>Encryption key is loaded from environment variable <code>WP_PROMETHEUS_ENCRYPTION_KEY</code> for enhanced security.</em></p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2>Authentication Tokens</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Bearer Token</th>
                            <td>
                                <div class="token-field">
                                    <input type="text" class="regular-text" id="bearer-token" value="<?php echo esc_attr( $auth_settings['bearer_token'] ); ?>" readonly onclick="this.select();" />
                                    <button type="button" class="button copy-btn" onclick="copyToClipboard('bearer-token')">Copy</button>
                                </div>
                                <p class="description">For Authorization header: <code>Authorization: Bearer TOKEN</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API Key</th>
                            <td>
                                <div class="token-field">
                                    <input type="text" class="regular-text" id="api-key" value="<?php echo esc_attr( $auth_settings['api_key'] ); ?>" readonly onclick="this.select();" />
                                    <button type="button" class="button copy-btn" onclick="copyToClipboard('api-key')">Copy</button>
                                </div>
                                <p class="description">As URL parameter: <code>?api_key=KEY</code></p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php if ( $is_env_key ): ?>
                        <div class="notice notice-info inline">
                            <p><strong>Bearer Token from Environment:</strong> The Bearer Token is managed via environment variable and cannot be regenerated through the web interface. API Key can still be regenerated below.</p>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <?php wp_nonce_field( 'wp_prometheus_settings' ); ?>
                        <input type="hidden" name="wp_prometheus_action" value="regenerate_tokens" />
                        <p class="submit">
                            <?php if ( $is_env_key ): ?>
                                <input type="submit" class="button button-secondary" value="Regenerate API Key" onclick="return confirm('Are you sure? Existing API Key configurations will need to be updated.');" />
                            <?php else: ?>
                                <input type="submit" class="button button-secondary" value="Regenerate Tokens" onclick="return confirm('Are you sure? Existing configurations will need to be updated.');" />
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div class="card">
                    <h2>Authentication Methods</h2>
                    <ul>
                        <li><strong>WordPress Admin:</strong> Logged-in administrators have automatic access</li>
                        <li><strong>Bearer Token:</strong> Recommended for Prometheus and other monitoring tools (encrypted storage)</li>
                        <li><strong>API Key:</strong> Simple for testing and URL-based access (encrypted storage)</li>
                    </ul>
                    
                    <h3>Security Configuration</h3>
                    <ul>
                        <?php if ( $is_env_key ): ?>
                            <li><strong>🔐 Environment Key:</strong> Using encryption key from <code>WP_PROMETHEUS_ENCRYPTION_KEY</code> environment variable</li>
                            <li><strong>Bearer Token:</strong> Can be provided via <code>WP_PROMETHEUS_BEARER_TOKEN</code> environment variable</li>
                            <li><strong>Enhanced Security:</strong> Environment variables are not stored in database and cannot be accessed via web interface</li>
                        <?php else: ?>
                            <li><strong>Database Key:</strong> Encryption key is auto-generated and stored in WordPress database</li>
                            <li><strong>Enhanced Security Option:</strong> Set <code>WP_PROMETHEUS_ENCRYPTION_KEY</code> and <code>WP_PROMETHEUS_BEARER_TOKEN</code> environment variables for better security</li>
                        <?php endif; ?>
                    </ul>
                    
                    <h3>Environment Variable Setup</h3>
                    <p>For production environments, use environment variables for enhanced security:</p>
                    
                    <h4>Docker</h4>
                    <div class="code-block">
                        <code id="docker-example">docker run -e WP_PROMETHEUS_ENCRYPTION_KEY="$(openssl rand -base64 32)" -e WP_PROMETHEUS_BEARER_TOKEN="$(openssl rand -hex 32)" your-wordpress-image</code>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('docker-example')">Copy</button>
                    </div>
                    
                    <h4>Kubernetes</h4>
                    <div class="code-block">
                        <pre id="k8s-example">env:
  - name: WP_PROMETHEUS_ENCRYPTION_KEY
    valueFrom:
      secretKeyRef:
        name: wordpress-secrets
        key: prometheus-encryption-key
  - name: WP_PROMETHEUS_BEARER_TOKEN
    valueFrom:
      secretKeyRef:
        name: wordpress-secrets
        key: prometheus-bearer-token</pre>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('k8s-example')">Copy</button>
                    </div>
                    
                    <p><strong>Environment Variables:</strong></p>
                    <ul>
                        <li><code>WP_PROMETHEUS_ENCRYPTION_KEY</code> - Base64 encoded encryption key for API keys</li>
                        <li><code>WP_PROMETHEUS_BEARER_TOKEN</code> - Bearer token for Prometheus authentication (plain text)</li>
                    </ul>
                    
                    <p>📖 <strong>More Examples:</strong> <a href="https://github.com/slydlake/wordpress-prometheus-metrics#security-features" target="_blank">See GitHub Documentation</a></p>
                </div>

                <div class="card">
                    <h2>Usage Examples</h2>
                    
                    <h3>Primary Endpoint (Clean URL)</h3>
                    <h4>cURL with Bearer Token</h4>
                    <div class="code-block">
                        <code id="curl-primary">curl -H "Authorization: Bearer <?php echo esc_attr( $auth_settings['bearer_token'] ); ?>" "<?php echo esc_url( home_url( '/prometheus/metrics' ) ); ?>"</code>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('curl-primary')">Copy</button>
                    </div>
                    
                    <h4>cURL with API Key</h4>
                    <div class="code-block">
                        <code id="curl-apikey-primary">curl "<?php echo esc_url( home_url( '/prometheus/metrics' ) ); ?>?api_key=<?php echo esc_attr( $auth_settings['api_key'] ); ?>"</code>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('curl-apikey-primary')">Copy</button>
                    </div>
                    
                    <h3>Fallback Endpoints</h3>
                    <h4>REST API Endpoint</h4>
                    <div class="code-block">
                        <code id="curl-rest">curl -H "Authorization: Bearer <?php echo esc_attr( $auth_settings['bearer_token'] ); ?>" "<?php echo esc_url( $endpoint_url ); ?>"</code>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('curl-rest')">Copy</button>
                    </div>
                    
                    <h4>Universal Fallback (Always Works)</h4>
                    <div class="code-block">
                        <code id="curl-fallback">curl -H "Authorization: Bearer <?php echo esc_attr( $auth_settings['bearer_token'] ); ?>" "<?php echo esc_url( home_url( '/?wp_prometheus_metrics=1' ) ); ?>"</code>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('curl-fallback')">Copy</button>
                    </div>
                    
                    <h4>Alternative Clean URL</h4>
                    <div class="code-block">
                        <code id="curl-simple">curl -H "Authorization: Bearer <?php echo esc_attr( $auth_settings['bearer_token'] ); ?>" "<?php echo esc_url( home_url( '/metrics' ) ); ?>"</code>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('curl-simple')">Copy</button>
                    </div>
                    
                    <h3>Prometheus Configuration</h3>
                    <h4>Primary Configuration</h4>
                    <div class="code-block">
                        <pre id="prometheus-config"># prometheus.yml
scrape_configs:
  - job_name: 'wordpress'
    static_configs:
      - targets: ['<?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>']
    metrics_path: '/prometheus/metrics'
    authorization:
      type: Bearer
      credentials: '<?php echo esc_attr( $auth_settings['bearer_token'] ); ?>'</pre>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('prometheus-config')">Copy</button>
                    </div>
                    
                    <h4>REST API Fallback Configuration</h4>
                    <div class="code-block">
                        <pre id="prometheus-fallback"># prometheus.yml (REST API fallback)
scrape_configs:
  - job_name: 'wordpress'
    static_configs:
      - targets: ['<?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>']
    metrics_path: '/wp-json/wp-prometheus/v1/metrics'
    authorization:
      type: Bearer
      credentials: '<?php echo esc_attr( $auth_settings['bearer_token'] ); ?>'</pre>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('prometheus-fallback')">Copy</button>
                    </div>
                    
                    <h4>Universal Fallback Configuration (Always Works)</h4>
                    <div class="code-block">
                        <pre id="prometheus-alt"># prometheus.yml (universal fallback)
scrape_configs:
  - job_name: 'wordpress'
    static_configs:
      - targets: ['<?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>']
    metrics_path: '/'
    params:
      wp_prometheus_metrics: ['1']
    authorization:
      type: Bearer
      credentials: '<?php echo esc_attr( $auth_settings['bearer_token'] ); ?>'</pre>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('prometheus-alt')">Copy</button>
                    </div>
                </div>
            </div>

            <script>
                function copyToClipboard(elementId) {
                    const element = document.getElementById(elementId);
                    let text = '';
                    
                    // Handle different element types
                    if (element.tagName === 'PRE') {
                        text = element.textContent || element.innerText;
                    } else if (element.tagName === 'CODE') {
                        text = element.textContent || element.innerText;
                    } else if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                        text = element.value;
                    } else {
                        text = element.textContent || element.innerText || element.value;
                    }
                    
                    // Clean up the text
                    text = text.trim();
                    
                    // Try modern clipboard API first
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function() {
                            showCopyFeedback(element);
                        }).catch(function(err) {
                            // Fallback to execCommand
                            fallbackCopy(text, element);
                        });
                    } else {
                        // Fallback for older browsers
                        fallbackCopy(text, element);
                    }
                }
                
                function fallbackCopy(text, element) {
                    // Create a temporary textarea for copying
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.focus();
                    textarea.select();
                    
                    try {
                        document.execCommand('copy');
                        showCopyFeedback(element);
                    } catch (err) {
                        console.error('Copy failed:', err);
                    }
                    
                    document.body.removeChild(textarea);
                }
                
                function showCopyFeedback(element) {
                    // Find the copy button in the same container
                    const button = element.parentNode.querySelector('.copy-btn') || 
                                  element.parentElement.querySelector('.copy-btn') ||
                                  element.nextElementSibling;
                    
                    if (button) {
                        const originalText = button.textContent;
                        button.textContent = 'Copied!';
                        button.style.backgroundColor = '#00a32a';
                        button.style.color = 'white';
                        
                        setTimeout(function() {
                            button.textContent = originalText;
                            button.style.backgroundColor = '';
                            button.style.color = '';
                        }, 2000);
                    }
                }
            </script>

            <style>
                .wrap {
                    max-width: none !important;
                    margin-right: 20px;
                }
                .card { 
                    background: #fff; 
                    border: 1px solid #ccd0d4; 
                    padding: 20px; 
                    margin: 20px 0; 
                    width: calc(100% - 40px) !important;
                    max-width: none !important;
                    box-sizing: border-box;
                }
                .card h2 { 
                    margin-top: 0; 
                    padding-bottom: 10px;
                    border-bottom: 1px solid #eee;
                }
                .card h3 { 
                    color: #23282d; 
                    margin-top: 20px; 
                    margin-bottom: 10px;
                }
                .card h4 { 
                    color: #23282d; 
                    margin-top: 15px; 
                    margin-bottom: 8px;
                    font-size: 14px;
                }
                .card code { 
                    background: #f1f1f1; 
                    padding: 8px 12px; 
                    border-radius: 4px; 
                    display: inline-block; 
                    margin: 8px 0; 
                    word-break: break-all;
                    font-size: 13px;
                    max-width: 100%;
                }
                .card pre { 
                    background: #f1f1f1; 
                    padding: 15px; 
                    border-radius: 4px; 
                    overflow-x: auto; 
                    margin: 10px 0;
                    width: 100%;
                    box-sizing: border-box;
                }
                .card pre code {
                    background: none;
                    padding: 0;
                    margin: 0;
                    display: block;
                    width: 100%;
                }
                .card input[readonly] { 
                    background: #f9f9f9; 
                    width: 100%;
                    max-width: 600px;
                    box-sizing: border-box;
                }
                .card input[readonly]:focus { 
                    background: #fff; 
                }
                .card .form-table {
                    width: 100%;
                    max-width: none;
                }
                .card .form-table th {
                    width: 150px;
                    padding: 15px 10px 15px 0;
                }
                .card .form-table td {
                    padding: 15px 0;
                    width: auto;
                }
                .card ul {
                    margin: 15px 0;
                    padding-left: 25px;
                }
                .card ul li {
                    margin-bottom: 8px;
                    line-height: 1.5;
                }
                .card .regular-text {
                    width: 100% !important;
                    max-width: 600px;
                }
                .notice.inline {
                    margin: 15px 0;
                    padding: 12px;
                }
                .notice.inline p {
                    margin: 0;
                }
                .token-field {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                .token-field input {
                    flex: 1;
                    min-width: 300px;
                }
                .copy-btn {
                    margin-left: 10px !important;
                    white-space: nowrap;
                    transition: all 0.3s ease;
                }
                .copy-btn:hover {
                    background-color: #0073aa !important;
                    color: white !important;
                }
                .code-block {
                    position: relative;
                    margin: 10px 0;
                    width: 100%;
                    overflow: visible;
                }
                .code-block .copy-btn {
                    position: absolute;
                    top: 8px;
                    right: 8px;
                    margin: 0;
                    padding: 4px 8px;
                    font-size: 11px;
                    line-height: 1.4;
                    z-index: 10;
                    min-width: 50px;
                    text-align: center;
                }
                .code-block code,
                .code-block pre {
                    padding-right: 70px;
                    margin: 0;
                    width: 100%;
                    box-sizing: border-box;
                    overflow-x: auto;
                }
            </style>
            <?php
        }

        /**
         * Activation hook: flush rewrite rules so REST routes are available.
         */
        public static function on_activate() {
            // Add our rewrite rules first
            self::add_rewrite_rules();
            // Then flush to make them active
            flush_rewrite_rules();
            // Generate default auth tokens if they don't exist
            self::ensure_auth_tokens();
        }

        /**
         * Deactivation hook: flush rewrite rules to cleanup.
         */
        public static function on_deactivate() {
            // Clean up transients
            delete_transient( self::CACHE_KEY );
            // Flush rewrite rules to remove our custom rules
            flush_rewrite_rules();
        }

        /**
         * Ensure authentication tokens exist.
         */
        private static function ensure_auth_tokens() {
            if ( ! self::get_decrypted_option( 'wp_prometheus_auth_token' ) ) {
                self::set_encrypted_option( 'wp_prometheus_auth_token', self::generate_secure_token() );
            }
            if ( ! self::get_decrypted_option( 'wp_prometheus_api_key' ) ) {
                self::set_encrypted_option( 'wp_prometheus_api_key', self::generate_secure_token() );
            }
        }

        /**
         * Generate a secure random token.
         *
         * @return string
         */
        private static function generate_secure_token() {
            return bin2hex( random_bytes( 32 ) ); // 64 character hex string
        }

        /**
         * Get encryption key for token storage.
         *
         * @return string
         */
        private static function get_encryption_key() {
            // First check environment variable
            $env_key = getenv( 'WP_PROMETHEUS_ENCRYPTION_KEY' );
            if ( $env_key !== false && ! empty( $env_key ) ) {
                return base64_decode( $env_key );
            }
            
            // Fallback to database storage
            $key = get_option( 'wp_prometheus_encryption_key' );
            if ( ! $key ) {
                // Generate a new encryption key
                $key = base64_encode( random_bytes( 32 ) );
                update_option( 'wp_prometheus_encryption_key', $key );
            }
            return base64_decode( $key );
        }

        /**
         * Check if encryption key is from environment variable.
         *
         * @return bool
         */
        private static function is_encryption_key_from_env() {
            $env_key = getenv( 'WP_PROMETHEUS_ENCRYPTION_KEY' );
            return $env_key !== false && ! empty( $env_key );
        }

        /**
         * Encrypt data for storage.
         *
         * @param string $data
         * @return string
         */
        private static function encrypt_data( $data ) {
            if ( ! function_exists( 'openssl_encrypt' ) ) {
                // Fallback: no encryption if OpenSSL not available
                return base64_encode( $data );
            }

            $key = self::get_encryption_key();
            $iv = random_bytes( 16 );
            $encrypted = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );
            
            // Combine IV and encrypted data
            return base64_encode( $iv . $encrypted );
        }

        /**
         * Decrypt data from storage.
         *
         * @param string $encrypted_data
         * @return string|false
         */
        private static function decrypt_data( $encrypted_data ) {
            if ( ! function_exists( 'openssl_decrypt' ) ) {
                // Fallback: simple base64 decode if OpenSSL not available
                return base64_decode( $encrypted_data );
            }

            $data = base64_decode( $encrypted_data );
            if ( strlen( $data ) < 16 ) {
                return false;
            }

            $key = self::get_encryption_key();
            $iv = substr( $data, 0, 16 );
            $encrypted = substr( $data, 16 );
            
            return openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
        }

        /**
         * Set encrypted option in database.
         *
         * @param string $option_name
         * @param string $value
         */
        private static function set_encrypted_option( $option_name, $value ) {
            $encrypted_value = self::encrypt_data( $value );
            update_option( $option_name, $encrypted_value );
        }

        /**
         * Get decrypted option from database.
         *
         * @param string $option_name
         * @return string
         */
        private static function get_decrypted_option( $option_name ) {
            $encrypted_value = get_option( $option_name, '' );
            if ( empty( $encrypted_value ) ) {
                return '';
            }
            
            $decrypted = self::decrypt_data( $encrypted_value );
            return $decrypted !== false ? $decrypted : '';
        }

        /**
         * Get authentication token (Bearer token or API key).
         * If using environment variable, return directly without decryption.
         *
         * @param string $option_name
         * @return string
         */
        private static function get_auth_token( $option_name ) {
            // If using environment encryption key, check for environment variables first
            if ( self::is_encryption_key_from_env() ) {
                if ( $option_name === 'wp_prometheus_auth_token' ) {
                    $env_token = getenv( 'WP_PROMETHEUS_BEARER_TOKEN' );
                    if ( $env_token !== false && ! empty( $env_token ) ) {
                        return $env_token;
                    }
                }
                // API Key is still stored encrypted in database even with env key
            }
            
            // Fallback to database (encrypted)
            return self::get_decrypted_option( $option_name );
        }

        /**
         * Get current authentication settings for admin display.
         *
         * @return array
         */
        public static function get_auth_settings() {
            return array(
                'bearer_token' => self::get_auth_token( 'wp_prometheus_auth_token' ),
                'api_key'      => self::get_auth_token( 'wp_prometheus_api_key' ),
            );
        }

        /**
         * Convert memory size string to bytes.
         *
         * @param string $size Size string like "128M", "1G", "512K"
         * @return float Size in bytes
         */
        private static function convert_to_bytes( $size ) {
            $size = trim( $size );
            $unit = strtoupper( substr( $size, -1 ) );
            $value = (float) substr( $size, 0, -1 );
            
            switch ( $unit ) {
                case 'G':
                    return $value * 1024 * 1024 * 1024;
                case 'M':
                    return $value * 1024 * 1024;
                case 'K':
                    return $value * 1024;
                default:
                    return (float) $size;
            }
        }

        /**
         * Get directory sizes for WordPress installation.
         *
         * @return array Directory sizes in MB
         */
        private static function get_directory_sizes() {
            $sizes = array();
            
            try {
                // Uploads directory
                $upload_dir = wp_upload_dir();
                $uploads_size = 0;
                if ( isset( $upload_dir['basedir'] ) && is_dir( $upload_dir['basedir'] ) ) {
                    $uploads_size = self::get_directory_size_safe( $upload_dir['basedir'] );
                }
                
                // Themes directory
                $themes_size = 0;
                if ( is_dir( get_theme_root() ) ) {
                    $themes_size = self::get_directory_size_safe( get_theme_root() );
                }
                
                // Plugins directory
                $plugins_size = 0;
                if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) ) {
                    $plugins_size = self::get_directory_size_safe( WP_PLUGIN_DIR );
                }
                
                return array(
                    'uploads'   => round( $uploads_size / 1024 / 1024, 2 ),
                    'themes'    => round( $themes_size / 1024 / 1024, 2 ),
                    'plugins'   => round( $plugins_size / 1024 / 1024, 2 ),
                    'total'     => round( ( $uploads_size + $themes_size + $plugins_size ) / 1024 / 1024, 2 )
                );
            } catch ( Exception $e ) {
                return array();
            }
        }

        /**
         * Get size of a directory in bytes (safe version).
         *
         * @param string $directory Path to directory
         * @return int Size in bytes
         */
        private static function get_directory_size_safe( $directory ) {
            if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
                return 0;
            }
            
            $size = 0;
            
            try {
                // Use exec with du if available (Unix systems)
                if ( function_exists( 'exec' ) && ! defined( 'DISABLE_WP_CRON' ) ) {
                    $output = array();
                    $return_var = 0;
                    exec( 'du -sb ' . escapeshellarg( $directory ) . ' 2>/dev/null', $output, $return_var );
                    
                    if ( $return_var === 0 && ! empty( $output[0] ) ) {
                        $parts = explode( "\t", $output[0] );
                        if ( is_numeric( $parts[0] ) ) {
                            return (int) $parts[0];
                        }
                    }
                }
                
                // Fallback: manual calculation with limits
                $files = glob( $directory . '/*', GLOB_MARK );
                if ( is_array( $files ) && count( $files ) < 1000 ) { // Limit to avoid memory issues
                    foreach ( $files as $file ) {
                        if ( is_file( $file ) ) {
                            $size += filesize( $file );
                        }
                    }
                }
                
            } catch ( Exception $e ) {
                return 0;
            }
            
            return $size;
        }

        /**
         * Get Site Health check results using WordPress Site Health API.
         *
         * @return array Health check results by category
         */
        private static function get_health_check_results() {
            try {
                // Initialize results
                $results = array(
                    'critical'    => 0,
                    'recommended' => 0,
                    'good'        => 0,
                    'security'    => 0,
                    'performance' => 0,
                    'total_failed' => 0
                );
                
                // ALWAYS use the same data source as details
                $details = self::get_health_check_details();
                
                foreach ( $details as $detail ) {
                    $status = $detail['status'];
                    $category = $detail['category'];
                    
                    // Count by status
                    if ( $status === 'critical' ) {
                        $results['critical']++;
                    } elseif ( $status === 'recommended' ) {
                        $results['recommended']++;
                    } elseif ( $status === 'good' ) {
                        $results['good']++;
                    }
                    
                    // Count by category (only for non-good status)
                    if ( $status !== 'good' ) {
                        if ( $category === 'security' ) {
                            $results['security']++;
                        } elseif ( $category === 'performance' ) {
                            $results['performance']++;
                        }
                    }
                }
                
                // Total failed is critical + recommended
                $results['total_failed'] = $results['critical'] + $results['recommended'];
                
                return $results;
                
            } catch ( Exception $e ) {
                return self::get_enhanced_health_checks();
            }
        }
        
        /**
         * Get WordPress health tests using the actual WordPress Site Health system.
         *
         * @return array Health test results
         */
        private static function get_wordpress_health_tests() {
            try {
                // Load required WordPress files for Site Health
                if ( ! class_exists( 'WP_Site_Health' ) ) {
                    // Load all necessary files
                    $files_to_load = array(
                        ABSPATH . 'wp-admin/includes/class-wp-site-health.php',
                        ABSPATH . 'wp-admin/includes/class-wp-site-health-auto-updates.php',
                        ABSPATH . 'wp-admin/includes/update.php',
                        ABSPATH . 'wp-admin/includes/misc.php',
                        ABSPATH . 'wp-admin/includes/plugin.php',
                        ABSPATH . 'wp-admin/includes/theme.php'
                    );
                    
                    foreach ( $files_to_load as $file ) {
                        if ( file_exists( $file ) ) {
                            require_once $file;
                        }
                    }
                }
                
                $tests = array();
                
                // Use WordPress Site Health if available
                if ( class_exists( 'WP_Site_Health' ) ) {
                    $site_health = new WP_Site_Health();
                    
                    if ( method_exists( $site_health, 'get_tests' ) ) {
                        $all_tests = $site_health->get_tests();
                        
                        // Execute direct tests (synchronous)
                        if ( isset( $all_tests['direct'] ) && is_array( $all_tests['direct'] ) ) {
                            foreach ( $all_tests['direct'] as $test_name => $test_config ) {
                                if ( isset( $test_config['test'] ) && is_callable( $test_config['test'] ) ) {
                                    try {
                                        // Skip potentially problematic tests
                                        if ( strpos( $test_name, 'async' ) !== false ||
                                             strpos( $test_name, 'loopback' ) !== false ) {
                                            continue;
                                        }
                                        
                                        $test_result = call_user_func( $test_config['test'] );
                                        if ( is_array( $test_result ) && isset( $test_result['status'] ) ) {
                                            // Normalize WordPress status names to our standard
                                            $status = $test_result['status'];
                                            if ( $status === 'good' || $status === 'info' ) {
                                                $status = 'good';
                                            } elseif ( $status === 'recommended' ) {
                                                $status = 'recommended';
                                            } elseif ( $status === 'critical' ) {
                                                $status = 'critical';
                                            }
                                            
                                            $tests[ $test_name ] = array(
                                                'status' => $status,
                                                'label' => isset( $test_result['label'] ) ? $test_result['label'] : $test_name,
                                                'description' => isset( $test_result['description'] ) ? $test_result['description'] : '',
                                                'actions' => isset( $test_result['actions'] ) ? $test_result['actions'] : '',
                                                'badge' => isset( $test_result['badge'] ) ? $test_result['badge'] : array(),
                                            );
                                        }
                                    } catch ( Exception $e ) {
                                        // Skip failed tests
                                        continue;
                                    }
                                }
                            }
                        }
                        
                        // Note: We skip async tests for performance reasons in metrics collection
                    }
                }
                
                return $tests;
                
            } catch ( Exception $e ) {
                return array();
            }
        }
        
        /**
         * Enhanced health checks with more comprehensive WordPress-style checks.
         * This function counts based on the same logic as get_enhanced_health_check_details().
         *
         * @return array Enhanced health check results
         */
        private static function get_enhanced_health_checks() {
            // Get detailed checks and count them
            $details = self::get_enhanced_health_check_details();
            
            $results = array(
                'critical'    => 0,
                'recommended' => 0,
                'good'        => 0,
                'security'    => 0,
                'performance' => 0,
                'total_failed' => 0
            );
            
            foreach ( $details as $detail ) {
                $status = $detail['status'];
                $category = $detail['category'];
                
                // Count by status
                if ( $status === 'critical' ) {
                    $results['critical']++;
                } elseif ( $status === 'recommended' ) {
                    $results['recommended']++;
                } elseif ( $status === 'good' ) {
                    $results['good']++;
                }
                
                // Count by category (regardless of status)
                if ( $category === 'security' ) {
                    $results['security']++;
                } elseif ( $category === 'performance' ) {
                    $results['performance']++;
                }
            }
            
            // Total failed is critical + recommended
            $results['total_failed'] = $results['critical'] + $results['recommended'];
            
            return $results;
        }

        /**
         * Handle alternative URL patterns for environments without REST API rewrites.
         * This provides multiple ways to access the metrics endpoint.
         */
        public static function handle_alternative_urls() {
            // Check for direct metrics request via query parameter
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public metrics endpoint with custom authentication
            if ( isset( $_GET['wp_prometheus_metrics'] ) ) {
                // Create a fake REST request object
                $request = new WP_REST_Request( 'GET', '/wp-prometheus/v1/metrics' );
                
                // Copy query parameters
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public metrics endpoint with custom authentication
                foreach ( $_GET as $key => $value ) {
                    if ( $key !== 'wp_prometheus_metrics' ) {
                        $request->set_param( $key, $value );
                    }
                }
                
                // Copy authorization header if available
                $auth_header = null;
                if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
                    $auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
                } elseif ( function_exists( 'getallheaders' ) ) {
                    $headers = getallheaders();
                    if ( isset( $headers['Authorization'] ) ) {
                        $auth_header = $headers['Authorization'];
                    }
                }
                
                if ( $auth_header ) {
                    $request->set_header( 'authorization', $auth_header );
                }
                
                // Check authentication
                $auth_result = self::check_auth( $request );
                if ( is_wp_error( $auth_result ) ) {
                    http_response_code( 401 );
                    header( 'Content-Type: application/json' );
                    echo wp_json_encode( array(
                        'code'    => $auth_result->get_error_code(),
                        'message' => $auth_result->get_error_message(),
                        'data'    => $auth_result->get_error_data()
                    ) );
                    exit;
                }
                
                // Generate and serve metrics
                $metrics = self::build_metrics();
                header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw metrics output for Prometheus format
                echo $metrics;
                exit;
            }
        }
        
        /**
         * Handle request filter for custom URL patterns.
         * This hook allows us to intercept WordPress query processing.
         *
         * @param array $query_vars Current query variables
         * @return array Modified query variables
         */
        public static function handle_request_filter( $query_vars ) {
            // Check if this is a request to our metrics endpoint via different URL patterns
            global $wp;
            
            // Pattern 1: /metrics (simple)
            if ( isset( $wp->request ) && $wp->request === 'metrics' ) {
                $query_vars['wp_prometheus_metrics'] = '1';
            }
            
            // Pattern 2: /prometheus/metrics
            if ( isset( $wp->request ) && $wp->request === 'prometheus/metrics' ) {
                $query_vars['wp_prometheus_metrics'] = '1';
            }
            
            return $query_vars;
        }

        /**
         * Add custom rewrite rules for prometheus endpoints.
         * This provides clean URLs like /prometheus/metrics and /metrics
         */
        public static function add_rewrite_rules() {
            // Add rewrite rule for /prometheus/metrics (with and without trailing slash)
            add_rewrite_rule(
                '^prometheus/metrics/?$',
                'index.php?wp_prometheus_endpoint=metrics',
                'top'
            );
            
            // Add rewrite rule for /metrics (with and without trailing slash)
            add_rewrite_rule(
                '^metrics/?$',
                'index.php?wp_prometheus_endpoint=metrics',
                'top'
            );
            
            // Prevent WordPress from doing canonical redirects for our endpoints
            add_filter( 'redirect_canonical', array( __CLASS__, 'disable_canonical_redirect_for_metrics' ), 10, 2 );
        }

        /**
         * Check if rewrite rules need to be flushed and do so if necessary.
         * This ensures our custom endpoints work even if rewrite rules were cleared.
         */
        public static function maybe_flush_rewrite_rules() {
            // Get current rewrite rules
            $rules = get_option( 'rewrite_rules' );
            
            // Check if our rules exist
            $has_prometheus_rule = false;
            $has_metrics_rule = false;
            
            if ( is_array( $rules ) ) {
                foreach ( $rules as $pattern => $rewrite ) {
                    if ( strpos( $pattern, 'prometheus/metrics' ) !== false ) {
                        $has_prometheus_rule = true;
                    }
                    if ( strpos( $pattern, '^metrics' ) !== false && strpos( $rewrite, 'wp_prometheus_endpoint' ) !== false ) {
                        $has_metrics_rule = true;
                    }
                }
            }
            
            // If rules are missing, flush to regenerate them
            if ( ! $has_prometheus_rule || ! $has_metrics_rule ) {
                flush_rewrite_rules();
            }
        }

        /**
         * Disable canonical redirects for our metrics endpoints.
         * This prevents WordPress from redirecting /prometheus/metrics to /prometheus/metrics/
         *
         * @param string $redirect_url The redirect URL.
         * @param string $requested_url The requested URL.
         * @return string|false The redirect URL or false to disable redirect.
         */
        public static function disable_canonical_redirect_for_metrics( $redirect_url, $requested_url ) {
            // Parse the requested URL
            $path = wp_parse_url( $requested_url, PHP_URL_PATH );
            
            // If this is a request to our metrics endpoints, disable canonical redirect
            if ( $path === '/prometheus/metrics' || $path === '/metrics' ) {
                return false;
            }
            
            return $redirect_url;
        }

        /**
         * Add custom query variables for prometheus endpoints.
         *
         * @param array $vars Current query variables
         * @return array Modified query variables
         */
        public static function add_query_vars( $vars ) {
            $vars[] = 'wp_prometheus_endpoint';
            return $vars;
        }

        /**
         * Handle prometheus endpoint requests using template_redirect.
         * This is called after query parsing but before template loading.
         */
        public static function handle_prometheus_request() {
            $endpoint = get_query_var( 'wp_prometheus_endpoint' );
            
            if ( $endpoint === 'metrics' ) {
                // Create a fake REST request object for authentication
                $request = new WP_REST_Request( 'GET', '/wp-prometheus/v1/metrics' );
                
                // Copy query parameters
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public metrics endpoint with custom authentication  
                foreach ( $_GET as $key => $value ) {
                    if ( $key !== 'wp_prometheus_endpoint' ) {
                        $request->set_param( $key, sanitize_text_field( wp_unslash( $value ) ) );
                    }
                }
                
                // Copy authorization header if available
                $auth_header = null;
                if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
                    $auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
                } elseif ( function_exists( 'getallheaders' ) ) {
                    $headers = getallheaders();
                    if ( isset( $headers['Authorization'] ) ) {
                        $auth_header = $headers['Authorization'];
                    }
                }
                
                if ( $auth_header ) {
                    $request->set_header( 'authorization', $auth_header );
                }
                
                // Check authentication
                $auth_result = self::check_auth( $request );
                if ( is_wp_error( $auth_result ) ) {
                    http_response_code( 401 );
                    header( 'Content-Type: application/json' );
                    echo wp_json_encode( array(
                        'code'    => $auth_result->get_error_code(),
                        'message' => $auth_result->get_error_message(),
                        'data'    => $auth_result->get_error_data()
                    ) );
                    exit;
                }
                
                // Generate and serve metrics
                $metrics = self::build_metrics();
                header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw metrics output for Prometheus format
                echo $metrics;
                exit;
            }
        }

        /**
         * Escape a string for use as a Prometheus label value.
         *
         * Escapes backslashes, newlines and double quotes per Prometheus exposition format.
         *
         * @param string $val
         * @return string
         */
        private static function escape_label_value( $val ) {
            $val = (string) $val;
            return str_replace( array( '\\', "\n", '"' ), array( '\\\\', '\\n', '\\"' ), $val );
        }

        /**
         * Get detailed Site Health check results with individual test information.
         *
         * @return array Detailed health check results with test names and descriptions
         */
        private static function get_health_check_details() {
            try {
                $details = array();
                
                // Try to use WordPress native Site Health
                $health_tests = self::get_wordpress_health_tests();
                
                if ( ! empty( $health_tests ) ) {
                    // Process real WordPress health test results
                    foreach ( $health_tests as $test_name => $test_result ) {
                        if ( ! is_array( $test_result ) || ! isset( $test_result['status'] ) ) {
                            continue;
                        }
                        
                        $status = $test_result['status'];
                        $badge = isset( $test_result['badge']['label'] ) ? strtolower( $test_result['badge']['label'] ) : '';
                        $label = isset( $test_result['label'] ) ? $test_result['label'] : $test_name;
                        $description = isset( $test_result['description'] ) ? wp_strip_all_tags( $test_result['description'] ) : '';
                        
                        // Determine category based on test name and content
                        $category = 'general';
                        
                        // Security-related tests
                        if ( strpos( $test_name, 'https' ) !== false ||
                             strpos( $test_name, 'ssl' ) !== false ||
                             strpos( $test_name, 'security' ) !== false ||
                             strpos( $test_name, 'file_edit' ) !== false ||
                             strpos( $test_name, 'debug' ) !== false ||
                             strpos( $test_name, 'update' ) !== false ||
                             strpos( $test_name, 'version' ) !== false ||
                             strpos( $badge, 'security' ) !== false ) {
                            $category = 'security';
                        }
                        // Performance-related tests  
                        elseif ( strpos( $test_name, 'php' ) !== false ||
                                strpos( $test_name, 'memory' ) !== false ||
                                strpos( $test_name, 'database' ) !== false ||
                                strpos( $test_name, 'performance' ) !== false ||
                                strpos( $test_name, 'cache' ) !== false ||
                                strpos( $badge, 'performance' ) !== false ) {
                            $category = 'performance';
                        }
                        
                        $details[] = array(
                            'test' => $test_name,
                            'label' => $label,
                            'status' => $status,
                            'category' => $category,
                            'description' => substr( $description, 0, 120 ) // Limit description length
                        );
                    }
                    
                    return $details;
                }
                
                // Fallback to enhanced custom checks
                return self::get_enhanced_health_check_details();
                
            } catch ( Exception $e ) {
                return self::get_enhanced_health_check_details();
            }
        }
        
        /**
         * Enhanced health check details with specific test information.
         *
         * @return array Enhanced health check details
         */
        private static function get_enhanced_health_check_details() {
            $details = array();
            
            // Security checks
            
            // File editing check
            if ( ! defined( 'DISALLOW_FILE_EDIT' ) || ! DISALLOW_FILE_EDIT ) {
                $details[] = array(
                    'test' => 'file_editing',
                    'label' => 'File Editing',
                    'status' => 'recommended',
                    'category' => 'security',
                    'description' => 'File editing should be disabled in production environments'
                );
            } else {
                $details[] = array(
                    'test' => 'file_editing',
                    'label' => 'File Editing',
                    'status' => 'good',
                    'category' => 'security',
                    'description' => 'File editing is properly disabled'
                );
            }
            
            // Debug mode check
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $details[] = array(
                    'test' => 'debug_mode',
                    'label' => 'Debug Mode',
                    'status' => 'recommended',
                    'category' => 'security',
                    'description' => 'Debug mode should be disabled in production'
                );
            } else {
                $details[] = array(
                    'test' => 'debug_mode',
                    'label' => 'Debug Mode',
                    'status' => 'good',
                    'category' => 'security',
                    'description' => 'Debug mode is properly disabled'
                );
            }
            
            // WordPress version check
            if ( function_exists( 'get_core_updates' ) ) {
                try {
                    $core_updates = get_core_updates();
                    if ( is_array( $core_updates ) && ! empty( $core_updates ) ) {
                        $latest_update = $core_updates[0];
                        if ( isset( $latest_update->response ) && $latest_update->response === 'upgrade' ) {
                            $details[] = array(
                                'test' => 'wordpress_version',
                                'label' => 'WordPress Version',
                                'status' => 'critical',
                                'category' => 'security',
                                'description' => 'WordPress core update available'
                            );
                        } else {
                            $details[] = array(
                                'test' => 'wordpress_version',
                                'label' => 'WordPress Version',
                                'status' => 'good',
                                'category' => 'security',
                                'description' => 'WordPress is up to date'
                            );
                        }
                    }
                } catch ( Exception $e ) {
                    // Ignore errors
                }
            }
            
            // Plugin updates check
            $plugin_updates = get_site_transient( 'update_plugins' );
            if ( isset( $plugin_updates->response ) && ! empty( $plugin_updates->response ) ) {
                $update_count = count( $plugin_updates->response );
                $details[] = array(
                    'test' => 'plugin_updates',
                    'label' => 'Plugin Updates',
                    'status' => 'recommended',
                    'category' => 'security',
                    'description' => $update_count . ' plugin(s) need updates'
                );
            } else {
                $details[] = array(
                    'test' => 'plugin_updates',
                    'label' => 'Plugin Updates',
                    'status' => 'good',
                    'category' => 'security',
                    'description' => 'All plugins are up to date'
                );
            }
            
            // Performance checks
            
            // PHP version check
            if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
                $details[] = array(
                    'test' => 'php_version',
                    'label' => 'PHP Version',
                    'status' => 'critical',
                    'category' => 'performance',
                    'description' => 'PHP version ' . PHP_VERSION . ' is outdated and unsupported'
                );
            } elseif ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
                $details[] = array(
                    'test' => 'php_version',
                    'label' => 'PHP Version',
                    'status' => 'recommended',
                    'category' => 'performance',
                    'description' => 'PHP version ' . PHP_VERSION . ' should be updated to 8.0+'
                );
            } else {
                $details[] = array(
                    'test' => 'php_version',
                    'label' => 'PHP Version',
                    'status' => 'good',
                    'category' => 'performance',
                    'description' => 'PHP version ' . PHP_VERSION . ' is current'
                );
            }
            
            // Memory limit check
            $memory_limit = ini_get( 'memory_limit' );
            if ( $memory_limit ) {
                $memory_bytes = self::convert_to_bytes( $memory_limit );
                if ( $memory_bytes < 64 * 1024 * 1024 ) { // Less than 64MB
                    $details[] = array(
                        'test' => 'php_memory_limit',
                        'label' => 'PHP Memory Limit',
                        'status' => 'critical',
                        'category' => 'performance',
                        'description' => 'Memory limit ' . $memory_limit . ' is too low'
                    );
                } elseif ( $memory_bytes < 128 * 1024 * 1024 ) { // Less than 128MB
                    $details[] = array(
                        'test' => 'php_memory_limit',
                        'label' => 'PHP Memory Limit',
                        'status' => 'recommended',
                        'category' => 'performance',
                        'description' => 'Memory limit ' . $memory_limit . ' could be increased'
                    );
                } else {
                    $details[] = array(
                        'test' => 'php_memory_limit',
                        'label' => 'PHP Memory Limit',
                        'status' => 'good',
                        'category' => 'performance',
                        'description' => 'Memory limit ' . $memory_limit . ' is adequate'
                    );
                }
            }
            
            // Database connection check
            global $wpdb;
            if ( ! empty( $wpdb->last_error ) ) {
                $details[] = array(
                    'test' => 'database_connection',
                    'label' => 'Database Connection',
                    'status' => 'critical',
                    'category' => 'general',
                    'description' => 'Database connection has errors'
                );
            } else {
                $details[] = array(
                    'test' => 'database_connection',
                    'label' => 'Database Connection',
                    'status' => 'good',
                    'category' => 'general',
                    'description' => 'Database connection is working properly'
                );
            }
            
            // HTTPS check
            if ( ! is_ssl() ) {
                $details[] = array(
                    'test' => 'https_status',
                    'label' => 'HTTPS Status',
                    'status' => 'recommended',
                    'category' => 'security',
                    'description' => 'Site should use HTTPS for better security'
                );
            } else {
                $details[] = array(
                    'test' => 'https_status',
                    'label' => 'HTTPS Status',
                    'status' => 'good',
                    'category' => 'security',
                    'description' => 'Site is properly secured with HTTPS'
                );
            }
            
            return $details;
        }
    }

    // initialize plugin
    WP_Prometheus_Metrics::init();
}

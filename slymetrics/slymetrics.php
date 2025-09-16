<?php
/**
 * Plugin Name: SlyMetrics
 * Plugin URI: https://github.com/slydlake/slymetrics
 * Text Domain: slymetrics
 * Description: Plugin to export metrics for prometheus
 * Author: Timon Först
 * Author URI: https://github.com/slydlake
 * Version: 1.2.0
 * License: MIT
 */

if ( ! class_exists( 'SlyMetrics_Plugin' ) ) {

    /**
     * Simple class encapsulating exporter logic.
     * Keeps global functions minimal and makes testing easier.
     */
    class SlyMetrics_Plugin {

        // Enhanced cache configuration for different metric types
        const CACHE_TTL = 10;
        const CACHE_KEY = 'slymetrics_cache';
        const CACHE_KEY_HEAVY = 'slymetrics_heavy_cache'; // For directory sizes, health checks
        const CACHE_TTL_HEAVY = 300; // 5 minutes for heavy operations
        const CACHE_KEY_STATIC = 'slymetrics_static_cache'; // For PHP config, WP version
        const CACHE_TTL_STATIC = 3600; // 1 hour for static data

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
            
            // Register custom rewrite rules for slymetrics endpoints
            add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
            add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
            
            // Single unified metrics handler (early interception for all URL patterns)
            add_action( 'parse_request', array( __CLASS__, 'handle_metrics_request' ) );
            
            // Check and refresh rewrite rules if needed (admin only)
            add_action( 'admin_init', array( __CLASS__, 'maybe_flush_rewrite_rules' ) );
        }

        /**
         * Register REST route for metrics.
         * Protected endpoint with authentication.
         */
        public static function register_routes() {
            register_rest_route(
                'slymetrics/v1',
                '/metrics',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_callback' ),
                    'permission_callback' => array( __CLASS__, 'check_auth' ),
                )
            );
        }

        /**
         * Extract authorization header from request or server variables.
         * Centralized auth header extraction to eliminate duplication.
         *
         * @param WP_REST_Request|null $request Optional REST request object
         * @return string|false Authorization header or false if not found
         */
        private static function extract_auth_header( $request = null ) {
            $auth_header = false;
            
            // Try REST request first if provided
            if ( $request ) {
                $auth_header = $request->get_header( 'authorization' );
                if ( ! $auth_header ) {
                    $auth_header = $request->get_header( 'HTTP_AUTHORIZATION' );
                }
            }
            
            // Fallback to server variables
            if ( ! $auth_header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
                $auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
            }
            
            // Last resort: getallheaders()
            if ( ! $auth_header && function_exists( 'getallheaders' ) ) {
                $headers = getallheaders();
                if ( isset( $headers['Authorization'] ) ) {
                    $auth_header = $headers['Authorization'];
                }
            }
            
            return $auth_header;
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
            $auth_header = self::extract_auth_header( $request );
            
            if ( $auth_header && preg_match( '/Bearer\s+(.+)/', $auth_header, $matches ) ) {
                $token = trim( $matches[1] );
                $valid_token = self::get_auth_token( 'slymetrics_auth_token' );
                if ( $valid_token && hash_equals( $valid_token, $token ) ) {
                    return true;
                }
            }

            // Option 2: API Key as query parameter
            $api_key = $request->get_param( 'api_key' );
            if ( $api_key ) {
                $valid_api_key = self::get_auth_token( 'slymetrics_api_key' );
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
                __( 'Authentication required for metrics endpoint.', 'slymetrics' ), 
                array( 'status' => 401 ) 
            );
        }

        /**
         * Unified metrics request handler for all URL patterns.
         * Replaces 4 separate handlers with one efficient implementation.
         *
         * @param WP $wp WordPress object
         */
        public static function handle_metrics_request( $wp ) {
            // Get the requested path with enhanced security validation
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            $parsed_url = wp_parse_url( $request_uri );
            $path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';
            
            // Additional security: validate path contains only safe characters
            if ( ! empty( $path ) && ! preg_match( '/^[a-zA-Z0-9\/_-]+$/', $path ) ) {
                self::log_error( 'Invalid characters in request path', array( 'path' => $path, 'ip' => self::get_client_ip() ) );
                return;
            }
            
            // Check for query parameter pattern first
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public metrics endpoint with custom authentication
            if ( isset( $_GET['slymetrics'] ) || isset( $_GET['slybase_metrics'] ) ) {
                self::serve_metrics_response();
                return;
            }
            
            // Check for path-based patterns
            $metrics_paths = array(
                'slymetrics/metrics',
                'slymetrics',
                'metrics'
            );
            
            if ( in_array( $path, $metrics_paths, true ) ) {
                self::serve_metrics_response();
                return;
            }
            
            // Check for query variable pattern (rewrite rules)
            $endpoint = get_query_var( 'slymetrics_endpoint' );
            if ( $endpoint === 'metrics' ) {
                self::serve_metrics_response();
                return;
            }
        }

        /**
         * Serve metrics response with authentication and caching.
         * Centralized response logic for all endpoint patterns.
         */
        private static function serve_metrics_response() {
            // Simple rate limiting check
            $client_ip = self::get_client_ip();
            $rate_limit_key = 'slymetrics_rate_limit_' . md5( $client_ip );
            $current_requests = (int) get_transient( $rate_limit_key );
            
            // Allow maximum 60 requests per minute per IP
            if ( $current_requests >= 60 ) {
                status_header( 429 );
                header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
                header( 'Retry-After: 60' );
                header( 'X-RateLimit-Limit: 60' );
                header( 'X-RateLimit-Remaining: 0' );
                header( 'X-RateLimit-Reset: ' . ( time() + 60 ) );
                
                self::log_error( 'Rate limit exceeded', array( 'ip' => $client_ip, 'requests' => $current_requests ) );
                
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON error response
                echo wp_json_encode( array( 'error' => 'Rate limit exceeded. Please try again later.' ) );
                exit;
            }
            
            // Increment request counter
            set_transient( $rate_limit_key, $current_requests + 1, 60 );
            
            // Create a fake REST request for authentication
            $fake_request = new WP_REST_Request( 'GET', '/slymetrics/v1/metrics' );
            
            // Copy API key from query parameters
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public metrics endpoint with custom authentication
            if ( isset( $_GET['api_key'] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public metrics endpoint with custom authentication
                $fake_request->set_param( 'api_key', sanitize_text_field( wp_unslash( $_GET['api_key'] ) ) );
            }
            
            // Check authentication
            $auth_result = self::check_auth( $fake_request );
            if ( is_wp_error( $auth_result ) ) {
                status_header( 401 );
                header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
                echo wp_json_encode( array(
                    'error' => 'Authentication Required',
                    'message' => $auth_result->get_error_message()
                ) );
                exit;
            }
            
            // Serve metrics with cache
            $metrics = self::get_cached_metrics();
            
            // Enhanced security headers
            status_header( 200 );
            header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Frame-Options: DENY' );
            header( 'X-XSS-Protection: 1; mode=block' );
            header( 'Referrer-Policy: no-referrer' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
            
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw metrics output for Prometheus format
            echo $metrics;
            exit;
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
            $metrics = self::get_cached_metrics();

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
            if ( $route !== '/slymetrics/v1/metrics' ) {
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
         * Get cached metrics or build new ones if cache is expired.
         * Enhanced caching with segmented cache keys for different metric types.
         *
         * @return string Prometheus formatted metrics
         */
        private static function get_cached_metrics() {
            // Try to get cached metrics
            $metrics = get_transient( self::CACHE_KEY );
            
            if ( false === $metrics ) {
                // Build fresh metrics with segmented caching strategy
                $metrics = self::build_metrics_with_caching();
                
                // Cache the complete metrics output
                set_transient( self::CACHE_KEY, $metrics, self::CACHE_TTL );
            }
            
            return $metrics;
        }

        /**
         * Build metrics with intelligent caching for different metric types.
         * Heavy operations are cached longer, static data cached longest.
         *
         * @return string Complete metrics output
         */
        private static function build_metrics_with_caching() {
            $out = '';
            $site_name = get_bloginfo( 'name' );

            // Fast metrics (cached for 10 seconds)
            $fast_metrics = get_transient( self::CACHE_KEY . '_fast' );
            if ( false === $fast_metrics ) {
                $fast_metrics = '';
                $fast_metrics .= self::build_user_metrics( $site_name );
                $fast_metrics .= self::build_post_metrics( $site_name );
                $fast_metrics .= self::build_plugin_metrics( $site_name );
                $fast_metrics .= self::build_content_metrics( $site_name );
                
                set_transient( self::CACHE_KEY . '_fast', $fast_metrics, self::CACHE_TTL );
            }
            $out .= $fast_metrics;

            // Heavy metrics (cached for 5 minutes)
            $heavy_metrics = get_transient( self::CACHE_KEY_HEAVY );
            if ( false === $heavy_metrics ) {
                $heavy_metrics = self::build_heavy_metrics( $site_name );
                set_transient( self::CACHE_KEY_HEAVY, $heavy_metrics, self::CACHE_TTL_HEAVY );
            }
            $out .= $heavy_metrics;

            // Static metrics (cached for 1 hour)
            $static_metrics = get_transient( self::CACHE_KEY_STATIC );
            if ( false === $static_metrics ) {
                $static_metrics = self::build_static_metrics( $site_name );
                set_transient( self::CACHE_KEY_STATIC, $static_metrics, self::CACHE_TTL_STATIC );
            }
            $out .= $static_metrics;

            return $out;
        }

        /**
         * Build content-related metrics (comments, categories, media, tags).
         *
         * @param string $site_name Site name for labels
         * @return string Prometheus formatted content metrics
         */
        private static function build_content_metrics( $site_name ) {
            $out = '';
            
            // Comments
            $comments = wp_count_comments();
            // Ensure we have an array of status => count
            $counts = is_object( $comments ) ? get_object_vars( $comments ) : (array) $comments;

            $out .= "# HELP wordpress_comments_total Total number of comments by status.\n";
            $out .= "# TYPE wordpress_comments_total counter\n";

            // Output per status: wordpress_comments_total{wordpress_site="...",status="approved"} 123
            foreach ( $counts as $status => $count ) {
                // Skip non-numeric and the total field for now
                if ( $status === 'total_comments' || ! is_numeric( $count ) ) {
                    continue;
                }

                // Normalizations for common WP field names
                if ( $status === 'awaiting_moderation' ) {
                    $label = 'moderated';
                } elseif ( $status === 'post-trashed' ) {
                    $label = 'post_trashed';
                } else {
                    $label = $status;
                }

                $out .= self::format_metric( 'wordpress_comments_total', $site_name, array( 'status' => $label ), (int) $count );
            }

            // Categories
            $category_count = (int) wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
            $out .= "# HELP wordpress_categories_total Total number of categories.\n";
            $out .= "# TYPE wordpress_categories_total counter\n";
            $out .= self::format_metric( 'wordpress_categories_total', $site_name, array(), $category_count );

            // Media (attachments) - optimized query
            $media_count = self::get_media_count_optimized();
            $out .= "# HELP wordpress_media_total Total number of media items.\n";
            $out .= "# TYPE wordpress_media_total counter\n";
            $out .= self::format_metric( 'wordpress_media_total', $site_name, array(), $media_count );

            // Tags
            $tag_count = (int) wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
            $out .= "# HELP wordpress_tags_total Total number of tags.\n";
            $out .= "# TYPE wordpress_tags_total counter\n";
            $out .= self::format_metric( 'wordpress_tags_total', $site_name, array(), $tag_count );
            
            return $out;
        }

        /**
         * Optimized media count calculation with caching and fallbacks.
         *
         * @return int Media count
         */
        private static function get_media_count_optimized() {
            // Try cached media count first
            $media_count = get_transient( 'slymetrics_media_count' );
            if ( false !== $media_count ) {
                return (int) $media_count;
            }
            
            $media_count = 0;
            
            // Primary method: wp_count_posts
            $attachments = wp_count_posts( 'attachment' );
            if ( is_object( $attachments ) ) {
                // wp_count_posts returns an object with status properties (e.g. inherit, publish, trash...)
                foreach ( get_object_vars( $attachments ) as $count ) {
                    $media_count += (int) $count;
                }
            }
            
            // Fallback if primary method failed
            if ( $media_count === 0 ) {
                // Fallback if wp_count_posts unexpectedly doesn't return an object
                $media_count = (int) count( get_posts( array(
                    'post_type'   => 'attachment',
                    'post_status' => 'any',
                    'numberposts' => -1,
                ) ) );
            }
            
            // Cache the result for 5 minutes
            set_transient( 'slymetrics_media_count', $media_count, 300 );
            
            return $media_count;
        }

        /**
         * Build heavy metrics that require intensive operations.
         *
         * @param string $site_name Site name for labels
         * @return string Prometheus formatted heavy metrics
         */
        private static function build_heavy_metrics( $site_name ) {
            $out = '';
            global $wpdb;
            
            // Database operations with enhanced security
            try {
                // Enhanced secure autoload query with input validation
                if ( ! isset( $wpdb->options ) || empty( $wpdb->options ) ) {
                    self::log_error( 'Options table not available for autoload calculation' );
                    return $out;
                }
                
                // Validate table name to prevent injection
                $options_table = $wpdb->options;
                if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', str_replace( $wpdb->prefix, '', $options_table ) ) ) {
                    self::log_error( 'Options table name contains invalid characters', array( 'table' => $options_table ) );
                    return $out;
                }
                
                // Get all autoload metrics in a single secure query
                $sql = $wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total_count,
                        ROUND(SUM(LENGTH(option_value)) / 1024) as size_kb,
                        SUM(CASE WHEN option_name LIKE %s THEN 1 ELSE 0 END) as transient_count
                    FROM " . esc_sql( $options_table ) . " 
                    WHERE autoload = %s",
                    '%transient%',
                    'yes'
                );
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Metrics data, cached at application level via transients. SQL is prepared above using $wpdb->prepare()
                $autoload_data = $wpdb->get_row( $sql );

                if ( $autoload_data && is_object( $autoload_data ) ) {
                    // Validate retrieved data
                    $total_count = max( 0, (int) $autoload_data->total_count );
                    $size_kb = max( 0, (int) $autoload_data->size_kb );
                    $transient_count = max( 0, (int) $autoload_data->transient_count );
                    
                    // Autoload count
                    $out .= "# HELP wordpress_autoload_options_total Number of autoloaded options.\n";
                    $out .= "# TYPE wordpress_autoload_options_total gauge\n";
                    $out .= self::format_metric( 'wordpress_autoload_options_total', $site_name, array(), $total_count );
                    
                    // Autoload size in bytes
                    $out .= "# HELP wordpress_autoload_size_bytes Size of autoloaded options in bytes.\n";
                    $out .= "# TYPE wordpress_autoload_size_bytes gauge\n";
                    $out .= self::format_metric( 'wordpress_autoload_size_bytes', $site_name, array(), ($size_kb * 1024) );
                    
                    // Autoload transient count
                    $out .= "# HELP wordpress_autoload_transients_total Number of autoloaded transients.\n";
                    $out .= "# TYPE wordpress_autoload_transients_total gauge\n";
                    $out .= self::format_metric( 'wordpress_autoload_transients_total', $site_name, array(), $transient_count );
                }
            } catch ( Exception $e ) {
                self::log_error( 'Failed to get autoload options data', array( 'error' => $e->getMessage() ) );
            }

            // Database Size
            try {
                // Enhanced secure database query with input validation
                if ( ! defined( 'DB_NAME' ) || empty( DB_NAME ) ) {
                    self::log_error( 'Database name not available for size calculation' );
                    return $out;
                }
                
                // Validate database name to prevent injection
                $db_name = preg_replace( '/[^a-zA-Z0-9_]/', '', DB_NAME );
                if ( $db_name !== DB_NAME ) {
                    self::log_error( 'Database name contains invalid characters', array( 'db_name' => DB_NAME ) );
                    return $out;
                }
                
                // Secure prepared statement with validated input
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metrics data, cached at application level via transients
                $db_size_mb = (float) $wpdb->get_var( $wpdb->prepare( 
                    "SELECT SUM(ROUND(((data_length + index_length) / 1024 / 1024), 2)) as value 
                     FROM information_schema.TABLES 
                     WHERE table_schema = %s 
                     AND table_type = 'BASE TABLE'", 
                    $db_name 
                ) );
                
                if ( $db_size_mb > 0 ) {
                    $out .= "# HELP wordpress_database_size_bytes Database size in bytes.\n";
                    $out .= "# TYPE wordpress_database_size_bytes gauge\n";
                    $out .= self::format_metric( 'wordpress_database_size_bytes', $site_name, array(), ($db_size_mb * 1024 * 1024) );
                }
            } catch ( Exception $e ) {
                self::log_error( 'Failed to get database size', array( 'error' => $e->getMessage(), 'database' => DB_NAME ) );
            }

            // Directory Sizes (simplified and safe)
            $directory_sizes = self::get_directory_sizes();
            if ( ! empty( $directory_sizes ) ) {
                $out .= "# HELP wordpress_directory_size_bytes Directory sizes in bytes.\n";
                $out .= "# TYPE wordpress_directory_size_bytes gauge\n";
                
                foreach ( $directory_sizes as $dir_type => $size_mb ) {
                    $out .= self::format_metric( 'wordpress_directory_size_bytes', $site_name, array( 'directory' => $dir_type ), ($size_mb * 1024 * 1024) );
                }
            }

            // Site Health Check Results (safe implementation)
            $health_check = self::get_health_check_results();
            if ( ! empty( $health_check ) ) {
                $out .= "# HELP wordpress_health_check_total Site health check results.\n";
                $out .= "# TYPE wordpress_health_check_total gauge\n";
                
                foreach ( $health_check as $category => $count ) {
                    $out .= self::format_metric( 'wordpress_health_check_total', $site_name, array( 'category' => $category ), $count );
                }
            }

            // Site Health Check Details (individual test results)
            $health_details = self::get_health_check_details();
            if ( ! empty( $health_details ) ) {
                $out .= "# HELP wordpress_health_check_detail_info Individual health check test results.\n";
                $out .= "# TYPE wordpress_health_check_detail_info gauge\n";
                
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
                    
                    $out .= self::format_metric( 'wordpress_health_check_detail_info', $site_name, array( 
                        'test_name' => $test_detail['test'],
                        'status' => $test_detail['status'],
                        'category' => $test_detail['category'],
                        'description' => $test_detail['description']
                    ), $status_value );
                }
            }
            
            return $out;
        }

        /**
         * Build static metrics that rarely change.
         *
         * @param string $site_name Site name for labels
         * @return string Prometheus formatted static metrics
         */
        private static function build_static_metrics( $site_name ) {
            $out = '';
            
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
            
            $out .= "# HELP wordpress_version WordPress version information.\n";
            $out .= "# TYPE wordpress_version gauge\n";
            $out .= self::format_metric( 'wordpress_version', $site_name, array( 
                'version' => $wp_version,
                'update_available' => (string) $update_available
            ), 1 );

            // PHP Information
            try {
                $out .= "# HELP wordpress_php_info PHP configuration information.\n";
                $out .= "# TYPE wordpress_php_info gauge\n";
                
                // PHP Version - with numeric ID for backwards compatibility
                $out .= self::format_metric( 'wordpress_php_info', $site_name, array( 'type' => 'version', 'label' => PHP_VERSION ), PHP_VERSION_ID );
                $out .= self::format_metric( 'wordpress_php_info', $site_name, array( 'type' => 'major_version', 'label' => (string) PHP_MAJOR_VERSION ), PHP_MAJOR_VERSION );
                $out .= self::format_metric( 'wordpress_php_info', $site_name, array( 'type' => 'minor_version', 'label' => (string) PHP_MINOR_VERSION ), PHP_MINOR_VERSION );
                $out .= self::format_metric( 'wordpress_php_info', $site_name, array( 'type' => 'release_version', 'label' => (string) PHP_RELEASE_VERSION ), PHP_RELEASE_VERSION );

                // Add new metric for PHP version string display
                $out .= "# HELP wordpress_php_version_info PHP version as readable string.\n";
                $out .= "# TYPE wordpress_php_version_info gauge\n";
                $out .= self::format_metric( 'wordpress_php_version_info', $site_name, array( 'php_version' => PHP_VERSION ), 1 );

                // Add simple configuration metrics for table display
                $out .= "# HELP wordpress_config_info WordPress and PHP configuration values.\n";
                $out .= "# TYPE wordpress_config_info gauge\n";
                
                // Add specific metrics for table display
                $out .= "# HELP wordpress_memory_limit_info Memory limit for table display.\n";
                $out .= "# TYPE wordpress_memory_limit_info gauge\n";
                
                $out .= "# HELP wordpress_upload_max_info Upload max filesize for table display.\n";
                $out .= "# TYPE wordpress_upload_max_info gauge\n";
                
                $out .= "# HELP wordpress_post_max_info Post max size for table display.\n";
                $out .= "# TYPE wordpress_post_max_info gauge\n";
                
                $out .= "# HELP wordpress_exec_time_info Max execution time for table display.\n";
                $out .= "# TYPE wordpress_exec_time_info gauge\n";

                // PHP Configuration values
                if ( function_exists( 'ini_get' ) ) {
                    $php_configs = array( 'max_input_vars', 'max_execution_time', 'memory_limit', 'max_input_time', 'upload_max_filesize', 'post_max_size' );
                    
                    foreach ( $php_configs as $php_variable ) {
                        $php_value = ini_get( $php_variable );
                        $numeric_value = self::convert_to_bytes( $php_value );
                        
                        // General PHP info metric
                        $out .= self::format_metric( 'wordpress_php_info', $site_name, array( 'type' => $php_variable, 'label' => $php_value ), $numeric_value );
                        
                        // General config metric
                        $out .= self::format_metric( 'wordpress_config_info', $site_name, array( 'config' => $php_variable, 'value' => $php_value ), $numeric_value );
                        
                        // Specific display metrics for table usage
                        if ( $php_variable === 'memory_limit' ) {
                            $out .= self::format_metric( 'wordpress_memory_limit_info', $site_name, array( 'memory_limit' => $php_value ), 1 );
                        } elseif ( $php_variable === 'upload_max_filesize' ) {
                            $out .= self::format_metric( 'wordpress_upload_max_info', $site_name, array( 'upload_max' => $php_value ), 1 );
                        } elseif ( $php_variable === 'post_max_size' ) {
                            $out .= self::format_metric( 'wordpress_post_max_info', $site_name, array( 'post_max' => $php_value ), 1 );
                        } elseif ( $php_variable === 'max_execution_time' ) {
                            $out .= self::format_metric( 'wordpress_exec_time_info', $site_name, array( 'exec_time' => $php_value ), 1 );
                        }
                    }
                }
            } catch ( Exception $e ) {
                self::log_error( 'Failed to get PHP information', array( 'error' => $e->getMessage() ) );
            }
            
            return $out;
        }

        /**
         * Log error messages with context for debugging.
         * Only logs when WP_DEBUG is enabled to avoid production noise.
         *
         * @param string $message Error message
         * @param array $context Additional context data
         */
        private static function log_error( $message, $context = array() ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                $context_string = ! empty( $context ) ? ' Context: ' . wp_json_encode( $context ) : '';
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging when explicitly enabled via WP_DEBUG and WP_DEBUG_LOG
                error_log( 'SlyMetrics Error: ' . $message . $context_string );
            }
        }

        /**
         * Get client IP address securely with proxy support.
         * Validates and sanitizes IP addresses from various headers.
         *
         * @return string Client IP address or 'unknown'
         */
        private static function get_client_ip() {
            $ip_headers = array(
                'HTTP_CF_CONNECTING_IP',     // Cloudflare
                'HTTP_X_REAL_IP',            // Nginx proxy
                'HTTP_X_FORWARDED_FOR',      // Standard proxy header
                'HTTP_X_FORWARDED',          // Proxy header
                'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
                'HTTP_CLIENT_IP',            // Proxy header
                'REMOTE_ADDR'                // Standard
            );
            
            foreach ( $ip_headers as $header ) {
                if ( ! empty( $_SERVER[ $header ] ) ) {
                    $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
                    
                    // Handle comma-separated IPs (X-Forwarded-For)
                    if ( strpos( $ip, ',' ) !== false ) {
                        $ip = trim( explode( ',', $ip )[0] );
                    }
                    
                    // Validate IP address
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                        return $ip;
                    }
                }
            }
            
            return 'unknown';
        }

        /**
         * Enhanced label value escaping for Prometheus format.
         * Prevents injection attacks and ensures valid Prometheus labels.
         *
         * @param string $value Value to escape
         * @return string Safely escaped value
         */
        private static function escape_label_value( $value ) {
            // Convert to string and limit length to prevent DoS
            $value = (string) $value;
            if ( strlen( $value ) > 1000 ) {
                $value = substr( $value, 0, 1000 ) . '...';
                self::log_error( 'Label value truncated due to excessive length', array( 'original_length' => strlen( $value ) ) );
            }
            
            // Escape special characters for Prometheus format
            $value = str_replace( array( '\\', '"', "\n", "\r", "\t" ), array( '\\\\', '\\"', '\\n', '\\r', '\\t' ), $value );
            
            // Remove or replace potentially dangerous characters
            $value = preg_replace( '/[^\x20-\x7E]/', '', $value ); // Remove non-printable ASCII
            
            return $value;
        }

        /**
         * Generate Prometheus metric line with site label.
         * Reduces code duplication in metric generation.
         *
         * @param string $metric_name Metric name
         * @param string $site_name Site name to include in label
         * @param array $labels Additional labels as key => value pairs
         * @param mixed $value Metric value
         * @return string Formatted metric line
         */
        private static function format_metric( $metric_name, $site_name, $labels = array(), $value = 1 ) {
            // Validate and sanitize metric name
            $metric_name = preg_replace( '/[^a-zA-Z0-9_:]/', '_', (string) $metric_name );
            if ( empty( $metric_name ) ) {
                self::log_error( 'Invalid metric name provided', array( 'original' => $metric_name ) );
                return '';
            }
            
            // Validate numeric value
            if ( ! is_numeric( $value ) ) {
                self::log_error( 'Non-numeric value provided for metric', array( 'metric' => $metric_name, 'value' => $value ) );
                $value = 0;
            }
            
            $label_string = 'wordpress_site="' . self::escape_label_value( $site_name ) . '"';
            
            foreach ( $labels as $key => $label_value ) {
                // Validate label key
                $key = preg_replace( '/[^a-zA-Z0-9_]/', '_', (string) $key );
                if ( ! empty( $key ) ) {
                    $label_string .= ',' . $key . '="' . self::escape_label_value( $label_value ) . '"';
                }
            }
            
            return $metric_name . '{' . $label_string . '} ' . $value . "\n";
        }

        /**
         * Build plugin and theme related metrics.
         *
         * @param string $site_name Site name for labels
         * @return string Prometheus formatted plugin/theme metrics
         */
        private static function build_plugin_metrics( $site_name ) {
            $out = '';
            
            // Active plugins - ensure get_plugins function is available
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
            $total_installed_plugins = is_array( $all_plugins ) ? count( $all_plugins ) : 0;

            $active_plugins = get_option( 'active_plugins', array() );
            $active_count = is_array( $active_plugins ) ? count( $active_plugins ) : 0;

            // Inactive (installed but not activated) plugins
            $inactive_plugins = array_diff( array_keys( $all_plugins ), $active_plugins );
            $inactive_count = count( $inactive_plugins );

            // Plugin status metrics
            $out .= "# HELP wordpress_plugins_total Number of active and inactive plugins.\n";
            $out .= "# TYPE wordpress_plugins_total counter\n";
            $out .= self::format_metric( 'wordpress_plugins_total', $site_name, array( 'status' => 'active' ), $active_count );
            $out .= self::format_metric( 'wordpress_plugins_total', $site_name, array( 'status' => 'inactive' ), $inactive_count );
            $out .= self::format_metric( 'wordpress_plugins_total', $site_name, array( 'status' => 'all' ), $total_installed_plugins );

            // Plugin update status
            $updates = get_site_transient( 'update_plugins' );
            $updates_response = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
            $plugins_with_updates = array_keys( $updates_response );

            // Count ALL installed plugins (active + inactive) that need updates
            $all_installed_plugins = array_keys( $all_plugins );
            $plugins_needing_update = array_intersect( $all_installed_plugins, $plugins_with_updates );
            $plugins_up_to_date = array_diff( $all_installed_plugins, $plugins_needing_update );

            $out .= "# HELP wordpress_plugins_update_total Plugin update status.\n";
            $out .= "# TYPE wordpress_plugins_update_total counter\n";
            $out .= self::format_metric( 'wordpress_plugins_update_total', $site_name, array( 'status' => 'available' ), count( $plugins_needing_update ) );
            $out .= self::format_metric( 'wordpress_plugins_update_total', $site_name, array( 'status' => 'uptodate' ), count( $plugins_up_to_date ) );

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
            }

            $out .= "# HELP wordpress_themes_total Number of installed themes.\n";
            $out .= "# TYPE wordpress_themes_total counter\n";
            $out .= self::format_metric( 'wordpress_themes_total', $site_name, array( 'type' => 'child' ), $child_count );
            $out .= self::format_metric( 'wordpress_themes_total', $site_name, array( 'type' => 'parent' ), $parent_count );
            
            return $out;
        }

        /**
         * Build post and page related metrics.
         *
         * @param string $site_name Site name for labels
         * @return string Prometheus formatted post/page metrics
         */
        private static function build_post_metrics( $site_name ) {
            $out = '';
            
            // Posts (default post type)
            $posts = wp_count_posts();
            $posts_pub = isset( $posts->publish ) ? (int) $posts->publish : 0;
            $posts_draft = isset( $posts->draft ) ? (int) $posts->draft : 0;

            // Calculate total posts from all statuses
            $posts_total = 0;
            if ( is_object( $posts ) ) {
                foreach ( get_object_vars( $posts ) as $cnt ) {
                    $posts_total += (int) $cnt;
                }
            }

            $out .= "# HELP wordpress_posts_total Number of posts.\n";
            $out .= "# TYPE wordpress_posts_total counter\n";
            $out .= self::format_metric( 'wordpress_posts_total', $site_name, array( 'status' => 'published' ), $posts_pub );
            $out .= self::format_metric( 'wordpress_posts_total', $site_name, array( 'status' => 'draft' ), $posts_draft );
            $out .= self::format_metric( 'wordpress_posts_total', $site_name, array( 'status' => 'all' ), $posts_total );

            // Pages
            $pages = wp_count_posts( 'page' );
            $pages_pub = isset( $pages->publish ) ? (int) $pages->publish : 0;
            $pages_draft = isset( $pages->draft ) ? (int) $pages->draft : 0;
            $out .= "# HELP wordpress_pages_total Number of pages.\n";
            $out .= "# TYPE wordpress_pages_total counter\n";
            $out .= self::format_metric( 'wordpress_pages_total', $site_name, array( 'status' => 'published' ), $pages_pub );
            $out .= self::format_metric( 'wordpress_pages_total', $site_name, array( 'status' => 'draft' ), $pages_draft );
            $out .= self::format_metric( 'wordpress_pages_total', $site_name, array( 'status' => 'all' ), ($pages_pub + $pages_draft) );
            
            return $out;
        }

        /**
         * Build user-related metrics.
         *
         * @param string $site_name Site name for labels
         * @return string Prometheus formatted user metrics
         */
        private static function build_user_metrics( $site_name ) {
            $out = '';
            
            // Users
            $users = count_users();
            $total_users = isset( $users['total_users'] ) ? (int) $users['total_users'] : 0;
            $avail_roles = isset( $users['avail_roles'] ) && is_array( $users['avail_roles'] ) ? $users['avail_roles'] : array();

            $out .= "# HELP wordpress_users_total Number of users per role.\n";
            $out .= "# TYPE wordpress_users_total counter\n";

            // Output per role as label role="..."
            foreach ( $avail_roles as $role => $count ) {
                $out .= self::format_metric( 'wordpress_users_total', $site_name, array( 'role' => $role ), (int) $count );
            }

            // Total as separate line (optional)
            $out .= self::format_metric( 'wordpress_users_total', $site_name, array( 'role' => 'total' ), $total_users );
            
            return $out;
        }

        /**
         * Build WordPress-formatted metrics string.
         * Simplified orchestration function that delegates to specialized builders.
         *
         * @return string Complete Prometheus formatted metrics
         */
        private static function build_metrics() : string {
            // Delegate to the enhanced caching system
            return self::build_metrics_with_caching();
        }

        /**
         * Add admin menu for SlyMetrics settings.
         */
        public static function add_admin_menu() {
            add_options_page(
                'SlyMetrics Settings',
                'SlyMetrics',
                'manage_options',
                'slymetrics',
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
                admin_url( 'options-general.php?page=slymetrics' ),
                __( 'Settings', 'slymetrics' )
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
            if ( isset( $_POST['slymetrics_action'] ) && check_admin_referer( 'slymetrics_settings' ) ) {
                if ( $_POST['slymetrics_action'] === 'regenerate_tokens' ) {
                    // Regenerate API key (always allowed)
                    self::set_encrypted_option( 'slymetrics_api_key', self::generate_secure_token() );
                    
                    // Only regenerate Bearer token if not using environment key
                    if ( ! self::is_encryption_key_from_env() ) {
                        self::set_encrypted_option( 'slymetrics_auth_token', self::generate_secure_token() );
                        add_settings_error( 'slymetrics_messages', 'tokens_regenerated', 'Bearer Token and API Key successfully regenerated!', 'updated' );
                    } else {
                        add_settings_error( 'slymetrics_messages', 'api_key_regenerated', 'API Key successfully regenerated! Bearer Token is managed via environment variable.', 'updated' );
                    }
                }
            }
        }

        /**
         * Render admin page.
         */
        public static function admin_page() {
            $auth_settings = self::get_auth_settings();
            $endpoint_url = home_url( '/wp-json/slymetrics/v1/metrics' );
            $is_env_key = self::is_encryption_key_from_env();
            
            ?>
            <div class="wrap">
                <h1>SlyMetrics Settings</h1>
                
                <?php settings_errors( 'slymetrics_messages' ); ?>
                
                <div class="card">
                    <h2>Endpoint Information</h2>
                    <p><strong>Primary Metrics Endpoint (Clean URL):</strong> <code><?php echo esc_url( home_url( '/slymetrics/metrics' ) ); ?></code></p>
                    <p><em>Recommended endpoint with clean URL structure. Works out-of-the-box with WordPress permalinks enabled.</em></p>
                    
                    <h3>Alternative Endpoints (Fallback)</h3>
                    <p>If the primary endpoint is not working, use these alternative URLs:</p>
                    <ul>
                        <li><strong>REST API Endpoint:</strong> <code><?php echo esc_url( $endpoint_url ); ?></code></li>
                        <li><strong>REST API Fallback:</strong> <code><?php echo esc_url( home_url( '/index.php?rest_route=/slymetrics/v1/metrics' ) ); ?></code> <em>(always works)</em></li>
                        <li><strong>Query Parameter:</strong> <code><?php echo esc_url( home_url( '/?slymetrics=1' ) ); ?></code> <em>(always works)</em></li>
                        <li><strong>Short Path:</strong> <code><?php echo esc_url( home_url( '/metrics' ) ); ?></code> <em>(alternative clean URL)</em></li>
                    </ul>
                    
                    <p><em>All endpoints are protected by authentication and return the same metrics data in Prometheus format.</em></p>
                    <?php if ( $is_env_key ): ?>
                        <p><strong>🔐 Security:</strong> <em>Encryption key is loaded from environment variable <code>SLYMETRICS_ENCRYPTION_KEY</code> for enhanced security.</em></p>
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
                        <?php wp_nonce_field( 'slymetrics_settings' ); ?>
                        <input type="hidden" name="slymetrics_action" value="regenerate_tokens" />
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
                            <li><strong>🔐 Environment Key:</strong> Using encryption key from <code>SLYMETRICS_ENCRYPTION_KEY</code> environment variable</li>
                            <li><strong>Bearer Token:</strong> Can be provided via <code>SLYMETRICS_BEARER_TOKEN</code> environment variable</li>
                            <li><strong>Enhanced Security:</strong> Environment variables are not stored in database and cannot be accessed via web interface</li>
                        <?php else: ?>
                            <li><strong>Database Key:</strong> Encryption key is auto-generated and stored in WordPress database</li>
                            <li><strong>Enhanced Security Option:</strong> Set <code>SLYMETRICS_ENCRYPTION_KEY</code> and <code>SLYMETRICS_BEARER_TOKEN</code> environment variables for better security</li>
                        <?php endif; ?>
                    </ul>
                    
                    <h3>Environment Variable Setup</h3>
                    <p>For production environments, use environment variables for enhanced security:</p>
                    
                    <h4>Docker</h4>
                    <div class="code-block">
                        <code id="docker-example">docker run -e SLYMETRICS_ENCRYPTION_KEY="$(openssl rand -base64 32)" -e SLYMETRICS_BEARER_TOKEN="$(openssl rand -hex 32)" your-wordpress-image</code>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('docker-example')">Copy</button>
                    </div>
                    
                    <h4>Kubernetes</h4>
                    <div class="code-block">
                        <pre id="k8s-example">env:
  - name: SLYMETRICS_ENCRYPTION_KEY
    valueFrom:
      secretKeyRef:
        name: wordpress-secrets
        key: slymetrics-encryption-key
  - name: SLYMETRICS_BEARER_TOKEN
    valueFrom:
      secretKeyRef:
        name: wordpress-secrets
        key: slymetrics-bearer-token</pre>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('k8s-example')">Copy</button>
                    </div>
                    
                    <p><strong>Environment Variables:</strong></p>
                    <ul>
                        <li><code>SLYMETRICS_ENCRYPTION_KEY</code> - Base64 encoded encryption key for API keys</li>
                        <li><code>SLYMETRICS_BEARER_TOKEN</code> - Bearer token for SlyMetrics authentication (plain text)</li>
                    </ul>
                    
                    <p>📖 <strong>More Examples:</strong> <a href="https://github.com/slydlake/wordpress-prometheus-metrics#security-features" target="_blank">See GitHub Documentation</a></p>
                </div>

                <div class="card">
                    <h2>Usage Examples</h2>
                    
                    <h3>Primary Endpoint (Clean URL)</h3>
                    <h4>cURL with Bearer Token</h4>
                    <div class="code-block">
                        <code id="curl-primary">curl -H "Authorization: Bearer <?php echo esc_attr( $auth_settings['bearer_token'] ); ?>" "<?php echo esc_url( home_url( '/slymetrics/metrics' ) ); ?>"</code>
                        <button type="button" class="button copy-btn" onclick="copyToClipboard('curl-primary')">Copy</button>
                    </div>
                    
                    <h4>cURL with API Key</h4>
                    <div class="code-block">
                        <code id="curl-apikey-primary">curl "<?php echo esc_url( home_url( '/slymetrics/metrics' ) ); ?>?api_key=<?php echo esc_attr( $auth_settings['api_key'] ); ?>"</code>
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
                        <code id="curl-fallback">curl -H "Authorization: Bearer <?php echo esc_attr( $auth_settings['bearer_token'] ); ?>" "<?php echo esc_url( home_url( '/?slymetrics=1' ) ); ?>"</code>
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
    metrics_path: '/slymetrics/metrics'
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
    metrics_path: '/wp-json/slymetrics/v1/metrics'
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
      slymetrics: ['1']
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
                    top: 10px;
                    right: 10px;
                    z-index: 10;
                }
                .code-block code,
                .code-block pre {
                    padding-right: 80px;
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
            // Mark that we've flushed rules
            update_option( 'slymetrics_rewrite_rules_flushed', time() );
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
            if ( ! self::get_decrypted_option( 'slymetrics_auth_token' ) ) {
                self::set_encrypted_option( 'slymetrics_auth_token', self::generate_secure_token() );
            }
            if ( ! self::get_decrypted_option( 'slymetrics_api_key' ) ) {
                self::set_encrypted_option( 'slymetrics_api_key', self::generate_secure_token() );
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
            $env_key = getenv( 'SLYMETRICS_ENCRYPTION_KEY' );
            if ( $env_key !== false && ! empty( $env_key ) ) {
                return base64_decode( $env_key );
            }
            
            // Fallback to database storage
            $key = get_option( 'slymetrics_encryption_key' );
            if ( ! $key ) {
                $key = self::generate_secure_token();
                update_option( 'slymetrics_encryption_key', $key );
            }
            return base64_decode( $key );
        }

        /**
         * Check if encryption key is from environment variable.
         *
         * @return bool
         */
        private static function is_encryption_key_from_env() {
            $env_key = getenv( 'SLYMETRICS_ENCRYPTION_KEY' );
            return $env_key !== false && ! empty( $env_key );
        }

        /**
         * Encrypt data for secure storage.
         *
         * @param string $data
         * @return string
         */
        private static function encrypt_data( $data ) {
            if ( ! function_exists( 'openssl_encrypt' ) ) {
                // Fallback: simple base64 encode if OpenSSL not available
                return base64_encode( $data );
            }

            $key = self::get_encryption_key();
            $iv = openssl_random_pseudo_bytes( 16 );
            $encrypted = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );
            
            return base64_encode( $iv . $encrypted );
        }

        /**
         * Decrypt data from secure storage.
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
                if ( $option_name === 'slymetrics_auth_token' ) {
                    $env_token = getenv( 'SLYMETRICS_BEARER_TOKEN' );
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
                'bearer_token' => self::get_auth_token( 'slymetrics_auth_token' ),
                'api_key'      => self::get_auth_token( 'slymetrics_api_key' ),
            );
        }

        /**
         * Add rewrite rules for clean URLs.
         */
        public static function add_rewrite_rules() {
            // Main metrics endpoint - handle both with and without trailing slash
            add_rewrite_rule( '^slymetrics/metrics/?$', 'index.php?slymetrics_endpoint=metrics', 'top' );
            
            // Redirect slymetrics/metrics (without slash) to slymetrics/metrics/ (with slash) to avoid 301 redirects
            add_rewrite_rule( '^slymetrics/metrics$', 'index.php?slymetrics_endpoint=metrics', 'top' );
            
            // Short alternative
            add_rewrite_rule( '^slymetrics/?$', 'index.php?slymetrics_endpoint=metrics', 'top' );
            
            // Even shorter - handle both with and without trailing slash
            add_rewrite_rule( '^metrics/?$', 'index.php?slymetrics_endpoint=metrics', 'top' );
            add_rewrite_rule( '^metrics$', 'index.php?slymetrics_endpoint=metrics', 'top' );
        }

        /**
         * Add query vars.
         *
         * @param array $vars
         * @return array
         */
        public static function add_query_vars( $vars ) {
            $vars[] = 'slymetrics_endpoint';
            $vars[] = 'slymetrics'; // Also register the query parameter endpoint
            return $vars;
        }

        /**
         * Maybe flush rewrite rules if needed.
         */
        public static function maybe_flush_rewrite_rules() {
            // Check if we're in admin to avoid unnecessary checks on frontend
            if ( ! is_admin() ) {
                return;
            }
            
            // Check if our rewrite rules exist
            $rules = get_option( 'rewrite_rules' );
            
            // Look for our specific rule
            $has_our_rule = false;
            if ( is_array( $rules ) ) {
                foreach ( $rules as $pattern => $rewrite ) {
                    if ( ( strpos( $pattern, 'slymetrics/metrics' ) !== false || strpos( $pattern, '^metrics' ) !== false ) 
                         && strpos( $rewrite, 'slymetrics_endpoint=metrics' ) !== false ) {
                        $has_our_rule = true;
                        break;
                    }
                }
            }
            
            // If our rule is missing, add it and flush
            if ( ! $has_our_rule ) {
                self::add_rewrite_rules();
                flush_rewrite_rules();
                
                // Also update a flag to indicate we've flushed rules
                update_option( 'slymetrics_rewrite_rules_flushed', time() );
            }
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
                // WordPress uploads directory
                $upload_dir = wp_upload_dir();
                if ( isset( $upload_dir['basedir'] ) && is_dir( $upload_dir['basedir'] ) ) {
                    $uploads_size = self::get_directory_size_safe( $upload_dir['basedir'] );
                    $sizes['uploads'] = round( $uploads_size / (1024 * 1024), 2 );
                }
                
                // Themes directory
                $themes_dir = get_theme_root();
                if ( is_dir( $themes_dir ) ) {
                    $themes_size = self::get_directory_size_safe( $themes_dir );
                    $sizes['themes'] = round( $themes_size / (1024 * 1024), 2 );
                }
                
                // Plugins directory
                $plugins_dir = WP_PLUGIN_DIR;
                if ( is_dir( $plugins_dir ) ) {
                    $plugins_size = self::get_directory_size_safe( $plugins_dir );
                    $sizes['plugins'] = round( $plugins_size / (1024 * 1024), 2 );
                }
                
                // Calculate total
                $total_size = array_sum( $sizes );
                $sizes['total'] = round( $total_size, 2 );
                
            } catch ( Exception $e ) {
                // Return empty array on error
            }
            
            return $sizes;
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
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ( $iterator as $file ) {
                    if ( $file->isFile() && $file->isReadable() ) {
                        $size += $file->getSize();
                    }
                }
                
            } catch ( Exception $e ) {
                // Fallback: manual directory traversal
                $files = glob( rtrim( $directory, '/' ) . '/*', GLOB_MARK );
                if ( is_array( $files ) ) {
                    foreach ( $files as $file ) {
                        if ( is_file( $file ) ) {
                            $size += filesize( $file );
                        } elseif ( is_dir( $file ) ) {
                            $size += self::get_directory_size_safe( $file );
                        }
                    }
                }
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
                // Use enhanced health checks directly for more reliable results
                return self::get_enhanced_health_checks();
                
            } catch ( Exception $e ) {
                return array();
            }
        }
        
        /**
         * Get WordPress health tests using the actual WordPress Site Health system.
         *
         * @return array Health test results
         */
        private static function get_wordpress_health_tests() {
            try {
                // Load WordPress Site Health if available (WordPress 5.2+)
                if ( ! class_exists( 'WP_Site_Health' ) ) {
                    if ( file_exists( ABSPATH . 'wp-admin/includes/class-wp-site-health.php' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
                    } else {
                        return array();
                    }
                }
                
                if ( ! class_exists( 'WP_Site_Health' ) ) {
                    return array();
                }
                
                $site_health = new WP_Site_Health();
                
                // Get both direct and async tests
                $tests = array();
                
                // Direct tests
                if ( method_exists( $site_health, 'get_tests' ) ) {
                    $all_tests = $site_health->get_tests();
                    if ( isset( $all_tests['direct'] ) && is_array( $all_tests['direct'] ) ) {
                        foreach ( $all_tests['direct'] as $test_name => $test_data ) {
                            if ( isset( $test_data['test'] ) && is_callable( $test_data['test'] ) ) {
                                try {
                                    $result = call_user_func( $test_data['test'] );
                                    if ( is_array( $result ) && isset( $result['status'] ) ) {
                                        $tests[] = $result;
                                    }
                                } catch ( Exception $e ) {
                                    // Skip failed tests
                                }
                            }
                        }
                    }
                }
                
                // Count results by category
                $results = array(
                    'good' => 0,
                    'recommended' => 0,
                    'critical' => 0,
                    'security' => 0,
                    'performance' => 0,
                    'total_failed' => 0
                );
                
                foreach ( $tests as $test ) {
                    if ( isset( $test['status'] ) ) {
                        switch ( $test['status'] ) {
                            case 'good':
                                $results['good']++;
                                break;
                            case 'recommended':
                                $results['recommended']++;
                                $results['total_failed']++;
                                break;
                            case 'critical':
                                $results['critical']++;
                                $results['total_failed']++;
                                break;
                        }
                        
                        // Categorize by test type
                        if ( isset( $test['test'] ) && is_string( $test['test'] ) ) {
                            $test_name = strtolower( $test['test'] );
                            if ( strpos( $test_name, 'security' ) !== false || 
                                 strpos( $test_name, 'debug' ) !== false || 
                                 strpos( $test_name, 'file_editing' ) !== false ||
                                 strpos( $test_name, 'https' ) !== false ) {
                                $results['security']++;
                            } elseif ( strpos( $test_name, 'performance' ) !== false ||
                                      strpos( $test_name, 'php' ) !== false ||
                                      strpos( $test_name, 'memory' ) !== false ) {
                                $results['performance']++;
                            }
                        }
                    }
                }
                
                return $results;
                
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
                'good' => 0,
                'recommended' => 0,
                'critical' => 0,
                'security' => 0,
                'performance' => 0,
                'total_failed' => 0
            );
            
            foreach ( $details as $detail ) {
                switch ( $detail['status'] ) {
                    case 'good':
                        $results['good']++;
                        break;
                    case 'recommended':
                        $results['recommended']++;
                        break;
                    case 'critical':
                        $results['critical']++;
                        break;
                }
                
                // Count failed tests by category (not all tests, just failed ones)
                if ( $detail['category'] === 'security' && in_array( $detail['status'], array( 'recommended', 'critical' ), true ) ) {
                    $results['security']++;
                } elseif ( $detail['category'] === 'performance' && in_array( $detail['status'], array( 'recommended', 'critical' ), true ) ) {
                    $results['performance']++;
                }
            }
            
            // Total failed is critical + recommended
            $results['total_failed'] = $results['critical'] + $results['recommended'];
            
            return $results;
        }

        /**
         * Get detailed Site Health check results with individual test information.
         *
         * @return array Detailed health check results with test names and descriptions
         */
        private static function get_health_check_details() {
            try {
                // First try WordPress built-in health checks
                if ( class_exists( 'WP_Site_Health' ) ) {
                    // Get enhanced details which includes both WP and custom checks
                    return self::get_enhanced_health_check_details();
                }
                
                // Fallback to enhanced health checks
                return self::get_enhanced_health_check_details();
                
            } catch ( Exception $e ) {
                return array();
            }
        }
        
        /**
         * Enhanced health check details with specific test information.
         *
         * @return array Enhanced health check details
         */
        private static function get_enhanced_health_check_details() {
            $details = array();
            
            try {
                // File editing check
                if ( ! defined( 'DISALLOW_FILE_EDIT' ) || ! DISALLOW_FILE_EDIT ) {
                    $details[] = array(
                        'test' => 'file_editing',
                        'status' => 'recommended',
                        'category' => 'security',
                        'description' => 'File editing (DISALLOW_FILE_EDIT) should be disabled in production environments'
                    );
                } else {
                    $details[] = array(
                        'test' => 'file_editing',
                        'status' => 'good',
                        'category' => 'security',
                        'description' => 'File editing is properly disabled'
                    );
                }
                
                // Debug mode check
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    $details[] = array(
                        'test' => 'debug_mode',
                        'status' => 'recommended',
                        'category' => 'security',
                        'description' => 'Debug mode (WP_DEBUG) should be disabled in production'
                    );
                } else {
                    $details[] = array(
                        'test' => 'debug_mode',
                        'status' => 'good',
                        'category' => 'security',
                        'description' => 'Debug mode is properly disabled'
                    );
                }
                
                // Plugin updates check
                $updates = get_site_transient( 'update_plugins' );
                $updates_available = isset( $updates->response ) && is_array( $updates->response ) ? count( $updates->response ) : 0;
                
                if ( $updates_available > 0 ) {
                    $details[] = array(
                        'test' => 'plugin_updates',
                        'status' => 'recommended',
                        'category' => 'security',
                        'description' => $updates_available . ' plugin updates available'
                    );
                } else {
                    $details[] = array(
                        'test' => 'plugin_updates',
                        'status' => 'good',
                        'category' => 'security',
                        'description' => 'All plugins are up to date'
                    );
                }
                
                // PHP version check
                if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
                    $details[] = array(
                        'test' => 'php_version',
                        'status' => 'critical',
                        'category' => 'performance',
                        'description' => 'PHP version ' . PHP_VERSION . ' is outdated and unsupported'
                    );
                } elseif ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
                    $details[] = array(
                        'test' => 'php_version',
                        'status' => 'recommended',
                        'category' => 'performance',
                        'description' => 'PHP version ' . PHP_VERSION . ' should be updated to 8.0+'
                    );
                } else {
                    $details[] = array(
                        'test' => 'php_version',
                        'status' => 'good',
                        'category' => 'performance',
                        'description' => 'PHP version ' . PHP_VERSION . ' is current'
                    );
                }
                
                // Memory limit check
                $memory_limit = ini_get( 'memory_limit' );
                $memory_bytes = self::convert_to_bytes( $memory_limit );
                
                if ( $memory_bytes < 128 * 1024 * 1024 ) { // Less than 128MB
                    $details[] = array(
                        'test' => 'php_memory_limit',
                        'status' => 'critical',
                        'category' => 'performance',
                        'description' => 'Memory limit ' . $memory_limit . ' is too low'
                    );
                } elseif ( $memory_bytes < 256 * 1024 * 1024 ) { // Less than 256MB
                    $details[] = array(
                        'test' => 'php_memory_limit',
                        'status' => 'recommended',
                        'category' => 'performance',
                        'description' => 'Memory limit ' . $memory_limit . ' could be increased'
                    );
                } else {
                    $details[] = array(
                        'test' => 'php_memory_limit',
                        'status' => 'good',
                        'category' => 'performance',
                        'description' => 'Memory limit ' . $memory_limit . ' is adequate'
                    );
                }
                
                // Database connection check
                global $wpdb;
                if ( $wpdb->last_error ) {
                    $details[] = array(
                        'test' => 'database_connection',
                        'status' => 'critical',
                        'category' => 'general',
                        'description' => 'Database connection has errors'
                    );
                } else {
                    $details[] = array(
                        'test' => 'database_connection',
                        'status' => 'good',
                        'category' => 'general',
                        'description' => 'Database connection is working properly'
                    );
                }
                
                // HTTPS check
                if ( is_ssl() ) {
                    $details[] = array(
                        'test' => 'https_status',
                        'status' => 'good',
                        'category' => 'security',
                        'description' => 'Site is using HTTPS'
                    );
                } else {
                    $details[] = array(
                        'test' => 'https_status',
                        'status' => 'recommended',
                        'category' => 'security',
                        'description' => 'Site should use HTTPS for better security'
                    );
                }
                
            } catch ( Exception $e ) {
                // Return what we have so far
            }
            
            return $details;
        }
    }

    // Initialize the plugin
    SlyMetrics_Plugin::init();
}
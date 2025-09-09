=== Prometheus Metrics ===
Contributors: timonf
Tags: prometheus, metrics, monitoring, observability, performance
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0
Requires PHP: 7.4
License: MIT
License URI: https://opensource.org/licenses/MIT

A comprehensive WordPress plugin that exports WordPress metrics in Prometheus format for monitoring and observability.

== Description ==

The Prometheus Metrics WordPress plugin is a powerful monitoring plugin that exports comprehensive WordPress metrics in Prometheus format. Perfect for DevOps teams and system administrators who want to monitor their WordPress sites using modern observability tools.

**Key Features:**

* **Secure Authentication** - Multiple authentication methods with encrypted token storage
* **Comprehensive Metrics** - WordPress users, posts, pages, plugins, themes, comments, categories, tags, and media
* **Advanced Monitoring** - WordPress version tracking, autoload performance, PHP configuration, database size
* **Site Health Integration** - WordPress Site Health API integration for security and performance monitoring
* **Directory Size Monitoring** - Track uploads, themes, and plugins directory sizes
* **REST API Integration** - Uses native WordPress REST API
* **Caching** - Built-in metrics caching to reduce database load
* **Environment Variable Support** - Enhanced security with external encryption key management
* **Admin Interface** - User-friendly settings page with token management
* **Multi-Site Support** - All metrics include site labels for multi-site filtering

**Available Metrics:**

* User counts per role
* Post and page statistics by status
* Plugin and theme information
* Comment statistics
* WordPress version and update status
* Database and directory sizes
* PHP configuration details
* Site health check results
* And much more...

**Authentication Methods:**

1. Bearer Token (Recommended)
2. API Key (URL Parameter)
3. WordPress Administrator (Automatic access for logged-in admins)

**Security Features:**

* AES-256-CBC encryption for token storage
* Environment variable support for encryption keys
* Secure random token generation
* Multiple fallback authentication methods

== Installation ==

1. In Wordpress: Install the plugin in the plugin section. Manual: Download and upload the plugin files to the `/wp-content/plugins/prometheus-metrics/` directory
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to 'Settings' → 'Prometheus Metrics' to configure authentication tokens
4. Copy your Bearer Token or API Key for use in your Prometheus configuration
5. Configure your Prometheus server to scrape the metrics endpoint

For detailed information take a look on the Github Page https://github.com/slydlake/wordpress-prometheus-metrics

**Prometheus Configuration Example:**

```yaml
scrape_configs:
  - job_name: 'wordpress'
    static_configs:
      - targets: ['yoursite.com']
    metrics_path: '/prometheus/metrics'
    authorization:
      type: Bearer
      credentials: 'your_bearer_token_here'
    scrape_interval: 60s
```

== Frequently Asked Questions ==

= What endpoints are available for metrics? =

The plugin provides multiple endpoint options:

* Primary: `/prometheus/metrics` (requires permalink support)
* REST API: `/wp-json/wp-prometheus/v1/metrics`
* Fallback: `/index.php?rest_route=/wp-prometheus/v1/metrics`
* Query parameter: `/?wp_prometheus_metrics=1`

= Do I need to configure Apache or Nginx? =

No! The plugin works out-of-the-box without requiring server configuration changes. Fallback URLs are automatically provided if rewrites don't work.

= How secure is the authentication? =

Very secure. All tokens are encrypted using AES-256-CBC with unique initialization vectors. You can also use environment variables for the encryption key in production.

= What metrics are exported? =

The plugin exports comprehensive metrics including user counts, post/page statistics, plugin/theme information, database sizes, PHP configuration, site health data, and much more. All metrics include site labels for multi-site environments.

= Can I use this with multi-site WordPress? =

Yes! All metrics include site labels, making it perfect for monitoring multi-site WordPress installations.

= What are the system requirements? =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* OpenSSL PHP extension (recommended for encryption)
* WordPress administrator access for configuration

== Screenshots ==

1. Admin settings page showing token configuration and security options
2. Example Prometheus metrics output in plain text format
3. Grafana dashboard displaying WordPress metrics and health status

== Changelog ==

= 1.0.0 =
* Initial release
* Comprehensive WordPress metrics export
* Multiple authentication methods
* Encrypted token storage
* Site health integration
* Directory size monitoring
* Multi-site support
* Admin interface for token management
* Environment variable support for enhanced security
* Built-in caching for performance
* Multiple endpoint options for compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release of Prometheus Metrics Wordpress Plugin. Install to start monitoring your WordPress site with Prometheus and Grafana.

== Additional Information ==

**GitHub Repository:** https://github.com/slydlake/wordpress-prometheus-metrics

**Grafana Dashboard:** Included in the plugin package for comprehensive WordPress monitoring.

**Support:** For issues and feature requests, please visit the GitHub repository.

**Security:** For enhanced security in production, use environment variables for encryption keys and implement network-level access controls.

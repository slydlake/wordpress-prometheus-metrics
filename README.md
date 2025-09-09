# WordPress Prometheus Metrics Plugin

A comprehensive WordPress plugin that exports WordPress metrics in Prometheus format for monitoring and observability.

Wordpress Plugin page: [https://wordpress.org/plugins/prometheus-metrics](https://wordpress.org/plugins/prometheus-metrics/)

## 🚀 Features

- **Secure Authentication**: Multiple authentication methods with encrypted token storage
- **Comprehensive Metrics**: WordPress users, posts, pages, plugins, themes, comments, categories, tags, and media
- **Advanced Monitoring**: WordPress version tracking, autoload performance, PHP configuration, database size
- **Site Health Integration**: WordPress Site Health API integration for security and performance monitoring
- **Directory Size Monitoring**: Track uploads, themes, and plugins directory sizes
- **REST API Integration**: Uses native WordPress REST API
- **Caching**: Built-in metrics caching to reduce database load
- **Environment Variable Support**: Enhanced security with external encryption key management
- **Admin Interface**: User-friendly settings page with token management
- **Multi-Site Support**: All metrics include site labels for multi-site filtering

## 📊 Metrics Overview

| Metric Name | Type | Description | Labels |
|-------------|------|-------------|---------|
| `wp_users` | counter | Number of users per role | `wp_site`, `role` |
| `wp_posts` | counter | Number of posts by status | `wp_site`, `status`: `published`, `draft`, `all` |
| `wp_pages` | counter | Number of pages by status | `wp_site`, `status`: `published`, `draft`, `all` |
| `wp_plugins` | counter | Active and inactive plugins | `wp_site`, `status`: `active`, `inactive`, `all` |
| `wp_plugins_update` | counter | Plugin update status | `wp_site`, `status`: `available`, `uptodate` |
| `wp_themes` | counter | Number of installed themes | `wp_site`, `type`: `child`, `parent` |
| `wp_comments` | counter | Number of comments by status | `wp_site`, `status`: `approved`, `spam`, `trash`, `post_trashed`, `moderated` |
| `wp_categories` | counter | Total number of categories | `wp_site` |
| `wp_media` | counter | Total number of media items | `wp_site` |
| `wp_tags` | counter | Total number of tags | `wp_site` |
| `wp_version` | gauge | WordPress version information | `wp_site`, `version`, `update_available` |
| `wp_autoload_count` | gauge | Number of autoloaded options | `wp_site` |
| `wp_autoload_size` | gauge | Size of autoloaded options in KB | `wp_site` |
| `wp_autoload_transients` | gauge | Number of autoloaded transients | `wp_site` |
| `wp_php_info` | gauge | PHP configuration information | `wp_site`, `type`, `label` |
| `wp_database_size` | gauge | Database size in MB | `wp_site` |
| `wp_directory_size` | gauge | Directory sizes in MB | `wp_site`, `directory`: `uploads`, `themes`, `plugins`, `total` |
| `wp_health_check` | gauge | Site health check results | `wp_site`, `category`: `critical`, `recommended`, `good`, `security`, `performance`, `total_failed` |

## 🔧 Installation

1. Download the plugin files
2. Upload to your WordPress `wp-content/plugins/` directory
3. Activate the plugin through the WordPress admin interface
4. Configure authentication tokens in **Settings** → **Prometheus Metrics**

## � API Endpoints

The plugin provides multiple endpoint options to ensure compatibility across different server configurations:

### Primary Endpoint (Requires Permalink Support)
```
/prometheus/metrics
```
**Note:** Requires WordPress permalink support (Settings → Permalinks → Select any option except "Plain").

### Fallback Endpoints (No Permalink Support Required)
```
/index.php?rest_route=/wp-prometheus/v1/metrics    # REST API fallback
/?wp_prometheus_metrics=1                          # Query parameter fallback
/wp-json/wp-prometheus/v1/metrics                  # Standard REST API
```

**Note:** If permalink support is not available, use the fallback URLs above.

## �🔐 Authentication Methods

The plugin supports multiple authentication methods:

### 1. Bearer Token (Recommended)
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     http://yoursite.com/prometheus/metrics
```

### 2. API Key (URL Parameter)
```bash
curl "http://yoursite.com/prometheus/metrics?api_key=YOUR_API_KEY"
```

### 3. WordPress Administrator
- Automatic access for logged-in WordPress administrators

## 🛡️ Security Features

### Encrypted Token Storage
- All authentication tokens are encrypted using **AES-256-CBC**
- Unique initialization vectors (IV) for each encryption
- Secure random token generation (64-character hex strings)

### Environment Variable Support
For enhanced security, set the encryption key via environment variable:

```bash
# Generate a secure key
export WP_PROMETHEUS_ENCRYPTION_KEY=$(openssl rand -base64 32)
```

When using environment variables:
- Encryption key is not stored in the database
- Token regeneration via web interface is disabled
- Enhanced security indicator in admin interface

## ⚙️ Configuration

### Basic Setup
1. Navigate to **Settings** → **Prometheus Metrics**
2. Copy the Bearer Token or API Key
3. Configure your Prometheus scraper

### Prometheus Configuration

#### Primary Configuration (requires permalinks)
```yaml
# prometheus.yml
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

#### REST API Fallback Configuration
```yaml
# prometheus.yml (REST API fallback)
scrape_configs:
  - job_name: 'wordpress'
    static_configs:
      - targets: ['yoursite.com']
    metrics_path: '/index.php'
    params:
      rest_route: ['/wp-prometheus/v1/metrics']
    authorization:
      type: Bearer
      credentials: 'your_bearer_token_here'
    scrape_interval: 60s
```

#### Query Parameter Fallback Configuration
```yaml
# prometheus.yml (query parameter fallback)
scrape_configs:
  - job_name: 'wordpress'
    static_configs:
      - targets: ['yoursite.com']
    metrics_path: '/'
    params:
      wp_prometheus_metrics: ['1']
    authorization:
      type: Bearer
      credentials: 'your_bearer_token_here'
    scrape_interval: 60s
```

### Docker Environment
```bash
docker run -e WP_PROMETHEUS_ENCRYPTION_KEY="$(openssl rand -base64 32)" \
           your-wordpress-image
```

### Environment Variables
```bash
# .env file
WP_PROMETHEUS_ENCRYPTION_KEY=base64_encoded_32_byte_key
```

### Container Environments

#### Docker Compose
```yaml
version: '3.8'
services:
  wordpress:
    image: wordpress:latest
    environment:
      - WP_PROMETHEUS_ENCRYPTION_KEY=${WP_PROMETHEUS_ENCRYPTION_KEY}
    volumes:
      - ./wordpress-exporter-prometheus.php:/var/www/html/wp-content/plugins/wordpress-prometheus-metrics/wordpress-exporter-prometheus.php
```

#### Kubernetes Deployment
```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: wordpress
spec:
  template:
    spec:
      containers:
      - name: wordpress
        image: wordpress:latest
        env:
        - name: WP_PROMETHEUS_ENCRYPTION_KEY
          valueFrom:
            secretKeyRef:
              name: wordpress-secrets
              key: prometheus-encryption-key
```

📖 **More Examples:** [See GitHub Documentation](https://github.com/slydlake/wordpress-prometheus-metrics#security-features)

## 🔍 API Endpoint

**Primary Endpoint:** `/prometheus/metrics` (requires permalinks to be enabled)

**Fallback Endpoints:**
- `/wp-json/wp-prometheus/v1/metrics` (WordPress REST API, requires permalinks)
- `/index.php?rest_route=/wp-prometheus/v1/metrics` (Always works, even with plain permalinks)
- `/?wp_prometheus_metrics=1` (Query parameter method, always works)

**Method:** `GET`

**Content-Type:** `text/plain; charset=UTF-8`

**Response Format:** Prometheus metrics format

**Troubleshooting:** If you get 404 errors with the primary endpoint, use the fallback URL with `index.php?rest_route=`

## 📈 Example Metrics Output

```
# HELP wp_users Number of users per role.
# TYPE wp_users counter
wp_users{wp_site="My WordPress Site",role="administrator"} 2
wp_users{wp_site="My WordPress Site",role="editor"} 5
wp_users{wp_site="My WordPress Site",role="total"} 42

# HELP wp_posts Number of posts.
# TYPE wp_posts counter
wp_posts{wp_site="My WordPress Site",status="published"} 156
wp_posts{wp_site="My WordPress Site",status="draft"} 8
wp_posts{wp_site="My WordPress Site",status="all"} 164

# HELP wp_plugins Number of active and inactive plugins.
# TYPE wp_plugins counter
wp_plugins{wp_site="My WordPress Site",status="active"} 12
wp_plugins{wp_site="My WordPress Site",status="inactive"} 3
wp_plugins{wp_site="My WordPress Site",status="all"} 15

# HELP wp_version WordPress version information.
# TYPE wp_version gauge
wp_version{wp_site="My WordPress Site",version="6.8.2",update_available="0"} 1

# HELP wp_autoload_count Number of autoloaded options.
# TYPE wp_autoload_count gauge
wp_autoload_count{wp_site="My WordPress Site"} 150

# HELP wp_php_info PHP configuration information.
# TYPE wp_php_info gauge
wp_php_info{wp_site="My WordPress Site",type="version",label="8.2.29"} 80229
wp_php_info{wp_site="My WordPress Site",type="memory_limit",label="256M"} 268435456

# HELP wp_database_size Database size in MB.
# TYPE wp_database_size gauge
wp_database_size{wp_site="My WordPress Site"} 4.93

# HELP wp_directory_size Directory sizes in MB.
# TYPE wp_directory_size gauge
wp_directory_size{wp_site="My WordPress Site",directory="uploads"} 15.6
wp_directory_size{wp_site="My WordPress Site",directory="themes"} 8.2
wp_directory_size{wp_site="My WordPress Site",directory="plugins"} 12.4

# HELP wp_health_check Site health check results.
# TYPE wp_health_check gauge
wp_health_check{wp_site="My WordPress Site",category="critical"} 0
wp_health_check{wp_site="My WordPress Site",category="recommended"} 3
wp_health_check{wp_site="My WordPress Site",category="good"} 7
wp_health_check{wp_site="My WordPress Site",category="security"} 2
wp_health_check{wp_site="My WordPress Site",category="performance"} 1
wp_health_check{wp_site="My WordPress Site",category="total_failed"} 3
```

## 🏗️ Technical Requirements

- **WordPress:** 5.0 or higher
- **PHP:** 7.4 or higher
- **PHP Extensions:** OpenSSL (recommended for encryption)
- **Permissions:** WordPress administrator access for configuration

## 🔧 Advanced Configuration

### No Apache Configuration Required

This plugin works out-of-the-box without requiring Apache configuration changes. If REST API rewrites don't work in your environment, the plugin automatically provides fallback URLs:

- **Fallback URL:** `/index.php?rest_route=/wp-prometheus/v1/metrics`
- **Query Parameter:** `/?wp_prometheus_metrics=1`

### Optional Apache Configuration (For Pretty URLs)

If you want to use the primary endpoints `/prometheus/metrics` or `/wp-json/wp-prometheus/v1/metrics`, ensure WordPress permalink support is enabled.

### Apache Configuration Requirements

For the plugin to work properly with pretty URLs, WordPress rewrites must be enabled in Apache. 

**Configuration File Location:** `/etc/apache2/sites-available/000-default.conf` (or your virtual host file)

**Required Configuration:**
```apache
<VirtualHost *:80>
  DocumentRoot /var/www/html

  <Directory /var/www/html>
    # Allow .htaccess and rewrites for WordPress
    AllowOverride All
    Require all granted
  </Directory>

  # Forward REST requests to index.php using server/vhost context
  <IfModule mod_rewrite.c>
    RewriteEngine On
    # Match request path starting with /wp-json and capture the rest
    RewriteCond %{REQUEST_URI} ^/wp-json(?:/(.*))?$
    # Rewrite to index.php with rest_route using capture group %1
    RewriteRule ^ /index.php?rest_route=/%1 [L,QSA]
  </IfModule>

  ErrorLog ${APACHE_LOG_DIR}/error.log
  CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

### Fallback Mode
If OpenSSL is not available, the plugin falls back to Base64 encoding for token storage.

## 🚨 Security Considerations

### Production Recommendations
1. **Use Environment Variables**: Set `WP_PROMETHEUS_ENCRYPTION_KEY` for production
2. **Secure Network**: Use HTTPS for all metric requests
3. **Access Control**: Restrict Prometheus server access to metrics endpoint
4. **Regular Token Rotation**: Regenerate tokens periodically
5. **Monitor Access**: Review WordPress access logs for suspicious activity

### Network Security (optional)

**Apache Configuration:** Edit your virtual host file (typically `/etc/apache2/sites-available/000-default.conf` or `/etc/apache2/sites-available/your-site.conf`)

```apache
# Example: Restrict access by IP (Apache)
<Location "/prometheus/metrics">
    Require ip 10.0.0.0/8
    Require ip 192.168.0.0/16
</Location>

<Location "/wp-json/wp-prometheus/v1/metrics">
    Require ip 10.0.0.0/8
    Require ip 192.168.0.0/16
</Location>
```

**Nginx Configuration:** Add to your server block in `/etc/nginx/sites-available/your-site`
```nginx
location /prometheus/metrics {
    allow 10.0.0.0/8;
    allow 192.168.0.0/16;
    deny all;
    try_files $uri $uri/ /index.php?$args;
}

location /wp-json/wp-prometheus/v1/metrics {
    allow 10.0.0.0/8;
    allow 192.168.0.0/16;
    deny all;
    try_files $uri $uri/ /index.php?$args;
}
```

## 🛠️ Troubleshooting

### Common Issues

**Metrics endpoint returns JSON instead of plain text:**
- Check if WordPress REST API is properly configured
- Verify the route registration

**Authentication fails:**
- Verify token is correctly copied (no extra spaces)
- Check if environment variable is properly set
- Ensure WordPress user has admin privileges
- CHeck if apache rewrite is working properly 

**Empty metrics:**
- Check WordPress database connectivity
- Verify plugin is activated
- Review WordPress error logs

### Debug Mode
Enable WordPress debug mode to see detailed error messages:
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## 📚 Development

### File Structure
```
wordpress-prometheus-metrics/
├── README.md
└── wordpress-exporter-prometheus.php
```

### Key Functions
- `build_metrics()`: Generates Prometheus metrics
- `check_auth()`: Handles authentication
- `encrypt_data()` / `decrypt_data()`: Token encryption
- `admin_page()`: Settings interface

## 📊 Grafana Dashboards

### WordPress Comprehensive Monitoring Dashboard

A complete Grafana dashboard that combines health monitoring, performance metrics, and content statistics.

**Dashboard File:** `grafana/wordpress-combined-dashboard.json`

**Features:**
- **Overview Stats:** Users, pages, posts, media, health issues
- **Health Monitoring:** Critical issues, recommendations, security & performance alerts
- **Database Metrics:** Database size, autoload performance, directory sizes
- **Configuration Table:** WordPress & PHP versions, memory limits, upload settings per site
- **Plugin Management:** Active/inactive status, update availability
- **Content Analysis:** Comment distribution, user roles
- **Activity Timeline:** Published content trends over time

**Import Instructions:**
1. Open Grafana → "+" → Import
2. Upload `wordpress-combined-dashboard.json`
3. Configure your Prometheus data source
4. Select your WordPress sites from the dropdown

**Panel Layout:**
- **Row 1 (6x4):** Core metrics - Users, Pages, Posts, Media, Critical Issues, Failed Tests
- **Row 2 (5):** Health details - Recommendations, Passed Tests, Security Issues, Performance Issues
- **Row 3 (8):** Database & Performance metrics as stat values
- **Row 4 (8):** WordPress & PHP configuration table
- **Row 5 (6+6):** Plugin status and updates
- **Row 6 (12+12):** Comment status and user role distributions
- **Row 7 (24):** Activity timeline showing content trends

The dashboard automatically filters by selected websites and provides comprehensive monitoring without excessive scrolling.

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## 📝 License

MIT License - see LICENSE file for details

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/slydlake/wordpress-prometheus-metrics/issues)
- **Documentation**: This README
- **WordPress Support**: Standard WordPress admin interface

---

**Author:** Timon Först  
**Repository:** https://github.com/slydlake/wordpress-prometheus-metrics
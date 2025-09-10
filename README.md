# WordPress Prometheus Metrics Plugin

A comprehensive WordPress plugin that exports WordPress metrics in Prometheus format for monitoring including Grafana Dashboard for multi sites.

Wordpress Plugin page: [https://wordpress.org/plugins/prometheus-metrics](https://wordpress.org/plugins/prometheus-metrics/)

![Grafana Dashboard](./prometheus-metrics/assets//screenshot-4.png)

## 🚀 Features

- **📊 Comprehensive Metrics**: WordPress content, users, plugins, themes, and system information
- **🔒 Secure Authentication**: Bearer token and API key authentication with AES-256-CBC encryption
- **🌐 Multiple Endpoints**: Clean URLs (`/prometheus/metrics`), REST API, and query parameter fallbacks
- **🏥 Health Monitoring**: Integration with WordPress Site Health API for detailed system checks
- **📈 Grafana Optimized**: Display-friendly metrics specifically designed for clean table visualizations
- **🐳 Container Ready**: Environment variable support for Docker, Kubernetes, and CI/CD pipelines
- **⚡ Performance Focused**: Optimized queries, caching, and minimal resource impact
- **🔄 Auto-Discovery**: Automatic endpoint detection and fallback mechanisms
- **📱 Admin Interface**: User-friendly settings page with token management and health status
- **🛡️ Production Ready**: Fallback modes, error handling, and comprehensive security features

### New in Version 1.0.1

- **🎯 Display Metrics**: Added Grafana-optimized metrics (`wp_php_version`, `wp_memory_limit_display`, etc.)
- **📋 Table Compatibility**: Simplified label structure for clean Grafana table displays
- **🔧 Enhanced Health Checks**: Detailed individual health check results with descriptions
- **📦 Plugin Update Tracking**: Fixed counting for inactive plugins with available updates
- **🔗 Clean URL Support**: WordPress Rewrite API integration for `/prometheus/metrics` endpoints

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
| `wp_health_check_detail` | gauge | Individual health check test results | `wp_site`, `test_name`, `status`, `category`, `description` |

## 🔧 Installation

1. Download the plugin files
2. Upload to your WordPress `wp-content/plugins/` directory
3. Activate the plugin through the WordPress admin interface
4. Configure authentication tokens in **Settings** → **Prometheus Metrics**

## 🌐 API Endpoints

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

## 🔐 Authentication Methods

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
For enhanced security and stable token configuration, set environment variables:

```bash
# Generate a secure encryption key
export WP_PROMETHEUS_ENCRYPTION_KEY=$(openssl rand -base64 32)

# Set a stable bearer token for Prometheus
export WP_PROMETHEUS_BEARER_TOKEN=$(openssl rand -hex 32)
```

**Environment Variables:**
- **`WP_PROMETHEUS_ENCRYPTION_KEY`**: Base64-encoded 32-byte encryption key for API key storage
- **`WP_PROMETHEUS_BEARER_TOKEN`**: Stable bearer token for Prometheus authentication (plain text, 64-character hex)

**When using environment variables:**
- Encryption key is not stored in the database
- Bearer token remains stable across container restarts
- Token regeneration via web interface is disabled for environment-managed tokens
- Enhanced security indicator in admin interface
- Perfect for containerized deployments and CI/CD pipelines

## ⚙️ Configuration

### Basic Setup
1. Navigate to **Settings** → **Prometheus Metrics**
2. Copy the Bearer Token or API Key
3. Configure your Prometheus scraper

![Wordpress Admin Page](./prometheus-metrics/assets//screenshot-1.png)

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
# With stable bearer token for Prometheus
docker run -e WP_PROMETHEUS_ENCRYPTION_KEY="$(openssl rand -base64 32)" \
           -e WP_PROMETHEUS_BEARER_TOKEN="your_stable_prometheus_token" \
           your-wordpress-image

# Generate new tokens
docker run -e WP_PROMETHEUS_ENCRYPTION_KEY="$(openssl rand -base64 32)" \
           -e WP_PROMETHEUS_BEARER_TOKEN="$(openssl rand -hex 32)" \
           your-wordpress-image
```

### Environment Variables
```bash
# .env file
WP_PROMETHEUS_ENCRYPTION_KEY=base64_encoded_32_byte_key
WP_PROMETHEUS_BEARER_TOKEN=your_stable_64_character_hex_token
```

**Benefits of using `WP_PROMETHEUS_BEARER_TOKEN`:**
- **Stable Configuration**: Token remains consistent across container restarts
- **Prometheus Integration**: No need to update Prometheus configuration when containers restart
- **CI/CD Friendly**: Tokens can be managed via secrets management systems
- **Production Ready**: Eliminates token rotation issues in production environments

### Container Environments

#### Docker Compose
```yaml
version: '3.8'
services:
  wordpress:
    image: wordpress:latest
    environment:
      - WP_PROMETHEUS_ENCRYPTION_KEY=${WP_PROMETHEUS_ENCRYPTION_KEY}
      - WP_PROMETHEUS_BEARER_TOKEN=${WP_PROMETHEUS_BEARER_TOKEN}
    volumes:
      - ./wordpress-exporter-prometheus.php:/var/www/html/wp-content/plugins/wordpress-prometheus-metrics/wordpress-exporter-prometheus.php

  # Example Prometheus configuration with stable token
  prometheus:
    image: prom/prometheus:latest
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
    # Use the same token in prometheus.yml:
    # credentials: '${WP_PROMETHEUS_BEARER_TOKEN}'
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
        - name: WP_PROMETHEUS_BEARER_TOKEN
          valueFrom:
            secretKeyRef:
              name: wordpress-secrets
              key: prometheus-bearer-token
---
# Example: Create secret with stable tokens
apiVersion: v1
kind: Secret
metadata:
  name: wordpress-secrets
type: Opaque
data:
  # Base64 encoded values
  prometheus-encryption-key: <base64-encoded-32-byte-key>
  prometheus-bearer-token: <base64-encoded-64-char-hex-token>
```

**Kubernetes Token Management:**
```bash
# Generate and create secret
kubectl create secret generic wordpress-secrets \
  --from-literal=prometheus-encryption-key="$(openssl rand -base64 32)" \
  --from-literal=prometheus-bearer-token="$(openssl rand -hex 32)"

# Use the same token in your Prometheus configuration
kubectl get secret wordpress-secrets -o jsonpath='{.data.prometheus-bearer-token}' | base64 -d
```

## � Available Metrics

### Core WordPress Metrics

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wp_users` | counter | Number of users per role | `wp_site`, `role` | `wp_users{role="administrator"} 1` |
| `wp_posts` | counter | Number of posts by status | `wp_site`, `status` | `wp_posts{status="published"} 5` |
| `wp_pages` | counter | Number of pages by status | `wp_site`, `status` | `wp_pages{status="published"} 3` |
| `wp_plugins` | counter | Number of plugins by status | `wp_site`, `status` | `wp_plugins{status="active"} 12` |
| `wp_plugins_update` | counter | Plugin update status | `wp_site`, `status` | `wp_plugins_update{status="available"} 2` |
| `wp_themes` | counter | Number of installed themes | `wp_site`, `type` | `wp_themes{type="parent"} 5` |
| `wp_comments` | counter | Number of comments by status | `wp_site`, `status` | `wp_comments{status="approved"} 45` |
| `wp_categories` | counter | Total number of categories | `wp_site` | `wp_categories{} 8` |
| `wp_media` | counter | Total number of media items | `wp_site` | `wp_media{} 150` |
| `wp_tags` | counter | Total number of tags | `wp_site` | `wp_tags{} 25` |

### System Information Metrics

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wp_version` | gauge | WordPress version information | `wp_site`, `version`, `update_available` | `wp_version{version="6.8.2"} 1` |
| `wp_php_info` | gauge | PHP configuration details | `wp_site`, `type`, `label` | `wp_php_info{type="version",label="8.2.29"} 80229` |
| `wp_database_size` | gauge | Database size in MB | `wp_site` | `wp_database_size{} 125.45` |
| `wp_directory_size` | gauge | Directory sizes in MB | `wp_site`, `directory` | `wp_directory_size{directory="uploads"} 512.8` |

### Display-Friendly Metrics (Grafana Optimized)

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wp_php_version` | gauge | PHP version (display format) | `wp_site`, `php_version` | `wp_php_version{php_version="8.2.29"} 1` |
| `wp_memory_limit_display` | gauge | Memory limit (display format) | `wp_site`, `memory_limit` | `wp_memory_limit_display{memory_limit="256M"} 1` |
| `wp_upload_max_display` | gauge | Upload max size (display format) | `wp_site`, `upload_max` | `wp_upload_max_display{upload_max="64M"} 1` |
| `wp_post_max_display` | gauge | Post max size (display format) | `wp_site`, `post_max` | `wp_post_max_display{post_max="64M"} 1` |
| `wp_exec_time_display` | gauge | Max execution time (display format) | `wp_site`, `exec_time` | `wp_exec_time_display{exec_time="30"} 1` |

### Performance & Health Metrics

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wp_autoload_count` | gauge | Number of autoloaded options | `wp_site` | `wp_autoload_count{} 245` |
| `wp_autoload_size` | gauge | Size of autoloaded options in KB | `wp_site` | `wp_autoload_size{} 156.2` |
| `wp_autoload_transients` | gauge | Number of autoloaded transients | `wp_site` | `wp_autoload_transients{} 12` |

### Site Health Metrics

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wp_health_check` | gauge | Site health check summary | `wp_site`, `category` | `wp_health_check{category="critical"} 0` |
| `wp_health_check_detail` | gauge | Individual health check results | `wp_site`, `test_name`, `status`, `category`, `description` | `wp_health_check_detail{test_name="php_version",status="good"} 1` |

### Label Descriptions

- **`wp_site`**: WordPress site name/title
- **`role`**: User role (administrator, editor, author, etc.)
- **`status`**: Status type (published, draft, active, inactive, etc.)
- **`type`**: Category type (parent, child, version, etc.)
- **`directory`**: Directory name (uploads, themes, plugins, total)
- **`category`**: Health check category (critical, recommended, good, security, performance)
- **`test_name`**: Individual health check test identifier
- **`description`**: Human-readable description of the health check

## 📈 Example Metrics Output

```
curl -H "Authorization: Bearer f5634d6a966856848e2f3f4a139e534b844805f7561d86642adb19060719e95d" "http://192.168.178.21:30107/prometheus/metrics"
# HELP wp_users Number of users per role.
# TYPE wp_users counter
wp_users{wp_site="New wordpress blog",role="administrator"} 1
wp_users{wp_site="New wordpress blog",role="contributor"} 1
wp_users{wp_site="New wordpress blog",role="none"} 0
wp_users{wp_site="New wordpress blog",role="total"} 2
# HELP wp_posts number of posts.
# TYPE wp_posts counter
wp_posts{wp_site="New wordpress blog",status="published"} 1
wp_posts{wp_site="New wordpress blog",status="draft"} 0
wp_posts{wp_site="New wordpress blog",status="all"} 1
# HELP wp_pages number of pages.
# TYPE wp_pages counter
wp_pages{wp_site="New wordpress blog",status="published"} 1
wp_pages{wp_site="New wordpress blog",status="draft"} 1
wp_pages{wp_site="New wordpress blog",status="all"} 2
# HELP wp_plugins number of active and inactive plugins.
# TYPE wp_plugins counter
wp_plugins{wp_site="New wordpress blog",status="active"} 3
wp_plugins{wp_site="New wordpress blog",status="inactive"} 2
wp_plugins{wp_site="New wordpress blog",status="all"} 5
# HELP wp_plugins_update Plugin update status.
# TYPE wp_plugins_update counter
wp_plugins_update{wp_site="New wordpress blog",status="available"} 0
wp_plugins_update{wp_site="New wordpress blog",status="uptodate"} 3
# HELP wp_themes Number of installed themes.
# TYPE wp_themes counter
wp_themes{wp_site="New wordpress blog",type="child"} 0
wp_themes{wp_site="New wordpress blog",type="parent"} 3
# HELP wp_comments Total number of comments by status.
# TYPE wp_comments counter
wp_comments{wp_site="New wordpress blog",status="approved"} 1
wp_comments{wp_site="New wordpress blog",status="spam"} 0
wp_comments{wp_site="New wordpress blog",status="trash"} 0
wp_comments{wp_site="New wordpress blog",status="post_trashed"} 0
wp_comments{wp_site="New wordpress blog",status="all"} 1
wp_comments{wp_site="New wordpress blog",status="moderated"} 0
# HELP wp_categories Total number of categories.
# TYPE wp_categories counter
wp_categories{wp_site="New wordpress blog"} 1
# HELP wp_media Total number of media items.
# TYPE wp_media counter
wp_media{wp_site="New wordpress blog"} 0
# HELP wp_tags Total number of tags.
# TYPE wp_tags counter
wp_tags{wp_site="New wordpress blog"} 0
# HELP wp_version WordPress version information.
# TYPE wp_version gauge
wp_version{wp_site="New wordpress blog",version="6.8.2",update_available="0"} 1
# HELP wp_autoload_count Number of autoloaded options.
# TYPE wp_autoload_count gauge
wp_autoload_count{wp_site="New wordpress blog"} 0
# HELP wp_autoload_size Size of autoloaded options in KB.
# TYPE wp_autoload_size gauge
wp_autoload_size{wp_site="New wordpress blog"} 0
# HELP wp_autoload_transients Number of autoloaded transients.
# TYPE wp_autoload_transients gauge
wp_autoload_transients{wp_site="New wordpress blog"} 0
# HELP wp_php_info PHP configuration information.
# TYPE wp_php_info gauge
wp_php_info{wp_site="New wordpress blog",type="version",label="8.2.29"} 80229
wp_php_info{wp_site="New wordpress blog",type="major_version",label="8"} 8
wp_php_info{wp_site="New wordpress blog",type="minor_version",label="2"} 2
wp_php_info{wp_site="New wordpress blog",type="release_version",label="29"} 29
wp_php_info{wp_site="New wordpress blog",type="max_input_vars",label="1000"} 1000
wp_php_info{wp_site="New wordpress blog",type="max_execution_time",label="30"} 30
wp_php_info{wp_site="New wordpress blog",type="memory_limit",label="256M"} 268435456
wp_php_info{wp_site="New wordpress blog",type="max_input_time",label="-1"} 1
wp_php_info{wp_site="New wordpress blog",type="upload_max_filesize",label="64M"} 67108864
wp_php_info{wp_site="New wordpress blog",type="post_max_size",label="64M"} 67108864
# HELP wp_php_version PHP version information (display-friendly).
# TYPE wp_php_version gauge
wp_php_version{wp_site="New wordpress blog",php_version="8.2.29"} 1
# HELP wp_memory_limit_display Memory limit in display format.
# TYPE wp_memory_limit_display gauge
wp_memory_limit_display{wp_site="New wordpress blog",memory_limit="256M"} 1
# HELP wp_upload_max_display Upload max filesize in display format.
# TYPE wp_upload_max_display gauge
wp_upload_max_display{wp_site="New wordpress blog",upload_max="64M"} 1
# HELP wp_post_max_display Post max size in display format.
# TYPE wp_post_max_display gauge
wp_post_max_display{wp_site="New wordpress blog",post_max="64M"} 1
# HELP wp_exec_time_display Max execution time in display format.
# TYPE wp_exec_time_display gauge
wp_exec_time_display{wp_site="New wordpress blog",exec_time="30"} 1
# HELP wp_database_size Database size in MB.
# TYPE wp_database_size gauge
wp_database_size{wp_site="New wordpress blog"} 0.86
# HELP wp_directory_size Directory sizes in MB.
# TYPE wp_directory_size gauge
wp_directory_size{wp_site="New wordpress blog",directory="uploads"} 0
wp_directory_size{wp_site="New wordpress blog",directory="themes"} 13.56
wp_directory_size{wp_site="New wordpress blog",directory="plugins"} 10.48
wp_directory_size{wp_site="New wordpress blog",directory="total"} 24.05
# HELP wp_health_check Site health check results.
# TYPE wp_health_check gauge
wp_health_check{wp_site="New wordpress blog",category="critical"} 0
wp_health_check{wp_site="New wordpress blog",category="recommended"} 3
wp_health_check{wp_site="New wordpress blog",category="good"} 4
wp_health_check{wp_site="New wordpress blog",category="security"} 3
wp_health_check{wp_site="New wordpress blog",category="performance"} 0
wp_health_check{wp_site="New wordpress blog",category="total_failed"} 3
# HELP wp_health_check_detail Individual health check test results.
# TYPE wp_health_check_detail gauge
wp_health_check_detail{wp_site="New wordpress blog",test_name="file_editing",status="recommended",category="security",description="File editing should be disabled in production environments"} 0
wp_health_check_detail{wp_site="New wordpress blog",test_name="debug_mode",status="good",category="security",description="Debug mode is properly disabled"} 1
wp_health_check_detail{wp_site="New wordpress blog",test_name="plugin_updates",status="recommended",category="security",description="1 plugin(s) need updates"} 0
wp_health_check_detail{wp_site="New wordpress blog",test_name="php_version",status="good",category="performance",description="PHP version 8.2.29 is current"} 1
wp_health_check_detail{wp_site="New wordpress blog",test_name="php_memory_limit",status="good",category="performance",description="Memory limit 256M is adequate"} 1
wp_health_check_detail{wp_site="New wordpress blog",test_name="database_connection",status="good",category="general",description="Database connection is working properly"} 1
wp_health_check_detail{wp_site="New wordpress blog",test_name="https_status",status="recommended",category="security",description="Site should use HTTPS for better security"} 0
```

## 🏗️ Technical Requirements

- **WordPress:** 5.0 or higher
- **PHP:** 7.4 or higher
- **PHP Extensions:** OpenSSL (recommended for encryption)
- **Permissions:** WordPress administrator access for configuration

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


## 📊 Grafana Dashboards

### WordPress Comprehensive Monitoring Dashboard

A complete Grafana dashboard that combines health monitoring, performance metrics, and content statistics.

**Dashboard File:** `grafana/wordpress-metrics-dashboard.json`

![Grafana Dashboard](./prometheus-metrics/assets//screenshot-4.png)
![Grafana Dashboard](./prometheus-metrics/assets//screenshot-5.png)
![Grafana Dashboard](./prometheus-metrics/assets//screenshot-6.png)

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request
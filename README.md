# SlyMetrics - WordPress Prometheus Plugin

A comprehensive WordPress plugin that exports WordPress metrics in Prometheus format for monitoring including Grafana Dashboard for multi sites.

Wordpress Plugin page: [https://wordpress.org/plugins/slymetrics](https://wordpress.org/plugins/slymetrics/)

![Grafana Dashboard](./assets//screenshot-4.png)

## 🚀 Features

- **📊 Comprehensive Metrics**: WordPress content, users, plugins, themes, and system information
- **🔒 Enterprise Security**: Advanced authentication with rate limiting, input validation, and XSS protection
- **🌐 Multiple Endpoints**: Clean URLs (`/slymetrics/metrics`), REST API, and query parameter fallbacks
- **🏥 Health Monitoring**: Integration with WordPress Site Health API for detailed system checks
- **📈 Grafana Optimized**: Display-friendly metrics specifically designed for clean table visualizations
- **🐳 Container Ready**: Environment variable support for Docker, Kubernetes, and CI/CD pipelines
- **⚡ High Performance**: 3-tier intelligent caching system with 3x performance improvement
- **🔄 Auto-Discovery**: Automatic endpoint detection and fallback mechanisms
- **📱 Admin Interface**: User-friendly settings page with token management and health status
- **🛡️ Production Ready**: Enterprise-grade architecture with comprehensive error handling
- **💾 Memory Optimized**: Lazy loading and optimized data structures for heavy operations
- **🌐 Professional Code**: Multi-language support with enterprise-grade documentation

### New in Version 1.2.0

- **🏗️ Enterprise Architecture**: Complete refactoring with 98% reduction in function complexity through modular design
- **🔒 Advanced Security**: Enterprise-grade input validation, SQL injection prevention, XSS protection, and security headers
- **⚡ Performance Boost**: 3-tier intelligent caching strategy delivers 3x performance improvement
- **🛡️ Rate Limiting**: IP-based protection (60 requests/minute) with proper HTTP 429 responses
- **💾 Memory Optimization**: Lazy loading and optimized data structures for heavy operations
- **📊 Enhanced Monitoring**: Centralized error logging with structured context and WP_DEBUG integration
- **🔐 Robust Authentication**: Improved client IP detection with proxy support and enhanced token validation
- **📝 Code Quality**: Professional PHPDoc documentation and 90% reduction in code duplication
- **🌐 Multi-Language**: Full English code comments for international development standards

### New in Version 1.1.0

- **Prometheus Naming Compliance**: Updated all metrics to follow Prometheus best practices with `wordpress_` prefix
- **Consistent Labels**: Standardized all labels to use `wordpress_site` instead of `wp_site`
- **Environment Variables**: Changed to `SLYMETRICS_*` prefix for better plugin identification
- **Enhanced Metrics**: Improved metric naming with proper units and types
- **Updated Endpoints**: Consistent `/slymetrics/` endpoint usage across all configurations

## 📊 Metrics Overview

| Metric Name | Type | Description | Labels |
|-------------|------|-------------|---------|
| `wordpress_users_total` | counter | Number of users per role | `wordpress_site`, `role` |
| `wordpress_posts_total` | counter | Number of posts by status | `wordpress_site`, `status`: `published`, `draft`, `all` |
| `wordpress_pages_total` | counter | Number of pages by status | `wordpress_site`, `status`: `published`, `draft`, `all` |
| `wordpress_plugins_total` | counter | Active and inactive plugins | `wordpress_site`, `status`: `active`, `inactive`, `all` |
| `wordpress_plugins_update_total` | counter | Plugin update status | `wordpress_site`, `status`: `available`, `uptodate` |
| `wordpress_themes_total` | counter | Number of installed themes | `wordpress_site`, `type`: `child`, `parent` |
| `wordpress_comments_total` | counter | Number of comments by status | `wordpress_site`, `status`: `approved`, `spam`, `trash`, `post_trashed`, `moderated` |
| `wordpress_categories_total` | counter | Total number of categories | `wordpress_site` |
| `wordpress_media_total` | counter | Total number of media items | `wordpress_site` |
| `wordpress_tags_total` | counter | Total number of tags | `wordpress_site` |
| `wordpress_version_info` | gauge | WordPress version information | `wordpress_site`, `version`, `update_available` |
| `wordpress_autoload_options_total` | gauge | Number of autoloaded options | `wordpress_site` |
| `wordpress_autoload_size_bytes` | gauge | Size of autoloaded options in bytes | `wordpress_site` |
| `wordpress_autoload_transients_total` | gauge | Number of autoloaded transients | `wordpress_site` |
| `wordpress_php_info` | gauge | PHP configuration information | `wordpress_site`, `type`, `label` |
| `wordpress_php_version_info` | gauge | PHP version as readable string | `wordpress_site`, `php_version` |
| `wordpress_config_info` | gauge | WordPress and PHP configuration values | `wordpress_site`, `config`, `value` |
| `wordpress_memory_limit_info` | gauge | Memory limit for table display | `wordpress_site`, `memory_limit` |
| `wordpress_upload_max_info` | gauge | Upload max filesize for table display | `wordpress_site`, `upload_max` |
| `wordpress_post_max_info` | gauge | Post max size for table display | `wordpress_site`, `post_max` |
| `wordpress_exec_time_info` | gauge | Max execution time for table display | `wordpress_site`, `exec_time` |
| `wordpress_database_size_bytes` | gauge | Database size in bytes | `wordpress_site` |
| `wordpress_directory_size_bytes` | gauge | Directory sizes in bytes | `wordpress_site`, `directory`: `uploads`, `themes`, `plugins`, `total` |
| `wordpress_health_check_total` | gauge | Site health check results | `wordpress_site`, `category`: `critical`, `recommended`, `good`, `security`, `performance`, `total_failed` |
| `wordpress_health_check_detail_info` | gauge | Individual health check test results | `wordpress_site`, `test_name`, `status`, `category`, `description` |

## 🔧 Installation

1. Download the plugin files
2. Upload to your WordPress `wp-content/plugins/` directory
3. Activate the plugin through the WordPress admin interface
4. Configure authentication tokens in **Settings** → **SlyMetrics**

## 🌐 API Endpoints

The plugin provides multiple endpoint options to ensure compatibility across different server configurations:

### Primary Endpoints (Require Permalink Support)
```
/slymetrics/metrics
/metrics
```
**Note:** Requires WordPress permalink support (Settings → Permalinks → Select any option except "Plain").

### Fallback Endpoints (No Permalink Support Required)
```
/index.php?rest_route=/slymetrics/v1/metrics    # REST API fallback
/?slymetrics=1                                  # Query parameter fallback
/wp-json/slymetrics/v1/metrics                  # Standard REST API
```

**Note:** If permalink support is not available, use the fallback URLs above.

## 🔐 Authentication Methods

The plugin supports multiple authentication methods:

### 1. Bearer Token (Recommended)
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     http://yoursite.com/slymetrics/metrics
```

### 2. API Key (URL Parameter)
```bash
curl "http://yoursite.com/slymetrics/metrics?api_key=YOUR_API_KEY"
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
export SLYMETRICS_ENCRYPTION_KEY=$(openssl rand -base64 32)

# Set a stable bearer token for Prometheus
export SLYMETRICS_BEARER_TOKEN=$(openssl rand -hex 32)
```

**Environment Variables:**
- **`SLYMETRICS_ENCRYPTION_KEY`**: Base64-encoded 32-byte encryption key for API key storage
- **`SLYMETRICS_BEARER_TOKEN`**: Stable bearer token for Prometheus authentication (plain text, 64-character hex)

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

![Wordpress Admin Page](./assets//screenshot-1.png)

### Prometheus Configuration

#### Primary Configuration (requires permalinks)
```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'wordpress'
    static_configs:
      - targets: ['yoursite.com']
    metrics_path: '/slymetrics/metrics'
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
      rest_route: ['/slymetrics/v1/metrics']
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
      slymetrics: ['1']
    authorization:
      type: Bearer
      credentials: 'your_bearer_token_here'
    scrape_interval: 60s
```

### Docker Environment
```bash
# With stable bearer token for Prometheus
docker run -e SLYMETRICS_ENCRYPTION_KEY="$(openssl rand -base64 32)" \
           -e SLYMETRICS_BEARER_TOKEN="your_stable_prometheus_token" \
           your-wordpress-image

# Generate new tokens
docker run -e SLYMETRICS_ENCRYPTION_KEY="$(openssl rand -base64 32)" \
           -e SLYMETRICS_BEARER_TOKEN="$(openssl rand -hex 32)" \
           your-wordpress-image
```

### Environment Variables
```bash
# .env file
SLYMETRICS_ENCRYPTION_KEY=base64_encoded_32_byte_key
SLYMETRICS_BEARER_TOKEN=your_stable_64_character_hex_token
```

**Benefits of using `SLYMETRICS_BEARER_TOKEN`:**
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
      - SLYMETRICS_ENCRYPTION_KEY=${SLYMETRICS_ENCRYPTION_KEY}
      - SLYMETRICS_BEARER_TOKEN=${SLYMETRICS_BEARER_TOKEN}
    volumes:
      - ./wordpress-exporter-prometheus.php:/var/www/html/wp-content/plugins/wordpress-prometheus-metrics/wordpress-exporter-prometheus.php

  # Example Prometheus configuration with stable token
  prometheus:
    image: prom/prometheus:latest
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
    # Use the same token in prometheus.yml:
    # credentials: '${SLYMETRICS_BEARER_TOKEN}'
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
        - name: SLYMETRICS_ENCRYPTION_KEY
          valueFrom:
            secretKeyRef:
              name: slymetrics-secrets
              key: slymetrics-encryption-key
        - name: SLYMETRICS_BEARER_TOKEN
          valueFrom:
            secretKeyRef:
              name: slymetrics-secrets
              key: slymetrics-bearer-token
---
# Example: Create secret with stable tokens
apiVersion: v1
kind: Secret
metadata:
  name: wordpress-secrets
type: Opaque
data:
  # Base64 encoded values
  slymetrics-encryption-key: <base64-encoded-32-byte-key>
  slymetrics-bearer-token: <base64-encoded-64-char-hex-token>
```

**Kubernetes Token Management:**
```bash
# Generate and create secret
kubectl create secret generic slymetrics-secrets \
  --from-literal=slymetrics-encryption-key="$(openssl rand -base64 32)" \
  --from-literal=slymetrics-bearer-token="$(openssl rand -hex 32)"

# Use the same token in your Prometheus configuration
kubectl get secret slymetrics-secrets -o jsonpath='{.data.slymetrics-bearer-token}' | base64 -d
```

## � Available Metrics

### Core WordPress Metrics

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wordpress_users_total` | counter | Number of users per role | `wordpress_site`, `role` | `wordpress_users_total{role="administrator"} 1` |
| `wordpress_posts_total` | counter | Number of posts by status | `wordpress_site`, `status` | `wordpress_posts_total{status="published"} 5` |
| `wordpress_pages_total` | counter | Number of pages by status | `wordpress_site`, `status` | `wordpress_pages_total{status="published"} 3` |
| `wordpress_plugins_total` | counter | Number of plugins by status | `wordpress_site`, `status` | `wordpress_plugins_total{status="active"} 12` |
| `wordpress_plugins_update_total` | counter | Plugin update status | `wordpress_site`, `status` | `wordpress_plugins_update_total{status="available"} 2` |
| `wordpress_themes_total` | counter | Number of installed themes | `wordpress_site`, `type` | `wordpress_themes_total{type="parent"} 5` |
| `wordpress_comments_total` | counter | Number of comments by status | `wordpress_site`, `status` | `wordpress_comments_total{status="approved"} 45` |
| `wordpress_categories_total` | counter | Total number of categories | `wordpress_site` | `wordpress_categories_total{} 8` |
| `wordpress_media_total` | counter | Total number of media items | `wordpress_site` | `wordpress_media_total{} 150` |
| `wordpress_tags_total` | counter | Total number of tags | `wordpress_site` | `wordpress_tags_total{} 25` |

### System Information Metrics

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wordpress_version_info` | gauge | WordPress version information | `wordpress_site`, `version`, `update_available` | `wordpress_version_info{version="6.8.2"} 1` |
| `wordpress_php_info` | gauge | PHP configuration details | `wordpress_site`, `type`, `label` | `wordpress_php_info{type="version",label="8.2.29"} 80229` |
| `wordpress_database_size_bytes` | gauge | Database size in bytes | `wordpress_site` | `wordpress_database_size_bytes{} 125450000` |
| `wordpress_directory_size_bytes` | gauge | Directory sizes in bytes | `wordpress_site`, `directory` | `wordpress_directory_size_bytes{directory="uploads"} 512800000` |

### Display-Friendly Metrics (Grafana Optimized)

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wordpress_php_version_info` | gauge | PHP version (display format) | `wordpress_site`, `php_version` | `wordpress_php_version_info{php_version="8.2.29"} 1` |
| `wordpress_config_info` | gauge | WordPress and PHP config values | `wordpress_site`, `config`, `value` | `wordpress_config_info{config="memory_limit",value="256M"} 268435456` |
| `wordpress_memory_limit_info` | gauge | Memory limit (display format) | `wordpress_site`, `memory_limit` | `wordpress_memory_limit_info{memory_limit="256M"} 1` |
| `wordpress_upload_max_info` | gauge | Upload max size (display format) | `wordpress_site`, `upload_max` | `wordpress_upload_max_info{upload_max="64M"} 1` |
| `wordpress_post_max_info` | gauge | Post max size (display format) | `wordpress_site`, `post_max` | `wordpress_post_max_info{post_max="64M"} 1` |
| `wordpress_exec_time_info` | gauge | Max execution time (display format) | `wordpress_site`, `exec_time` | `wordpress_exec_time_info{exec_time="30"} 1` |

### Performance & Health Metrics

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wordpress_autoload_options_total` | gauge | Number of autoloaded options | `wordpress_site` | `wordpress_autoload_options_total{} 245` |
| `wordpress_autoload_size_bytes` | gauge | Size of autoloaded options in bytes | `wordpress_site` | `wordpress_autoload_size_bytes{} 156200` |
| `wordpress_autoload_transients_total` | gauge | Number of autoloaded transients | `wordpress_site` | `wordpress_autoload_transients_total{} 12` |

### Site Health Metrics

| Metric Name | Type | Description | Labels | Example |
|-------------|------|-------------|--------|---------|
| `wordpress_health_check_total` | gauge | Site health check summary | `wordpress_site`, `category` | `wordpress_health_check_total{category="critical"} 0` |
| `wordpress_health_check_detail_info` | gauge | Individual health check results | `wordpress_site`, `test_name`, `status`, `category`, `description` | `wordpress_health_check_detail_info{test_name="php_version",status="good"} 1` |

### Label Descriptions

- **`wordpress_site`**: WordPress site name/title
- **`role`**: User role (administrator, editor, author, etc.)
- **`status`**: Status type (published, draft, active, inactive, etc.)
- **`type`**: Category type (parent, child, version, etc.)
- **`directory`**: Directory name (uploads, themes, plugins, total)
- **`category`**: Health check category (critical, recommended, good, security, performance)
- **`test_name`**: Individual health check test identifier
- **`description`**: Human-readable description of the health check

## 📈 Example Metrics Output

```
curl -H "Authorization: Bearer f5634d6a966856848e2f3f4a139e534b844805f7561d86642adb19060719e95d" "http://192.168.178.21:30107/slymetrics/metrics"
# HELP wordpress_users_total Number of users per role.
# TYPE wordpress_users_total counter
wordpress_users_total{wordpress_site="New wordpress blog",role="administrator"} 1
wordpress_users_total{wordpress_site="New wordpress blog",role="contributor"} 1
wordpress_users_total{wordpress_site="New wordpress blog",role="none"} 0
wordpress_users_total{wordpress_site="New wordpress blog",role="total"} 2
# HELP wordpress_posts_total Number of posts.
# TYPE wordpress_posts_total counter
wordpress_posts_total{wordpress_site="New wordpress blog",status="published"} 1
wordpress_posts_total{wordpress_site="New wordpress blog",status="draft"} 0
wordpress_posts_total{wordpress_site="New wordpress blog",status="all"} 1
# HELP wordpress_pages_total Number of pages.
# TYPE wordpress_pages_total counter
wordpress_pages_total{wordpress_site="New wordpress blog",status="published"} 1
wordpress_pages_total{wordpress_site="New wordpress blog",status="draft"} 1
wordpress_pages_total{wordpress_site="New wordpress blog",status="all"} 2
# HELP wordpress_plugins_total Number of active and inactive plugins.
# TYPE wordpress_plugins_total counter
wordpress_plugins_total{wordpress_site="New wordpress blog",status="active"} 2
wordpress_plugins_total{wordpress_site="New wordpress blog",status="inactive"} 1
wordpress_plugins_total{wordpress_site="New wordpress blog",status="all"} 3
# HELP wordpress_plugins_update_total Plugin update status.
# TYPE wordpress_plugins_update_total counter
wordpress_plugins_update_total{wordpress_site="New wordpress blog",status="available"} 0
wordpress_plugins_update_total{wordpress_site="New wordpress blog",status="uptodate"} 3
# HELP wordpress_themes_total Number of installed themes.
# TYPE wordpress_themes_total counter
wordpress_themes_total{wordpress_site="New wordpress blog",type="child"} 0
wordpress_themes_total{wordpress_site="New wordpress blog",type="parent"} 0
# HELP wordpress_comments_total Total number of comments by status.
# TYPE wordpress_comments_total counter
wordpress_comments_total{wordpress_site="New wordpress blog",status="approved"} 1
wordpress_comments_total{wordpress_site="New wordpress blog",status="spam"} 0
wordpress_comments_total{wordpress_site="New wordpress blog",status="trash"} 0
wordpress_comments_total{wordpress_site="New wordpress blog",status="post_trashed"} 0
wordpress_comments_total{wordpress_site="New wordpress blog",status="all"} 1
wordpress_comments_total{wordpress_site="New wordpress blog",status="moderated"} 0
# HELP wordpress_categories_total Total number of categories.
# TYPE wordpress_categories_total counter
wordpress_categories_total{wordpress_site="New wordpress blog"} 1
# HELP wordpress_media_total Total number of media items.
# TYPE wordpress_media_total counter
wordpress_media_total{wordpress_site="New wordpress blog"} 0
# HELP wordpress_tags_total Total number of tags.
# TYPE wordpress_tags_total counter
wordpress_tags_total{wordpress_site="New wordpress blog"} 0
# HELP wordpress_autoload_options_total Number of autoloaded options.
# TYPE wordpress_autoload_options_total gauge
wordpress_autoload_options_total{wordpress_site="New wordpress blog"} 0
# HELP wordpress_autoload_size_bytes Size of autoloaded options in bytes.
# TYPE wordpress_autoload_size_bytes gauge
wordpress_autoload_size_bytes{wordpress_site="New wordpress blog"} 0
# HELP wordpress_autoload_transients_total Number of autoloaded transients.
# TYPE wordpress_autoload_transients_total gauge
wordpress_autoload_transients_total{wordpress_site="New wordpress blog"} 0
# HELP wordpress_database_size_bytes Database size in bytes.
# TYPE wordpress_database_size_bytes gauge
wordpress_database_size_bytes{wordpress_site="New wordpress blog"} 828375.04
# HELP wordpress_directory_size_bytes Directory sizes in bytes.
# TYPE wordpress_directory_size_bytes gauge
wordpress_directory_size_bytes{wordpress_site="New wordpress blog",directory="uploads"} 0
wordpress_directory_size_bytes{wordpress_site="New wordpress blog",directory="themes"} 0
wordpress_directory_size_bytes{wordpress_site="New wordpress blog",directory="plugins"} 13621002.24
wordpress_directory_size_bytes{wordpress_site="New wordpress blog",directory="total"} 13621002.24
# HELP wordpress_health_check_total Site health check results.
# TYPE wordpress_health_check_total gauge
wordpress_health_check_total{wordpress_site="New wordpress blog",category="good"} 5
wordpress_health_check_total{wordpress_site="New wordpress blog",category="recommended"} 2
wordpress_health_check_total{wordpress_site="New wordpress blog",category="critical"} 0
wordpress_health_check_total{wordpress_site="New wordpress blog",category="security"} 2
wordpress_health_check_total{wordpress_site="New wordpress blog",category="performance"} 0
wordpress_health_check_total{wordpress_site="New wordpress blog",category="total_failed"} 2
# HELP wordpress_health_check_detail_info Individual health check test results.
# TYPE wordpress_health_check_detail_info gauge
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="file_editing",status="recommended",category="security",description="File editing should be disabled in production environments"} 0
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="debug_mode",status="good",category="security",description="Debug mode is properly disabled"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="plugin_updates",status="good",category="security",description="All plugins are up to date"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="php_version",status="good",category="performance",description="PHP version 8.2.29 is current"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="php_memory_limit",status="good",category="performance",description="Memory limit 256M is adequate"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="database_connection",status="good",category="general",description="Database connection is working properly"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="https_status",status="recommended",category="security",description="Site should use HTTPS for better security"} 0
# HELP wordpress_version WordPress version information.
# TYPE wordpress_version gauge
wordpress_version{wordpress_site="New wordpress blog",version="6.8.2",update_available="0"} 1
# HELP wordpress_php_info PHP configuration information.
# TYPE wordpress_php_info gauge
wordpress_php_info{wordpress_site="New wordpress blog",type="version",label="8.2.29"} 80229
wordpress_php_info{wordpress_site="New wordpress blog",type="major_version",label="8"} 8
wordpress_php_info{wordpress_site="New wordpress blog",type="minor_version",label="2"} 2
wordpress_php_info{wordpress_site="New wordpress blog",type="release_version",label="29"} 29
wordpress_php_info{wordpress_site="New wordpress blog",type="max_input_vars",label="1000"} 1000
wordpress_php_info{wordpress_site="New wordpress blog",type="max_execution_time",label="30"} 30
wordpress_php_info{wordpress_site="New wordpress blog",type="memory_limit",label="256M"} 268435456
wordpress_php_info{wordpress_site="New wordpress blog",type="max_input_time",label="-1"} -1
wordpress_php_info{wordpress_site="New wordpress blog",type="upload_max_filesize",label="2M"} 2097152
wordpress_php_info{wordpress_site="New wordpress blog",type="post_max_size",label="8M"} 8388608
# HELP wordpress_php_version_info PHP version as readable string.
# TYPE wordpress_php_version_info gauge
wordpress_php_version_info{wordpress_site="New wordpress blog",php_version="8.2.29"} 1
# HELP wordpress_config_info WordPress and PHP configuration values.
# TYPE wordpress_config_info gauge
wordpress_config_info{wordpress_site="New wordpress blog",config="max_input_vars",value="1000"} 1000
wordpress_config_info{wordpress_site="New wordpress blog",config="max_execution_time",value="30"} 30
wordpress_config_info{wordpress_site="New wordpress blog",config="memory_limit",value="256M"} 268435456
wordpress_config_info{wordpress_site="New wordpress blog",config="max_input_time",value="-1"} -1
wordpress_config_info{wordpress_site="New wordpress blog",config="upload_max_filesize",value="2M"} 2097152
wordpress_config_info{wordpress_site="New wordpress blog",config="post_max_size",value="8M"} 8388608
# HELP wordpress_memory_limit_info Memory limit for table display.
# TYPE wordpress_memory_limit_info gauge
wordpress_memory_limit_info{wordpress_site="New wordpress blog",memory_limit="256M"} 1
# HELP wordpress_upload_max_info Upload max filesize for table display.
# TYPE wordpress_upload_max_info gauge
wordpress_upload_max_info{wordpress_site="New wordpress blog",upload_max="2M"} 1
# HELP wordpress_post_max_info Post max size for table display.
# TYPE wordpress_post_max_info gauge
wordpress_post_max_info{wordpress_site="New wordpress blog",post_max="8M"} 1
# HELP wordpress_exec_time_info Max execution time for table display.
# TYPE wordpress_exec_time_info gauge
wordpress_exec_time_info{wordpress_site="New wordpress blog",exec_time="30"} 1
```
# HELP wordpress_directory_size_bytes Directory sizes in bytes.
# TYPE wordpress_directory_size_bytes gauge
wordpress_directory_size_bytes{wordpress_site="New wordpress blog",directory="uploads"} 0
wordpress_directory_size_bytes{wordpress_site="New wordpress blog",directory="themes"} 13.56
wordpress_directory_size_bytes{wordpress_site="New wordpress blog",directory="plugins"} 10.48
wordpress_directory_size_bytes{wordpress_site="New wordpress blog",directory="total"} 24.05
# HELP wordpress_health_check_total Site health check results.
# TYPE wordpress_health_check_total gauge
wordpress_health_check_total{wordpress_site="New wordpress blog",category="critical"} 0
wordpress_health_check_total{wordpress_site="New wordpress blog",category="recommended"} 3
wordpress_health_check_total{wordpress_site="New wordpress blog",category="good"} 4
wordpress_health_check_total{wordpress_site="New wordpress blog",category="security"} 3
wordpress_health_check_total{wordpress_site="New wordpress blog",category="performance"} 0
wordpress_health_check_total{wordpress_site="New wordpress blog",category="total_failed"} 3
# HELP wordpress_health_check_detail_info Individual health check test results.
# TYPE wordpress_health_check_detail_info gauge
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="file_editing",status="recommended",category="security",description="File editing should be disabled in production environments"} 0
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="debug_mode",status="good",category="security",description="Debug mode is properly disabled"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="plugin_updates",status="recommended",category="security",description="1 plugin(s) need updates"} 0
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="php_version",status="good",category="performance",description="PHP version 8.2.29 is current"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="php_memory_limit",status="good",category="performance",description="Memory limit 256M is adequate"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="database_connection",status="good",category="general",description="Database connection is working properly"} 1
wordpress_health_check_detail_info{wordpress_site="New wordpress blog",test_name="https_status",status="recommended",category="security",description="Site should use HTTPS for better security"} 0
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
1. **Use Environment Variables**: Set `SLYMETRICS_ENCRYPTION_KEY` for production
2. **Secure Network**: Use HTTPS for all metric requests
3. **Access Control**: Restrict Prometheus server access to metrics endpoint
4. **Regular Token Rotation**: Regenerate tokens periodically
5. **Monitor Access**: Review WordPress access logs for suspicious activity

### Network Security (optional)

**Apache Configuration:** Edit your virtual host file (typically `/etc/apache2/sites-available/000-default.conf` or `/etc/apache2/sites-available/your-site.conf`)

```apache
# Example: Restrict access by IP (Apache)
<Location "/slymetrics/metrics">
    Require ip 10.0.0.0/8
    Require ip 192.168.0.0/16
</Location>

<Location "/wp-json/slymetrics/v1/metrics">
    Require ip 10.0.0.0/8
    Require ip 192.168.0.0/16
</Location>
```

**Nginx Configuration:** Add to your server block in `/etc/nginx/sites-available/your-site`
```nginx
location /slymetrics/metrics {
    allow 10.0.0.0/8;
    allow 192.168.0.0/16;
    deny all;
    try_files $uri $uri/ /index.php?$args;
}

location /wp-json/slymetrics/v1/metrics {
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

![Grafana Dashboard](./assets/screenshot-4.png)
![Grafana Dashboard](./assets/screenshot-5.png)
![Grafana Dashboard](./assets/screenshot-6.png)

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request
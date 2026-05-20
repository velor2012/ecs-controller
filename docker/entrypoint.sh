#!/bin/sh
set -e

echo "Starting ECS-Controller..."

resolve_proxy_value() {
    lower_value=$(eval "printf '%s' \"\${$1:-}\"")
    upper_value=$(eval "printf '%s' \"\${$2:-}\"")
    host_lower_value=$(eval "printf '%s' \"\${$3:-}\"")
    host_upper_value=$(eval "printf '%s' \"\${$4:-}\"")

    if [ -n "$lower_value" ]; then
        printf '%s' "$lower_value"
    elif [ -n "$upper_value" ]; then
        printf '%s' "$upper_value"
    elif [ -n "$host_lower_value" ]; then
        printf '%s' "$host_lower_value"
    else
        printf '%s' "$host_upper_value"
    fi
}

http_proxy_value=$(resolve_proxy_value http_proxy HTTP_PROXY HOST_http_proxy HOST_HTTP_PROXY)
https_proxy_value=$(resolve_proxy_value https_proxy HTTPS_PROXY HOST_https_proxy HOST_HTTPS_PROXY)

export http_proxy="$http_proxy_value"
export HTTP_PROXY="$http_proxy_value"
export https_proxy="$https_proxy_value"
export HTTPS_PROXY="$https_proxy_value"
unset HOST_http_proxy HOST_HTTP_PROXY HOST_https_proxy HOST_HTTPS_PROXY

# 1. 确保数据目录权限正确
# Docker 挂载卷时可能会导致权限归属为 root，这里强制修正为 www-data
if [ -d "/var/www/html/data" ]; then
    chown -R www-data:www-data /var/www/html/data
fi

# 2. 启动 Cron 服务 (后台运行)
# Alpine 使用 dcron，-b 表示后台运行，-L 指定日志级别
crond -b -l 8
echo "Cron daemon started."

# 3. 启动 Telegram 控制轮询 (后台运行)
# 如果没有配置 Telegram，进程会保持低频等待；配置后按钮控制可秒级响应。
su -s /bin/sh www-data -c "php /var/www/html/telegram_worker.php" >/dev/null 2>&1 &
echo "Telegram control worker started."

# 4. 启动 PHP-FPM (后台运行)
# -D 表示 Daemonize (守护进程模式)
php-fpm -D
echo "PHP-FPM started."

# 5. 启动 Nginx (前台运行)
# 保持 Nginx 在前台运行，防止容器退出
echo "Nginx started."
nginx -g 'daemon off;'

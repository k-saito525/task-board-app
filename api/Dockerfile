# API 用の PHP 実行環境。
# 本番向けの最適化（opcache, php-fpm + nginx 等）は行わず、開発用に絞っている。
FROM php:8.4-cli

# pdo_pgsql のビルドに libpq-dev が必要
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

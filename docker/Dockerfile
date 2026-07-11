# 開発用イメージ。将来のフェーズで配布用（マルチステージ）に育てる。
FROM php:8.4-cli

# composer がパッケージ展開に使う unzip、および zip 拡張を導入
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# composer 本体を公式イメージからコピー
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

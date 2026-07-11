# マルチステージ構成。
#   dev    … 開発用（compose.yaml から利用。vendor はホストのバインドマウント頼み）
#   vendor … 配布用イメージのための composer install 専用ステージ
#   dist   … 配布用の実行イメージ（デフォルトの build target）

# ---- dev: 開発用ステージ -----------------------------------------------
FROM php:8.4-cli AS dev

# composer がパッケージ展開に使う unzip、および zip 拡張を導入
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# composer 本体を公式イメージからコピー
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ---- vendor: 配布用の依存関係を用意するステージ -------------------------
FROM dev AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ---- dist: 配布用の実行イメージ ----------------------------------------
FROM php:8.4-cli AS dist

# ツール本体は /app に置く（解析対象は /workdir にマウントする）
WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY bin ./bin

WORKDIR /workdir

ENTRYPOINT ["php", "/app/bin/bobsap"]

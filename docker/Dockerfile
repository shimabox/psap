# マルチステージ構成。
#   dev           … 開発用（compose.yaml から利用。vendor はホストのバインドマウント頼み）
#   vendor        … 配布用イメージのための composer install 専用ステージ
#   dist          … 配布用の実行イメージ（デフォルトの build target）
#   phar          … psap.phar 生成専用ステージ（`make phar` から利用。配布物には含めない）
#   dist-plantuml … PlantUML レンダラー同梱の実行イメージ（analyze して即 PNG まで出す用）

# ---- dev: 開発用ステージ -----------------------------------------------
FROM php:8.5-cli AS dev

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
FROM php:8.5-cli AS dist

# ツール本体は /app に置く（解析対象は /workdir にマウントする）
WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY bin ./bin

WORKDIR /workdir

ENTRYPOINT ["php", "/app/bin/psap"]

# ---- phar: psap.phar 生成専用ステージ ---------------------------------
# clue/phar-composer は本体の composer.json を汚さないよう、ダウンロードした
# phar-composer.phar を直接 php で叩く（バージョン固定）。
FROM vendor AS phar

ARG PHAR_COMPOSER_VERSION=1.4.0

COPY composer.json ./
COPY src ./src
COPY bin ./bin

ADD https://github.com/clue/phar-composer/releases/download/v${PHAR_COMPOSER_VERSION}/phar-composer-${PHAR_COMPOSER_VERSION}.phar /usr/local/bin/phar-composer.phar
RUN chmod +x /usr/local/bin/phar-composer.phar \
    && php -d phar.readonly=0 /usr/local/bin/phar-composer.phar build . /app/psap.phar

# ---- dist-plantuml: PlantUML 同梱の配布用実行イメージ ---------------------
# dist と同じ内容 + Java（PlantUML 実行用）+ plantuml.jar + CJK フォント。
# 「analyze して即 PNG」を1コマンドで行う analyze-png も同梱する。
FROM dist AS dist-plantuml

ARG PLANTUML_VERSION=1.2026.6

RUN apt-get update \
    && apt-get install -y --no-install-recommends default-jre-headless graphviz fonts-noto-cjk fonts-dejavu-core fontconfig libharfbuzz0b \
    && rm -rf /var/lib/apt/lists/*

ADD https://github.com/plantuml/plantuml/releases/download/v${PLANTUML_VERSION}/plantuml.jar /opt/plantuml.jar

COPY docker/analyze-png.sh /usr/local/bin/analyze-png
COPY docker/entrypoint-plantuml.sh /usr/local/bin/entrypoint-plantuml
RUN chmod +x /usr/local/bin/analyze-png /usr/local/bin/entrypoint-plantuml

ENTRYPOINT ["entrypoint-plantuml"]

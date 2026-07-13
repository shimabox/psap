# 開発用の薄いラッパー。実体は docker compose run --rm app ... / docker build ...
.DEFAULT_GOAL := help

.PHONY: help setup test stan cs cs-fix phar build-dist build-plantuml

help: ## このヘルプを表示する
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

setup: ## イメージをビルドして composer install する
	docker compose build
	docker compose run --rm app composer install

test: ## phpunit を実行する
	docker compose run --rm app composer test

stan: ## phpstan (level max) を実行する
	docker compose run --rm app composer stan

cs: ## コーディングスタイルをチェックする（dry-run）
	docker compose run --rm app composer cs

cs-fix: ## コーディングスタイルを自動整形する
	docker compose run --rm app composer cs:fix

phar: ## psap.phar を生成する（docker/Dockerfile の phar ステージ、clue/phar-composer を利用）
	docker build -t psap-phar-builder --target phar -f docker/Dockerfile .
	docker run --rm --entrypoint cat psap-phar-builder /app/psap.phar > psap.phar
	chmod +x psap.phar
	docker rmi psap-phar-builder > /dev/null
	@echo "Generated: psap.phar"

build-dist: ## 配布用の実行イメージをビルドする（psap:dist タグ）
	docker build -t psap:dist --target dist -f docker/Dockerfile .

build-plantuml: ## PlantUML 同梱の配布用イメージをビルドする（psap:plantuml タグ）
	docker build -t psap:plantuml --target dist-plantuml -f docker/Dockerfile .

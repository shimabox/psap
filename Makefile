# 開発用の薄いラッパー。実体は docker compose run --rm app ...
.DEFAULT_GOAL := help

.PHONY: help setup test stan cs cs-fix

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

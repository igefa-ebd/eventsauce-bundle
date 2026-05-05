.DEFAULT_GOAL=help

DOCKER_UID := $(shell id -u)
DOCKER_GID := $(shell id -g)
DOCKER_COMPOSE = UID=$(DOCKER_UID) GID=$(DOCKER_GID) docker compose -f .docker/docker-compose.yml

# Host targets. Run these on the host, not in the container.
up: ## Start the Docker environment
	$(DOCKER_COMPOSE) up --build -d

down: ## Stop the Docker environment
	$(DOCKER_COMPOSE) down --remove-orphans

console: ## Open a shell in the PHP container
	$(DOCKER_COMPOSE) exec php sh

# Below needs access to PHP and composer, so run in the container.

help:
	@awk -F ':|##' '/^[^\t].+?:.*?##/ {\
		printf "\033[36m%-20s\033[0m %s\n", $$1, $$NF \
		}' $(MAKEFILE_LIST)

fix-cs: ## Fix cs
	PHP_CS_FIXER_IGNORE_ENV=1 tools/php-cs-fixer/vendor/bin/php-cs-fixer fix --allow-risky=yes

phpunit: ## Run phpunit tests
	vendor/bin/phpunit --color

install: ## Run composer install
	composer install --ignore-platform-reqs

update: ## Run composer update
	composer update --ignore-platform-reqs

phpstan: ## Run phpstan
	vendor/bin/phpstan --memory-limit=1G

test: ## Run phpunit and phpstan
	vendor/bin/phpunit --color
	vendor/bin/phpstan --memory-limit=1G

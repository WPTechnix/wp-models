FROM php:8.1-cli-alpine

LABEL maintainer="developers@wptechnix.com"
LABEL description="WP Models development environment — PHP 8.1 + Composer + Node"

# ── System dependencies ───────────────────────────────────────────────────────
RUN apk add --no-cache \
      bash \
      curl \
      git \
      unzip \
      nodejs \
      npm \
      # required for PHP extensions
      $PHPIZE_DEPS \
      linux-headers

# ── PHP extensions ────────────────────────────────────────────────────────────
# Xdebug for code coverage
RUN pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/xdebug.ini

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Runtime config ────────────────────────────────────────────────────────────
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    PATH="/app/vendor/bin:${PATH}"

WORKDIR /app

CMD ["/bin/bash"]

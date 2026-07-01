FROM wordpress:php8.3-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
		git \
		libffi-dev \
		unzip \
	&& docker-php-ext-install ffi \
	&& rm -rf /var/lib/apt/lists/*

RUN { \
		echo 'ffi.enable=1'; \
		echo 'memory_limit=512M'; \
	} > /usr/local/etc/php/conf.d/zz-duckdb-json-example.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /usr/src/wordpress/wp-content/plugins/wordpress-databases-support
COPY . /usr/src/wordpress/wp-content/plugins/wordpress-databases-support

RUN composer config --no-plugins allow-plugins.satur.io/duckdb-auto true \
	&& COMPOSER_NO_DEV=1 composer require --no-interaction --no-progress --with-all-dependencies satur.io/duckdb-auto \
	&& if [ -x vendor/bin/install-c-lib ]; then vendor/bin/install-c-lib; fi \
	&& composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

COPY examples/duckdb-json-wordpress/wp-config.php /usr/src/wordpress/wp-config.php
COPY examples/duckdb-json-wordpress/install.php /usr/local/bin/duckdb-json-install.php
COPY examples/duckdb-json-wordpress/entrypoint.sh /usr/local/bin/duckdb-json-entrypoint

RUN chmod +x /usr/local/bin/duckdb-json-entrypoint

WORKDIR /var/www/html

ENTRYPOINT [ "/usr/local/bin/duckdb-json-entrypoint" ]
CMD [ "apache2-foreground" ]

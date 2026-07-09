FROM php:8.3-apache

# Расширения PHP: pdo_mysql (база) и gd (getimagesize/валидация панорам)
RUN apt-get update && apt-get install -y --no-install-recommends \
      libjpeg-dev libpng-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
    && a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

# Лимиты PHP под приём кадров и долгую склейку
COPY php-custom.ini /usr/local/etc/php/conf.d/zz-custom.ini

# Документ-корень = смонтированный public_html (см. docker-compose)

FROM php:8.2-fpm-alpine

# Instalar dependencias del sistema y extensiones de PHP requeridas por Greenter
RUN apk add --no-cache \
    libxml2-dev \
    openssl-dev \
    bash \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    zlib-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    curl-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    soap \
    dom \
    bcmath \
    xml \
    mbstring \
    zip \
    gd \
    intl \
    curl \
    fileinfo

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Instalar dependencias de composer
RUN composer update --no-dev --optimize-autoloader --no-audit --ignore-platform-reqs -vvv

EXPOSE 9000

CMD ["php-fpm"]

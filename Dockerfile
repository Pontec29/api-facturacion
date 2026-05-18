FROM php:8.2-apache

# Instalar dependencias del sistema requeridas por Greenter
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    libonig-dev \
    zip \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Configurar y descargar extensiones de PHP
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

# Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

# Cambiar el DocumentRoot a la carpeta public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
# Permitir .htaccess
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Instalar dependencias de composer
RUN composer update --no-dev --optimize-autoloader --no-audit --ignore-platform-reqs

# Parche SUNAT: quitar atributo languageLocaleID de <cbc:Note> (NoteType no lo acepta)
RUN sed -i \
    's|<cbc:Note languageLocaleID="{{ leg.code }}">|<cbc:Note>|g' \
    vendor/greenter/greenter/packages/xml/src/Xml/Templates/invoice2.1.xml.twig

EXPOSE 80

CMD ["apache2-foreground"]

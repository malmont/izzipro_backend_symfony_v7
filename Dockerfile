# 📦 PHP 8.3 FPM
FROM php:8.3-fpm

# 🔧 Paquets système & extensions PHP
RUN apt-get update && apt-get install -y \
    iputils-ping net-tools curl libfcgi-bin git unzip \
    libpq-dev libzip-dev zip libicu-dev \
 && docker-php-ext-install pdo pdo_pgsql zip intl opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# ✅ PHP ini de base
RUN printf "memory_limit=-1\n" > /usr/local/etc/php/conf.d/zzz-memory-limit.ini \
 && printf "opcache.enable=1\nopcache.memory_consumption=128\nopcache.interned_strings_buffer=8\nopcache.max_accelerated_files=20000\nopcache.validate_timestamps=0\n" > /usr/local/etc/php/conf.d/zzz-opcache.ini \
 && printf "variables_order=EGPCS\n" > /usr/local/etc/php/conf.d/zzz-env.ini

# ✅ PHP-FPM pool (écoute local + garde les env)
RUN sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf \
 && printf "\n; Optimisation du pool PHP-FPM\npm = dynamic\npm.max_children = 50\npm.start_servers = 10\npm.min_spare_servers = 5\npm.max_spare_servers = 15\n" >> /usr/local/etc/php-fpm.d/www.conf \
 && sed -i 's|^;*clear_env = .*|clear_env = no|' /usr/local/etc/php-fpm.d/www.conf

# 📦 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 📂 Dossier de travail
WORKDIR /var/www

# ⚠️ Important : NE PAS désactiver les scripts Composer (pas de --no-scripts)
# On copie d’abord composer.* pour profiter du cache
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-dev --optimize-autoloader --no-progress

# 📂 Puis on copie le reste de l’application
COPY . .

# 🗝️ Dossiers et permissions (var, jwt)
RUN mkdir -p /var/www/config/jwt /var/www/var \
 && chown -R www-data:www-data /var/www \
 && chmod -R 755 /var/www

# 🛡️ Désactiver définitivement Dotenv en runtime (sans secrets cuits dans l’image)
#   -> génère .env.local.php et bootstrap adapté
RUN composer dump-env prod --empty \
 && composer dump-autoload -o

# 👤 Non-root
USER www-data

# 🚀 Entrypoint
CMD ["php-fpm"]

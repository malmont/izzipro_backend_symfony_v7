# 📦 PHP 8.4.28 avec FPM (19/11/2026 : dernière 8.4, avec les correctifs de sécurité de ce jour ; lot PHP84, brique 1 avant
#    Symfony 8). Le code et le vendor sont montés par docker-compose.yml : l'image ne fournit que PHP, ses extensions et sa
#    configuration. Construire par `ops/image/construire-image.sh <étiquette> <commit>` (contexte neuf, .dockerignore
#    appliqué, image contrôlée par ops/image/verifier-image.sh), jamais par `docker build .` dans l'arbre en ligne.
FROM php:8.4.28-fpm

# 🔧 Installer les dépendances système nécessaires
RUN apt-get update && apt-get install -y \
    iputils-ping \
    net-tools \
    curl \
    libfcgi-bin \
    git \
    unzip \
    libpq-dev \
    postgresql-client \
    libzip-dev \
    zip \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo pdo_pgsql zip intl opcache gd \
 && pecl update-channels \
 && pecl install redis-6.3.0 \
 && docker-php-ext-enable redis \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# ✅ Configurer PHP
RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/memory-limit.ini \
 && printf "opcache.enable=1\nopcache.memory_consumption=128\nopcache.interned_strings_buffer=8\nopcache.max_accelerated_files=10000\nopcache.validate_timestamps=0\n" > /usr/local/etc/php/conf.d/opcache-recommended.ini \
 && echo "variables_order=EGPCS" > /usr/local/etc/php/conf.d/zzz-env.ini

# ✅ php.ini minimal : erreurs jamais affichées, journalisées ; expose_php coupé ; limites d'avant (lot PHP84)
COPY docker/php/php.ini /usr/local/etc/php/php.ini

# ✅ PHP-FPM: écoute local + garder les env
RUN sed -i "s|listen = 127.0.0.1:9000|listen = 127.0.0.1:9000|g" /usr/local/etc/php-fpm.d/www.conf \
 && sed -i 's|^;*clear_env = .*|clear_env = no|' /usr/local/etc/php-fpm.d/www.conf

# 📦 Installer Composer
COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer

# 📂 Définir le dossier de travail
WORKDIR /var/www

# 📄 Copier uniquement composer.json et composer.lock (cache Docker)
COPY composer.json composer.lock ./

# ✅ INSTALL 1 — sans scripts (pas encore de bin/console)
RUN composer install --prefer-dist --no-dev --optimize-autoloader --no-progress --no-scripts

# --- AJOUT DE LA CORRECTION ---
# On force la copie explicite du dossier master_files pour s'assurer qu'il est bien présent dans l'image
COPY public/assets/master_files/ ./public/assets/master_files/
# --- FIN DE LA CORRECTION ---

# 📂 Copier le reste du projet
COPY . .

# 🔧 Configurer les permissions spécifiques pour jwt
RUN mkdir -p /var/www/config/jwt \
    && chown -R www-data:www-data /var/www/config/jwt \
    && chmod -R 755 /var/www/config/jwt

# 📁 Créer le dossier var avec les bonnes permissions
RUN mkdir -p /var/www/var \
    && chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www

# ✅ Désactiver définitivement Dotenv (sans secrets cuits) et optimiser l'autoload
RUN composer dump-env prod --empty \
 && composer dump-autoload -o

# ✅ Exécuter avec un utilisateur non-root
USER www-data

# 🚀 Lancer PHP-FPM directement
CMD ["php-fpm"]
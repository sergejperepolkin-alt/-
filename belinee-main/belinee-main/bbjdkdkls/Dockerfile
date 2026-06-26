FROM php:8.1-apache

# Принудительно отключаем конфликтующий event и worker на уровне конфигурации Apache
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* \
    && echo "LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so" > /etc/apache2/mods-enabled/mpm_prefork.load \
    && a2enmod mpm_prefork

# Устанавливаем системные библиотеки
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev && rm -rf /var/lib/apt/lists/*

# Настраиваем и ставим расширения PHP
RUN docker-php-ext-configure gd --with-jpeg --with-freetype && docker-php-ext-install -j$(nproc) pdo pdo_mysql gd

# Включаем rewrite и настраиваем имя сервера
RUN a2enmod rewrite
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Копируем проект
COPY . /var/www/html/

# Выставляем права на файлы
RUN chown -R www-data:www-data /var/www/html && find /var/www/html -type d -exec chmod 755 {} \; && find /var/www/html -type f -exec chmod 644 {} \;
RUN mkdir -p /var/lib/php/sessions && chmod -R 777 /var/lib/php/sessions || true

# Подстраиваем порты под требования Railway
RUN sed -i 's/Listen 80/Listen ${PORT}/g' /etc/apache2/ports.conf
RUN sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/g' /etc/apache2/sites-available/000-default.conf

# Запускаем стандартный Apache без лишних bash-оберток
CMD ["apache2-foreground"]

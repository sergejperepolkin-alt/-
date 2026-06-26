FROM php:8.1-apache

# Жестко удаляем конфигурационные файлы mpm_event и mpm_worker, чтобы они даже не пытались загрузиться
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load \
    && rm -f /etc/apache2/mods-enabled/mpm_event.conf \
    && rm -f /etc/apache2/mods-enabled/mpm_worker.load \
    && rm -f /etc/apache2/mods-enabled/mpm_worker.conf

# Принудительно включаем mpm_prefork
RUN a2enmod mpm_prefork

# Устанавливаем системные библиотеки
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev && rm -rf /var/lib/apt/lists/*

# Настраиваем и ставим расширения PHP для работы с БД и графикой
RUN docker-php-ext-configure gd --with-jpeg --with-freetype && docker-php-ext-install -j$(nproc) pdo pdo_mysql gd

# Включаем модуль rewrite (нужен для красивых ссылок) и задаем ServerName
RUN a2enmod rewrite
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Копируем файлы проекта внутрь контейнера
COPY . /var/www/html/

# Выставляем правильные права на папки и файлы
RUN chown -R www-data:www-data /var/www/html && find /var/www/html -type d -exec chmod 755 {} \; && find /var/www/html -type f -exec chmod 644 {} \;
RUN mkdir -p /var/lib/php/sessions && chmod -R 777 /var/lib/php/sessions || true

# Настраиваем порты под требования Railway (он дает случайный порт через переменную ${PORT})
RUN sed -i 's/Listen 80/Listen ${PORT}/g' /etc/apache2/ports.conf
RUN sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/g' /etc/apache2/sites-available/000-default.conf

# Запускаем Apache в обычном режиме
CMD ["apache2-foreground"]

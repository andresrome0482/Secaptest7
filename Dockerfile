FROM php:8.2-apache

# La imagen base no trae las cabeceras de desarrollo de libcurl, necesarias
# para compilar la extensión ext-curl de PHP. Sin este paso,
# "docker-php-ext-install curl" falla con "Package requirements
# (libcurl >= 7.29.0) were not met".
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install curl

# Copiar el código: el document root público, las funciones compartidas y
# el script del cron de re-suspensión.
COPY public/ /var/www/html/
COPY src/ /var/www/src/
COPY cli/ /var/www/cli/

# Endurecer permisos básicos.
RUN chown -R www-data:www-data /var/www/html /var/www/src /var/www/cli

# Railway y Render inyectan la variable de entorno PORT y esperan que la app
# escuche en ese puerto. Apache por defecto escucha en 80, así que lo
# reconfiguramos en el arranque del contenedor.
RUN a2enmod rewrite

# Redirigir los logs internos de Apache (incluye errores fatales de PHP no
# capturados por nuestro código) al stdout/stderr del contenedor, que es lo
# único que Render efectivamente muestra en su panel de Logs.
RUN ln -sf /dev/stderr /var/log/apache2/error.log \
    && ln -sf /dev/stdout /var/log/apache2/access.log

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

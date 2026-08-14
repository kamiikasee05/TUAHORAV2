# ============================================================
# TUAHORAV2 — Admin PHP (Railway)
# Build local:  docker build -t tuahora-php ./php
# (en Windows sin Docker: validar con Podman en la notebook, o
#  php -l de los .php y nginx -t dentro del contenedor)
#
# Approach elegido: UN solo stage basado en php:8.3-fpm-alpine
# + nginx (apk). El entrypoint renderiza el nginx.conf con $PORT
# (que Railway inyecta), arranca php-fpm en background y deja
# nginx en foreground. Sin supervisord: menos deps, PID 1 = nginx.
# ============================================================

FROM php:8.3-fpm-alpine

# --- Extensiones del admin ---
# curl, openssl, mbstring, sqlite3, pdo_sqlite y fileinfo YA vienen
# compiladas en la imagen oficial (--enable-mbstring, --with-curl,
# --with-openssl, --with-sqlite3, --with-pdo-sqlite, fileinfo bundled).
# Solo falta gd (necesita $PHPIZE_DEPS + libs para JPEG/PNG/FreeType).
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libpng-dev \
        libjpeg-turbo-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd \
    && apk del .build-deps

# Sanity check en build time: aborta si falta alguna extensión requerida
RUN php -m | grep -E '^(curl|openssl|mbstring|fileinfo|sqlite3|pdo_sqlite|gd)$' \
    || (echo "FALTA una extension PHP requerida" && exit 1)

# --- nginx (sirve el admin; Railway espera que el proceso escuche en $PORT) ---
RUN apk add --no-cache nginx

# php-fpm hereda las env de Railway (SCHEDULER_URL, SCHEDULER_API_KEY,
# ADMIN_PASSWORD_HASH, PROVIDER_ID, ...). La imagen oficial ya pone
# clear_env = no; lo fijamos explícito por si cambia la imagen base.
RUN printf '[www]\nclear_env = no\ncatch_workers_output = yes\n' \
        > /usr/local/etc/php-fpm.d/zz-tuahora.conf

# --- App: docroot = /app (estructura php/: admin/, api/, env-loader.php, index.php) ---
COPY . /app/
RUN chown -R www-data:www-data /app \
    && chmod -R u+rwX /app

# --- nginx conf template (el token __PORT__ se reemplaza en el entrypoint) ---
# Ojo: NO termina en .conf → nginx no lo parsea hasta que el entrypoint lo renderice.
RUN rm -f /etc/nginx/http.d/default.conf \
    && cat > /etc/nginx/http.d/default.conf.template <<'EOF'
server {
    listen __PORT__;
    server_name _;
    root /app;
    index index.php index.html;
    charset utf-8;
    client_max_body_size 25m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }
}
EOF

# --- Entrypoint: renderiza nginx con $PORT, php-fpm en background, nginx en foreground ---
RUN cat > /usr/local/bin/tuahora-entrypoint <<'EOF'
#!/bin/sh
set -e
PORT="${PORT:-8080}"
sed "s/__PORT__/$PORT/g" /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf
php-fpm -D
exec nginx -g 'daemon off;'
EOF
RUN chmod +x /usr/local/bin/tuahora-entrypoint

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/tuahora-entrypoint"]

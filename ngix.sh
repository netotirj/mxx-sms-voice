#!/bin/bash

# =========================
# CONFIGURAÇÕES
# =========================
SITE_DOMAIN="maxxsolutions.com.br"

SITE_DIR="/var/www/site"
PAINEL_DIR="/var/www/painel"

DB_NAME="meu_banco"
DB_USER="meu_usuario"
DB_PASS="minha_senha_segura"

# =========================
# ATUALIZAÇÃO
# =========================
echo "Atualizando sistema..."
sudo dnf update -y

# =========================
# NGINX
# =========================
echo "Instalando Nginx..."
sudo dnf install nginx -y
sudo systemctl enable nginx
sudo systemctl start nginx

# =========================
# MARIADB
# =========================
echo "Instalando MariaDB..."
sudo dnf install mariadb-server -y
sudo systemctl enable mariadb
sudo systemctl start mariadb

echo "Criando banco..."
sudo mysql -e "CREATE DATABASE ${DB_NAME};"
sudo mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# =========================
# PHP
# =========================
echo "Instalando PHP..."
sudo dnf install php php-fpm php-mysqlnd php-cli php-json php-opcache php-mbstring php-xml php-curl -y

sudo systemctl enable php-fpm
sudo systemctl start php-fpm

# =========================
# DIRETÓRIOS
# =========================
echo "Criando diretórios..."

sudo mkdir -p ${SITE_DIR}
sudo mkdir -p ${PAINEL_DIR}

sudo chown -R nginx:nginx /var/www
sudo chmod -R 755 /var/www

# =========================
# TESTES
# =========================
echo "Criando arquivos de teste..."

echo "<h1>Site Maxx Solutions</h1>" | sudo tee ${SITE_DIR}/index.html
echo "<?php phpinfo(); ?>" | sudo tee ${PAINEL_DIR}/index.php

# =========================
# NGINX CONFIG
# =========================
echo "Configurando Nginx..."

sudo tee /etc/nginx/conf.d/maxx.conf > /dev/null <<EOF
server {
    listen 80;
    server_name ${SITE_DOMAIN};

    # SITE
    root ${SITE_DIR};
    index index.html index.php;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    # PAINEL
    location /painel {
        root /var/www;
        index index.php;
        try_files \$uri \$uri/ /painel/index.php?\$query_string;
    }

    # PHP
    location ~ ^/painel/.*\\.php$ {
        root /var/www;
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    # SEGURANÇA
    location ~ /\\. {
        deny all;
    }
}
EOF

sudo nginx -t
sudo systemctl restart nginx
sudo systemctl restart php-fpm

# =========================
# FIREWALL
# =========================
echo "Configurando firewall..."
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload

# =========================
# SYSTEMD WORKERS
# =========================
echo "Criando serviços dos workers..."

WORKER_DIR="/var/www/painel"

create_worker() {
    NAME=$1
    FILE=$2
    DESC=$3

    sudo tee /etc/systemd/system/${NAME}.service > /dev/null <<EOL
[Unit]
Description=${DESC}
After=network.target mariadb.service

[Service]
Type=simple
WorkingDirectory=${WORKER_DIR}
ExecStart=/usr/bin/php ${WORKER_DIR}/${FILE}.php --limit=50 --sleep=10
Restart=always
RestartSec=5
User=nginx
Group=nginx

[Install]
WantedBy=multi-user.target
EOL
}

create_worker "maxx-voice-worker" "run_voice_worker" "Maxx Voice Worker"
create_worker "maxx-whatsapp-worker" "run_whatsapp_worker" "Maxx WhatsApp Worker"
create_worker "maxx-cdr-worker" "run_cdr_worker" "Maxx CDR Worker"

echo "Recarregando systemd..."
sudo systemctl daemon-reload

echo "Habilitando serviços..."
sudo systemctl enable maxx-voice-worker
sudo systemctl enable maxx-whatsapp-worker
sudo systemctl enable maxx-cdr-worker

echo "Iniciando serviços..."
sudo systemctl restart maxx-voice-worker
sudo systemctl restart maxx-whatsapp-worker
sudo systemctl restart maxx-cdr-worker

# =========================
# FINAL
# =========================
echo "-----------------------------------"
echo "SITE:   http://${SITE_DOMAIN}"
echo "PAINEL: http://${SITE_DOMAIN}/painel"
echo "-----------------------------------"

echo "Ver logs com:"
echo "journalctl -u maxx-voice-worker -f"
echo "journalctl -u maxx-whatsapp-worker -f"
echo "journalctl -u maxx-cdr-worker -f"
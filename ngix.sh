#!/bin/bash

# Configurações iniciais
SITE_DIR="/var/www/meusite"
SITE_DOMAIN="localhost" # Altere para seu domínio ou IP público
PHP_VERSION="8.1" # Altere conforme a versão disponível no Ubuntu

# Banco de dados
DB_NAME="meu_banco"
DB_USER="meu_usuario"
DB_PASS="minha_senha_segura"

echo "Atualizando o sistema..."
sudo apt update && sudo apt upgrade -y

echo "Instalando NGINX..."
sudo apt install nginx -y
sudo systemctl start nginx
sudo systemctl enable nginx

echo "Instalando MySQL Server..."
sudo apt install mysql-server -y
sudo systemctl start mysql
sudo systemctl enable mysql

echo "Criando banco de dados e usuário MySQL..."
sudo mysql -e "CREATE DATABASE ${DB_NAME};"
sudo mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

echo "Instalando PHP e extensões..."
sudo apt install php${PHP_VERSION}-fpm php${PHP_VERSION}-mysql -y

echo "Criando diretório do site..."
sudo mkdir -p ${SITE_DIR}
sudo chown -R www-data:www-data ${SITE_DIR}
sudo chmod -R 755 ${SITE_DIR}

echo "Criando arquivo index.php de teste..."
cat <<EOF | sudo tee ${SITE_DIR}/index.php
<?php
phpinfo();
?>
EOF

echo "Configurando NGINX para o site..."
NGINX_CONF="/etc/nginx/sites-available/meusite"
cat <<EOF | sudo tee $NGINX_CONF
server {
    listen 80;
    server_name ${SITE_DOMAIN};

    root ${SITE_DIR};
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

echo "Ativando site no NGINX..."
sudo ln -s /etc/nginx/sites-available/meusite /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

echo "Instalação e configuração concluída com sucesso!"
echo "Acesse: http://${SITE_DOMAIN} para ver o site."

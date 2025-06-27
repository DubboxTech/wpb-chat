#!/bin/bash

# WhatsApp Business System - Instalação Local
# Este script configura o ambiente para desenvolvimento local

set -e

echo "🚀 Configurando WhatsApp Business System para ambiente local..."

# Verificar dependências
command -v php >/dev/null 2>&1 || { echo "❌ PHP não encontrado. Instale o PHP 8.1+ primeiro."; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "❌ Composer não encontrado. Instale o Composer primeiro."; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "❌ Node.js/NPM não encontrado. Instale o Node.js primeiro."; exit 1; }

# Verificar versão do PHP
PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo "✅ PHP versão: $PHP_VERSION"

# Instalar dependências do PHP
echo "📦 Instalando dependências do PHP..."
composer install

# Instalar dependências do Node.js
echo "📦 Instalando dependências do Node.js..."
npm install

# Configurar arquivo .env se não existir
if [ ! -f .env ]; then
    echo "📝 Criando arquivo .env..."
    cp .env.example .env
fi

# Gerar chave da aplicação
echo "🔑 Gerando chave da aplicação..."
php artisan key:generate

# Gerar chave JWT
echo "🔑 Gerando chave JWT..."
php artisan jwt:secret --force

# Verificar se o banco de dados existe
echo "📊 Verificando banco de dados..."
DB_NAME=$(grep DB_DATABASE .env | cut -d '=' -f2)
DB_USER=$(grep DB_USERNAME .env | cut -d '=' -f2)
DB_PASS=$(grep DB_PASSWORD .env | cut -d '=' -f2)

if [ -z "$DB_PASS" ]; then
    DB_PASS_PARAM=""
else
    DB_PASS_PARAM="-p$DB_PASS"
fi

# Tentar criar o banco se não existir
mysql -u$DB_USER $DB_PASS_PARAM -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;" 2>/dev/null || {
    echo "⚠️  Não foi possível criar o banco automaticamente."
    echo "   Crie manualmente o banco '$DB_NAME' no MySQL."
}

# Executar migrations
echo "📊 Executando migrations..."
php artisan migrate --force

# Executar seeders
echo "🌱 Executando seeders..."
php artisan db:seed --force

# Criar link simbólico para storage
echo "🔗 Criando link simbólico para storage..."
php artisan storage:link

# Limpar e otimizar cache
echo "🧹 Otimizando aplicação..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Build dos assets
echo "🎨 Compilando assets do frontend..."
npm run build

# Configurar permissões
echo "🔒 Configurando permissões..."
chmod -R 775 storage bootstrap/cache

echo ""
echo "🎉 Instalação concluída com sucesso!"
echo ""
echo "📋 Próximos passos:"
echo "1. Configure as credenciais do WhatsApp Business API no arquivo .env"
echo "2. Configure a chave da API do Gemini no arquivo .env"
echo "3. Inicie o servidor: php artisan serve"
echo "4. Acesse: http://localhost:8000"
echo ""
echo "🔧 Para desenvolvimento com hot reload:"
echo "   Terminal 1: php artisan serve"
echo "   Terminal 2: npm run dev"
echo ""
echo "📱 Configure o webhook do WhatsApp para:"
echo "   http://seu-dominio.com/api/whatsapp/webhook"


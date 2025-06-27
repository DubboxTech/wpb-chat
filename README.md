# WhatsApp Business System

Sistema completo de atendimento via WhatsApp Business API com IA integrada, desenvolvido em Laravel 10 com integração direta à Meta (sem intermediários).

## 🚀 Características Principais

### ✅ Autenticação Forte
- Login com JWT + 2FA (E-mail/SMS)
- Sistema de cargos (Admin, Marketing, Suporte)
- Controle granular de permissões

### ✅ Integração Direta WhatsApp Business API
- Conexão direta com Meta Developers (sem BSPs)
- Webhooks para recebimento em tempo real
- Validação automática de templates
- Suporte a todos os tipos de mídia

### ✅ Sistema de Campanhas
- Envio em massa com agendamento
- Segmentação avançada por histórico
- Rate limiting inteligente (até 50k+ mensagens/dia)
- Relatórios detalhados de entrega/falhas

### ✅ IA para Autoatendimento
- Integração com Google Gemini
- Respostas automáticas baseadas em contexto
- Fallback automático para atendentes humanos
- Sistema de treinamento personalizado

### ✅ Arquitetura de Alta Performance
- Laravel 10 + Octane (Swoole)
- MySQL + Redis para cache e filas
- Jobs assíncronos para escalabilidade
- Containerização completa com Docker

## 📋 Pré-requisitos

- Docker & Docker Compose
- Conta Meta Developers com WhatsApp Business API
- Chave API do Google Gemini
- Domínio com SSL (para webhooks)

## 🛠️ Instalação Rápida

### 1. Clone o Repositório
```bash
git clone <repository-url>
cd whatsapp-business-system
```

### 2. Configure Variáveis de Ambiente
```bash
cp .env.example .env
```

Edite o arquivo `.env` com suas configurações:

```env
# Aplicação
APP_NAME="WhatsApp Business System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio.com

# Banco de Dados
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=whatsapp_business_system
DB_USERNAME=laravel
DB_PASSWORD=sua_senha_segura

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=sua_senha_redis
REDIS_PORT=6379

# WhatsApp Business API
WHATSAPP_ACCESS_TOKEN=seu_access_token
WHATSAPP_PHONE_NUMBER_ID=seu_phone_number_id
WHATSAPP_BUSINESS_ACCOUNT_ID=seu_business_account_id
WHATSAPP_WEBHOOK_VERIFY_TOKEN=seu_verify_token
WHATSAPP_APP_SECRET=seu_app_secret

# Google Gemini AI
GEMINI_API_KEY=sua_chave_gemini

# E-mail (para 2FA)
MAIL_MAILER=smtp
MAIL_HOST=seu_smtp_host
MAIL_PORT=587
MAIL_USERNAME=seu_email
MAIL_PASSWORD=sua_senha_email
```

### 3. Execute o Deploy
```bash
chmod +x deploy.sh
./deploy.sh
```

### 4. Configure o Webhook
No Meta Developers, configure o webhook para:
```
URL: https://seu-dominio.com/api/whatsapp/webhook
Verify Token: o_mesmo_do_env
```

## 🔧 Configuração WhatsApp Business API

### 1. Criar Aplicação Meta
1. Acesse [Meta Developers](https://developers.facebook.com/)
2. Crie uma nova aplicação
3. Adicione o produto "WhatsApp Business API"
4. Configure as permissões necessárias

### 2. Obter Credenciais
- **Access Token**: Token de acesso permanente
- **Phone Number ID**: ID do número de telefone
- **Business Account ID**: ID da conta business
- **App Secret**: Chave secreta da aplicação

### 3. Configurar Webhooks
- **URL**: `https://seu-dominio.com/api/whatsapp/webhook`
- **Verify Token**: Token personalizado (mesmo do .env)
- **Eventos**: `messages`, `message_deliveries`, `message_reads`

## 🤖 Configuração IA (Gemini)

### 1. Obter Chave API
1. Acesse [Google AI Studio](https://makersuite.google.com/)
2. Crie uma nova chave API
3. Configure no arquivo `.env`

### 2. Treinar a IA
1. Acesse o painel administrativo
2. Vá em "IA > Dados de Treinamento"
3. Adicione perguntas e respostas comuns
4. Aprove os dados de treinamento

## 📊 Uso do Sistema

### Dashboard Principal
- Visualização de chats ativos
- Métricas de resposta em tempo real
- Gráficos de desempenho
- Filtros por data, tags e status

### Gerenciamento de Campanhas
1. **Criar Campanha**
   - Escolha o template aprovado
   - Configure segmentação
   - Defina agendamento
   - Configure rate limiting

2. **Monitorar Execução**
   - Acompanhe progresso em tempo real
   - Visualize estatísticas de entrega
   - Gerencie pausas/retomadas

### Sistema de Atendimento
- **IA Automática**: Responde automaticamente com base no treinamento
- **Fallback Humano**: Escalação automática quando necessário
- **Histórico Completo**: Todas as interações são registradas

## 🔒 Segurança

### Implementações de Segurança
- ✅ Autenticação JWT com refresh tokens
- ✅ 2FA obrigatório para administradores
- ✅ Validação de assinatura de webhooks
- ✅ Criptografia de dados sensíveis
- ✅ Rate limiting em todas as APIs
- ✅ Logs de auditoria completos

### Compliance WhatsApp
- ✅ Respeita limites de rate da API
- ✅ Valida templates antes do envio
- ✅ Gerencia opt-ins/opt-outs
- ✅ Mantém histórico para auditoria

## 📈 Monitoramento

### Métricas Disponíveis
- Taxa de entrega de mensagens
- Tempo médio de resposta
- Eficácia da IA (taxa de resolução)
- Volume de mensagens por período
- Performance de campanhas

### Logs
- Todas as interações são logadas
- Erros são capturados e alertados
- Métricas são coletadas automaticamente

## 🚀 Deploy em Produção

### AWS (Recomendado)
```bash
cd terraform
terraform init
terraform plan
terraform apply
```

### Docker Compose (Desenvolvimento)
```bash
docker-compose up -d
```

## 🛠️ Manutenção

### Backup
```bash
# Backup do banco
docker-compose exec mysql mysqldump -u root -p whatsapp_business_system > backup.sql

# Backup dos arquivos
tar -czf storage_backup.tar.gz storage/
```

### Atualizações
```bash
git pull origin main
docker-compose build --no-cache
docker-compose up -d
docker-compose exec app php artisan migrate --force
```

### Monitoramento de Logs
```bash
# Logs da aplicação
docker-compose logs -f app

# Logs das filas
docker-compose logs -f queue-worker

# Logs do nginx
docker-compose logs -f nginx
```

## 📞 Suporte

### Documentação Adicional
- [API Documentation](docs/api.md)
- [Deployment Guide](docs/deployment.md)
- [Troubleshooting](docs/troubleshooting.md)

### Problemas Comuns
1. **Webhook não funciona**: Verifique SSL e firewall
2. **IA não responde**: Verifique chave Gemini e dados de treinamento
3. **Mensagens não enviam**: Verifique tokens WhatsApp e rate limits

## 📄 Licença

Este projeto está licenciado sob a MIT License - veja o arquivo [LICENSE](LICENSE) para detalhes.

## 🤝 Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature
3. Commit suas mudanças
4. Push para a branch
5. Abra um Pull Request

---

**Desenvolvido com ❤️ para revolucionar o atendimento via WhatsApp Business**


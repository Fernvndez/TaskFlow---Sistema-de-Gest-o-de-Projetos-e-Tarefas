# TaskFlow - Sistema de Gestão de Projetos e Tarefas

> Uma solução completa para gestão de projetos e tarefas com recursos avançados como notificações em tempo real, APIs RESTful, jobs assíncronos e sistema de auditoria.

## 🎯 Problema Resolvido

TaskFlow resolve a complexidade de gerenciar múltiplos projetos em equipes, oferecendo:
- **Centralização** de todas as informações do projeto
- **Automação** de notificações e relatórios
- **Visibilidade** completa do progresso das tarefas
- **Integração** via API com outras ferramentas
- **Escalabilidade** para equipes de qualquer tamanho

## ✨ Funcionalidades

### 🔐 Autenticação e Autorização
- Sistema completo com Laravel Breeze
- Múltiplos perfis: Admin, Manager, Developer
- Convites por email para novos usuários
- Two-factor authentication (2FA)

### 📊 Gestão de Projetos
- Criação e edição de projetos
- Status customizáveis
- Deadlines e marcos importantes
- Métricas e relatórios automáticos

### ✅ Sistema de Tarefas
- Atribuição de tarefas para usuários
- Diferentes prioridades e status
- Sistema de comentários
- Upload de anexos

### 🔔 Notificações Inteligentes
- Notificações em tempo real
- Email para deadlines próximos
- Notificações de database
- Integration com Slack (webhook)

### 🚀 API RESTful Completa
- Endpoints para todos os recursos
- Autenticação via Laravel Sanctum
- Documentação com Swagger/OpenAPI
- Rate limiting e throttling

### ⚡ Jobs e Queues
- Processamento assíncrono de relatórios
- Envio de emails em background
- Backup automático de dados
- Limpeza de logs antigos

### 📈 Dashboard e Métricas
- Gráficos interativos
- Métricas de performance da equipe
- Relatórios exportáveis (PDF/Excel)
- Widgets customizáveis

## 🛠️ Tecnologias Utilizadas

- **Laravel 10+** - Framework PHP
- **MySQL/PostgreSQL** - Banco de dados
- **Redis** - Cache e queues
- **Laravel Breeze** - Autenticação
- **Laravel Sanctum** - API authentication
- **Laravel Horizon** - Queue monitoring
- **Tailwind CSS** - Styling
- **Alpine.js** - JavaScript framework
- **Chart.js** - Gráficos e métricas

## 📦 Instalação

### Pré-requisitos
- PHP 8.2+
- Composer
- Node.js e NPM
- MySQL/PostgreSQL
- Redis (opcional, mas recomendado)

### Passo a passo

1. **Clone o repositório**
```bash
git clone https://github.com/seu-usuario/taskflow.git
cd taskflow
```

2. **Instale as dependências**
```bash
composer install
npm install
```

3. **Configure o ambiente**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Configure o banco de dados no `.env`**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=taskflow
DB_USERNAME=root
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis
```

5. **Execute as migrations e seeders**
```bash
php artisan migrate --seed
```

6. **Configure as queues**
```bash
php artisan queue:table
php artisan migrate
```

7. **Build dos assets**
```bash
npm run dev
# ou para produção
npm run build
```

8. **Configure o storage**
```bash
php artisan storage:link
```

## 🚀 Uso

### Desenvolvimento
```bash
# Servidor Laravel
php artisan serve

# Queue worker (em outro terminal)
php artisan queue:work

# Horizon (monitoramento de queues)
php artisan horizon

# Assets (modo watch)
npm run dev
```

### Usuários padrão (após seed)
- **Admin:** admin@taskflow.com / password
- **Manager:** manager@taskflow.com / password  
- **Developer:** developer@taskflow.com / password

## 📚 API Documentation

A documentação completa da API está disponível em `/api/documentation` após a instalação.

### Exemplos de uso:

```bash
# Autenticação
POST /api/login
{
  "email": "admin@taskflow.com",
  "password": "password"
}

# Listar projetos
GET /api/projects
Authorization: Bearer {token}

# Criar tarefa
POST /api/tasks
Authorization: Bearer {token}
{
  "title": "Nova tarefa",
  "description": "Descrição da tarefa",
  "project_id": 1,
  "assigned_to": 2,
  "priority": "high",
  "due_date": "2024-12-31"
}
```

## 🧪 Testes

Execute os testes automatizados:

```bash
# Todos os testes
php artisan test

# Com coverage
php artisan test --coverage

# Testes específicos
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
```

## 📁 Estrutura do Projeto

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/           # Controllers da API
│   │   └── Web/           # Controllers web
│   ├── Middleware/        # Middlewares customizados
│   └── Requests/          # Form requests
├── Models/                # Eloquent models
├── Services/              # Business logic
├── Jobs/                  # Background jobs
├── Mail/                  # Mailable classes
├── Notifications/         # Notification classes
└── Observers/             # Model observers
```

## 🔧 Configurações Avançadas

### Queues com Horizon
```bash
php artisan horizon:install
php artisan horizon
```

### Broadcasting (WebSockets)
```bash
npm install --save laravel-echo pusher-js
```

### Configurar Cron Jobs
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## 🤝 Contribuindo

1. Fork o projeto
2. Crie sua feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

### Padrões de código
- PSR-12 para PHP
- Eslint para JavaScript
- Sempre escreva testes para novas funcionalidades

## 🚀 Deploy

### Laravel Forge
```bash
# Adicione ao seu script de deploy
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

### Docker
```bash
docker-compose up -d
```

## 📈 Extensões Futuras

- [ ] **Mobile App** - React Native ou Flutter
- [ ] **Integrações** - Slack, Discord, Microsoft Teams
- [ ] **Time Tracking** - Controle de horas trabalhadas
- [ ] **Gantt Charts** - Visualização de cronograma
- [ ] **Calendário** - Integração com Google Calendar
- [ ] **Templates** - Templates de projetos pré-definidos
- [ ] **White-label** - Customização completa da interface
- [ ] **Analytics Avançados** - BI e relatórios executivos
- [ ] **AI Assistant** - Sugestões inteligentes de tarefas

## 📄 Licença

Este projeto está sob a licença MIT. Veja o arquivo [LICENSE](LICENSE) para mais detalhes.

## 👨‍💻 Autor

**Seu Nome**
- GitHub: [@seu-usuario](https://github.com/seu-usuario)
- LinkedIn: [seu-perfil](https://linkedin.com/in/seu-perfil)
- Email: seu@email.com

## 🙏 Agradecimentos

- Laravel Framework
- Tailwind CSS
- Alpine.js
- Chart.js
- Toda a comunidade open source

---

⭐ Se este projeto te ajudou, deixe uma estrela!

# TaskForce

Aplicação de gestão de trabalho inspirada no ClickUp, desenvolvida em **PHP + SQLite + Bootstrap** (sem frameworks).

## Funcionalidades

- Autenticação (registo/login/logout)
- Gestão de Equipas e Projetos
- Membros por equipa (apenas membros acedem à equipa/projetos)
- Gestão de utilizadores no dashboard (admin cria users)
- Módulo RH: departamentos/grupos, horários, calendário de férias e alertas por e-mail
- Tarefas, Sub Tarefas e Checklist
- Vista Lista e Vista Quadro (Kanban simples)
- Envio de relatório diário para líder de projeto/equipa
- **Pedidos diretos às equipas** (fora de projetos) em `requests.php`
- **Formulários globais** criados apenas por admin e visíveis para todos os utilizadores
- Admin define os **campos personalizados** de cada formulário (texto, número, data, seleção, textarea)

## Requisitos

- PHP 8.1+
- Extensão `pdo_sqlite` ativa

## Instalação

1. Iniciar servidor local:

```bash
php -S 0.0.0.0:8000
```

2. Abrir no browser:

- `http://localhost:8000/install.php` para criar o primeiro utilizador administrador.

3. Depois entrar em `http://localhost:8000/login.php`.

> A base de dados `database.sqlite` é criada automaticamente no primeiro arranque.

## Pedidos às equipas (global)

- Ir a `Pedidos às equipas` no menu.
- Admin cria formulários globais e define equipa de destino.
- Qualquer utilizador autenticado pode submeter pedidos nesses formulários.

## Relatório diário

- Manual: dentro de cada projeto, clique em **Enviar relatório diário**.
- Automático (cron):

```bash
php cron_daily_reports.php
```

### Alertas RH (cron)

```bash
php cron_hr_alerts.php
```

Recomendado em produção (execução automática a cada minuto):

```bash
* * * * * php /caminho/TaskForce/cron_hr_alerts.php >/dev/null 2>&1
```

Se `mail()` não estiver configurado no ambiente, os relatórios/alertas ficam registados em `reports_sent.log`.

### SMTP autenticado (fallback ao `mail()`)

Quando o servidor não tem `sendmail`/`mail()` ativo, pode configurar SMTP com variáveis de ambiente:

```bash
export TASKFORCE_SMTP_HOST="smtp.seudominio.com"
export TASKFORCE_SMTP_PORT="587"
export TASKFORCE_SMTP_SECURE="tls"   # tls | ssl | vazio
export TASKFORCE_SMTP_USER="noreply@calcadacorp.ch"
export TASKFORCE_SMTP_PASS="***"
```

Opcionalmente:

```bash
export TASKFORCE_MAIL_FROM_ADDRESS="noreply@calcadacorp.ch"
export TASKFORCE_MAIL_FROM_NAME="TaskForce"
```

Todas as tentativas de entrega (sucesso/falha) ficam registadas em `reports_sent.log`.

## Migração para outro servidor (checklist rápida)

1. Copiar os ficheiros do projeto e garantir permissões de escrita na pasta da aplicação (incluindo `database.sqlite` quando já existir).
2. Confirmar PHP 8.1+ com `pdo_sqlite` ativo.
3. Configurar as variáveis SMTP (`TASKFORCE_SMTP_*`) no novo ambiente, se necessário.
4. Recriar os cron jobs:
   - `* * * * * php /caminho/TaskForce/cron_hr_alerts.php >/dev/null 2>&1`
   - `*/5 * * * * php /caminho/TaskForce/cron_daily_reports.php >/dev/null 2>&1`
5. Validar no browser:
   - `install.php` (apenas se for instalação nova),
   - `login.php`,
   - e envio de teste em `Alertas RH` com **Correr agora**.

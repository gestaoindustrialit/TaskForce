# TaskForce

Aplicação de gestão de trabalho inspirada no ClickUp, desenvolvida em **PHP + SQLite + Bootstrap** (sem frameworks).

## Funcionalidades

- Autenticação (registo/login/logout)
- Gestão de Equipas e Projetos
- Membros por equipa (apenas membros acedem à equipa/projetos)
- Gestão de utilizadores no dashboard (criar novos utilizadores)
- Tarefas e Sub Tarefas
- Checklist por tarefa
- Vista Lista e Vista Quadro (Kanban simples)
- Envio de relatório diário para líder de projeto/equipa
- Formulários internos por projeto (ex.: ticket de tornearia/manutenção/compras)
- Controlo de visibilidade de formulários (`team` ou `leadership`)

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

## Relatório diário

- Manual: dentro de cada projeto, clique em **Enviar relatório diário**.
- Automático (cron):

```bash
php cron_daily_reports.php
```

Se `mail()` não estiver configurado no ambiente, os relatórios ficam registados em `reports_sent.log`.

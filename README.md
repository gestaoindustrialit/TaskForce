# TaskForce

Aplicação de gestão de trabalho inspirada no ClickUp, desenvolvida em **PHP + SQLite + Bootstrap** (sem frameworks).

## Funcionalidades

- Autenticação (registo/login/logout)
- Gestão de Equipas e Projetos
- Membros por equipa (apenas membros acedem à equipa/projetos)
- Gestão de utilizadores no dashboard (admin cria users)
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

Se `mail()` não estiver configurado no ambiente, os relatórios ficam registados em `reports_sent.log`.

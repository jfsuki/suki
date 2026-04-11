# Routing Map

## Entry and rewrites
- project/public/.htaccess:
  - Static files/dirs bypass routing.
  - /assets/* -> project/public/assets.php (solo si no existe en el proyecto).
  - /api/<controller>/<method> -> project/public/api.php?route=<controller>/<method>
  - /<path> -> project/public/index.php?url=<path>
- framework/public/.htaccess:
  - Sin routing (solo assets + editor_json).

## View routing
- project/public/index.php:
  - url param defaults to dashboard.
  - resolves project/views/<url>.php
  - wraps with project/views/includes/header.php + project/views/includes/footer.php

## API routing
- project/public/api.php:
  - Este es el **ENRUTADOR CENTRAL MAESTRO**. No asumas que un archivo final en `app/controller/` es el responsable de un endpoint si la ruta está hardcodeada aquí.
  - La mayoría de rutas core del sistema interceptan la ejecución mediantes bloques `if ($route === '...')`.
  - **Endpoints Core Hardcodeados:**
    - `contracts/forms`, `reports`, `dashboards` (Generación visual y metadata)
    - `wizard/form-from-entity`
    - `entity/save`, `import/csv`
    - `records/*`, `command`, `entity/options` (CRUD y QueryBuilder dinámico)
    - `chat/sessions/list`, `chat/history`, `chat/sessions/create`, `chat/journal/get` (Persistencia del SUKI Builder y chat)
    - `chat/message`, `chat/help`, `chat/acid-test`, `chat/acid-report`, `chat/quality` (Agente Conversacional)
    - `registry/status`, `registry/projects`, `registry/select`, `registry/users`, `registry/user`
    - `registry/deploys`, `registry/deploy`, `registry/entities`
    - `auth/send-code`, `auth/verify-code`, `auth/login`, `auth/users`
    - `integrations/alanube/*` (test/save/emit/status/cancel/webhook)
    - `channels/telegram/webhook`, `channels/whatsapp/webhook`
    - `workflow/*` (Remix, List, Restore, Diff)

- **FALLBACK ARCHITECTURE (Fallback MVC Routing):**
  - Si (y solo si) una ruta de API no fue procesada por los `if ($route === '...')` hardcodeados en `api.php`, el enrutador entra en un modo "Fallback".
  - route param split by '/': `<controller>/<method>`
  - class instanciada: `App\Controller\<Controller>Controller` (Que vive físicamente en `project/app/controller/`)
  - method: lowercased second segment (default index)
  - *ADVERTENCIA*: Si un archivo luce como Controller pero su ruta coincide con un bloque `if ($route === ...)` en `api.php`, **el controlador será ignorado (Código Muerto/Ghost File)**. Verifique siempre `api.php` primero.
- framework/config/menu.php loads project/config/menu.json:
  - dashboard -> project/views/dashboard.php
  - facturas -> project/views/facturas.php
  - clientes/clientes -> project/views/clientes/clientes.php
  - inventario/productos -> project/views/inventario/productos.php
  - inventario/bodegas -> project/views/inventario/bodegas.php
  - inventario/kardex -> project/views/inventario/kardex.php

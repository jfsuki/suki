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
  - route param split by '/': <controller>/<method>
  - class: App\Controller\<Controller>Controller
  - method: lowercased second segment (default index)

## Menu driven routes
- framework/config/menu.php loads project/config/menu.json:
  - dashboard -> project/views/dashboard.php
  - facturas -> project/views/facturas.php
  - clientes/clientes -> project/views/clientes/clientes.php
  - inventario/productos -> project/views/inventario/productos.php
  - inventario/bodegas -> project/views/inventario/bodegas.php
  - inventario/kardex -> project/views/inventario/kardex.php

# Framework vs Project Boundary — EN
This document defines what belongs to the framework (kernel/base) vs what belongs to each project/app.

## Framework (distributable kernel)
- framework/app/Core/* (FormGenerator, FormBuilder, runtime helpers)
- framework/public/assets/js/* (grid + form runtime)
- framework/public/editor_json/formjson.html (dev assistant)
- framework/public/.htaccess (static headers; no routing)
- framework/contracts/schemas/* (JSON schemas)
- framework/contracts/forms/form.contract.json (contract example)
- framework/config/menu.php (loader for project menu.json)
- framework/docs/* (source of truth)
- framework/vendor/* + framework/composer.json (deps)

## Project/App (per implementation)
- project/public/index.php, project/public/api.php, project/public/.htaccess (routing shell)
- project/public/assets.php (proxy for framework assets if not present locally)
- project/views/* (page views; no inline form/grid config)
- project/contracts/forms/*, project/contracts/grids/*, project/contracts/views/* (app contracts)
- project/config/menu.json (menu content maintained by the developer)
- project/config/app.php, project/config/db.php, project/config/env_loader.php
- project/app/controller/* (business controllers)
- project/database/* (SQL or migrations for the app)
- project/public/assets/app/* (optional app-specific assets)
- project/.env (project config + optional framework paths)

## Tenant (customer)
- config/tenant/* or tenant-specific JSON (branding, permissions, tax rules)

## Rules
- Views must load JSON from /project/contracts first (legacy fallback only).
- Menu must be defined in /project/config/menu.json (framework/config/menu.php is only the loader).
- Do not mix framework kernel files with app/demo files in distribution.
- Web root for the app is project/public only; framework/public is for assets/editor (optional separate host).

---

# Frontera Framework vs Proyecto — ES
Este documento define qué pertenece al framework (kernel/base) y qué pertenece a cada proyecto/app.

## Framework (kernel distribuible)
- framework/app/Core/* (FormGenerator, FormBuilder, helpers del runtime)
- framework/public/assets/js/* (runtime de grids y forms)
- framework/public/editor_json/formjson.html (asistente dev)
- framework/public/.htaccess (headers; sin routing)
- framework/contracts/schemas/* (JSON schemas)
- framework/contracts/forms/form.contract.json (ejemplo de contrato)
- framework/config/menu.php (loader de menu.json del proyecto)
- framework/docs/* (fuente de verdad)
- framework/vendor/* + framework/composer.json (deps)

## Proyecto/App (por implementación)
- project/public/index.php, project/public/api.php, project/public/.htaccess (shell de routing)
- project/public/assets.php (proxy de assets al framework si no existen localmente)
- project/views/* (vistas; sin config inline de forms/grids)
- project/contracts/forms/*, project/contracts/grids/*, project/contracts/views/* (contratos del app)
- project/config/menu.json (menú mantenido por el programador)
- project/config/app.php, project/config/db.php, project/config/env_loader.php
- project/app/controller/* (controladores de negocio)
- project/database/* (SQL o migraciones del app)
- project/public/assets/app/* (assets específicos opcionales)
- project/.env (config del proyecto + rutas del framework opcionales)

## Tenant (cliente)
- config/tenant/* o JSONs específicos (branding, permisos, reglas fiscales)

## Reglas
- Las vistas deben cargar JSON desde /project/contracts primero (legacy solo fallback).
- El menú debe definirse en /project/config/menu.json (framework/config/menu.php solo carga).
- No mezclar archivos kernel con archivos demo/app al distribuir.
- El web root del app es project/public; framework/public solo para assets/editor (host opcional).

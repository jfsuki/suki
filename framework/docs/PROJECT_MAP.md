# Project Map (Workspace)

## Workspace Root
- framework/: kernel distribuible (no contiene archivos del proyecto).
- project/: implementación del proyecto/app (solo archivos del usuario).
- README.md: resumen general del workspace.

## Framework (kernel)
- framework/app/Core/Controller.php: view loader helper.
- framework/app/Core/FormGenerator.php: renders forms, grids, summary from config.
- framework/app/Core/FormBuilder.php: input/select/textarea rendering.
- framework/app/Core/TableGenerator.php: table shell for JS data load.
- framework/app/Core/Response.php: JSON response helper.
- framework/config/menu.php: loader para project/config/menu.json.
- framework/contracts/schemas/*: JSON schemas.
- framework/contracts/forms/form.contract.json: sample contract (kernel).
- framework/public/assets/js/*: runtime JS (grid + form).
- framework/public/assets/js/grid-engine.php: server generated grid JS.
- framework/public/editor_json/formjson.html: asistente de JSON y vistas.
- framework/public/.htaccess: headers básicos (sin routing).
- framework/vendor/*, framework/composer.json: dependencias del kernel.
- framework/docs/*: fuente de verdad del contrato.

## Project (app)
- project/public/index.php: router de vistas + layout.
- project/public/api.php: router API hacia controllers.
- project/public/assets.php: proxy de assets al framework (fallback).
- project/public/.htaccess: rutas /api y /<vista>, fallback de assets.
- project/views/*: vistas del app.
- project/contracts/*: contratos JSON del app.
- project/contracts/app.manifest.json: contrato global del app (db/registry/integrations/processes).
- project/config/*: config del app (menu.json, db, env_loader).
- project/app/controller/*: controladores de negocio.
- project/database/*: SQL/migraciones.
- project/.env: variables del proyecto (incluye rutas del framework).

## Legacy (solo compatibilidad)
- framework/views/*: fallback histórico de vistas.
- framework/contracts/*: contratos legacy (si existen).

# View Conventions

## Resolution
- URL path maps to project/views/<path>.php via project/public/index.php.
- Subfolders allowed: clientes/clientes -> project/views/clientes/clientes.php.
- Layout wrapper is in project/views/includes/header.php and project/views/includes/footer.php.

## Structure
- project/views/ contains page templates.
- project/views/includes/ contains shared layout parts.
- Views are partials (no <html>/<head>/<body>); layout is always wrapped by header.php/footer.php.

## JSON driven views
- JSON config lives in /project/contracts/forms (project standard).
- Legacy JSON next to view is allowed only as fallback during migration.
- Views load JSON (project path first, legacy fallback) and pass to FormGenerator.

## Assets
- JS bundles are included from project/views/includes/footer.php.
- Default: /assets/* is served by project/public/assets.php (proxy to framework/public/assets).
- Optional: define SUKI_FRAMEWORK_PUBLIC_URL to load assets from a dedicated framework host.

# Manual de testing (generador + app + chat)

Este manual valida que el generador, la BD, el CRUD y el chat funcionen de extremo a extremo.

## 0) Requisitos minimos
1) Laragon activo (Apache + MySQL).
2) Proyecto en `C:\laragon\www\suki`.
3) Archivo `.env` correcto.
4) (Opcional) Autotest rapido:
```
powershell -ExecutionPolicy Bypass -File framework/scripts/acid_test.ps1
```
Si la base es distinta:
```
SUKI_TEST_BASE="http://suki.test:8080/api" powershell -File framework/scripts/acid_test.ps1
```
5) Auto-testing chat (acid test):
```
php framework/tests/chat_acid.php
php framework/tests/chat_golden.php
php framework/tests/chat_api_single_demo.php
```
Debe devolver `failed = 0` y `training_error = "No error"`.
`chat_golden.php` y `chat_api_single_demo.php` deben devolver `summary.ok = true`.
Reporte CLI: `framework/tests/tmp/chat_acid_result.json`
Reporte desde chat ("Probar sistema"): `project/storage/reports/chat_acid_default.json`

## 1) Configurar la base de datos
Editar `project/.env`:
```
DB_HOST=localhost
DB_NAME=suki_saas
DB_USER=root
DB_PASS=
DB_DRIVER=mysql
DB_PORT=3306
DB_CHARSET=utf8mb4
```
Si tu root tiene clave, usa esa clave en `DB_PASS`.

Opcional (crear DB + usuario):
```
CREATE DATABASE suki_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'suki'@'localhost' IDENTIFIED BY 'suki_pass';
GRANT ALL PRIVILEGES ON suki_saas.* TO 'suki'@'localhost';
FLUSH PRIVILEGES;
```
Luego:
```
DB_USER=suki
DB_PASS=suki_pass
```

## 2) Conectar API de IA (Groq + Gemini)
Editar `project/.env`:
```
GROQ_API_KEY=TU_KEY
GROQ_MODEL=llama-3.1-8b-instant
GEMINI_API_KEY=TU_KEY
GEMINI_MODEL=gemini-2.5-flash-lite
LLM_ROUTER_MODE=auto
```
Si no pones keys, el chat funciona solo con comandos simples (local).

## 3) Probar generador (Editor JSON)
1) Abrir: `/editor_json/formjson.html` (framework host).
2) Crear formulario con campos basicos.
3) Guardar JSON (contrato).
4) Verificar que se guardo en `project/contracts/forms`.

## 4) Crear app desde chat (sin UI)
1) Abrir: `/chat_builder.html` (chat creador).
2) Enviar:
   - `crear tabla productos nombre:texto precio:numero`
   - `crear formulario productos`
3) Verificar:
   - `project/contracts/entities/productos.entity.json`
   - `project/contracts/forms/productos.form.json`

## 5) Probar CRUD desde chat (App)
En `/chat_app.html`:
- `crear producto nombre=Camisa precio=50000`
- `listar producto`
- `actualizar producto id=1 precio=55000`
- `eliminar producto id=1`

Si la entidad no existe:
- App: "Esa tabla no existe en esta app. Debe ser agregada por el creador."
- Builder: "No existe la tabla X. ¿Quieres crearla?"

## 5.1) Probar roles (multiusuario)
1) En el chat de la app (`/chat_app.html`), selecciona Rol = "Vendedora" (seller).
2) Intenta `eliminar producto id=1`.
3) Espera: permiso denegado si el contrato limita delete a admin.
4) Cambia Rol = "Administrador" y repite.

## 6) Probar app UI (forms reales)
Abrir en navegador:
- `/clientes`
- `/facturas`
- `/cuentas_cobrar`
Validar:
- Guardar registros en BD.
- Grid + summary actualizan.

## 7) Probar pruebas unitarias
CLI:
```
php framework/tests/run.php
```
Chat:
```
probar sistema
```
El bot ejecuta pruebas unitarias + acid test y actualiza el reporte en Home.

## 8) Probar Conversation Gateway (memoria local)
1) Enviar: `hola`
2) Enviar: `crear cliente nombre=Ana nit=123`
3) Verificar archivos:
   - `project/storage/tenants/default/agent_state/default__app__user_demo.json`
   - `project/storage/tenants/default/lexicon.json`
4) (Opcional) Ejecutar job:
```
php -r "require 'framework/app/autoload.php'; (new App\\Jobs\\AgentNurtureJob())->run('default');"
```
Cron diario:
```
php framework/cron/agent_nurture.php default
```
5) Si necesitas un modo mixto (app+builder), usar `/chat_gateway.html` (legacy).

## 9) Probar login en chat (auth basico)
En `/chat_builder.html` o `/chat_app.html`:
- `crear usuario usuario=ana rol=vendedor clave=1234`
- `iniciar sesion usuario=ana clave=1234`
Espera: "Login listo. Ya puedes usar la app."

## 10) Validar ayuda dinamica (registry real)
- En chat, escribe: `ayuda`
- Verifica que ejemplos y botones coincidan con entidades reales.

## 11) Errores comunes
- **Access denied root**: DB_PASS incorrecto.
- **mysql_native_password**: usuario MySQL incompatible; crea usuario nuevo con autenticacion moderna.
- **IA no configurada**: faltan API keys, usa comandos simples.

## 12) Medir consumo IA (requests y tokens)
En chat (builder o app) escribe:
- `consumo ia`
- `tokens ia`

Respuesta esperada:
- Requests IA del dia
- Prompt tokens
- Completion tokens
- Total tokens
- Proveedores usados

Fuente del reporte:
- `project/storage/tenants/{tenant}/telemetry/YYYY-MM-DD.log.jsonl`

## 13) Probar P0/P1/P2 (estado + entrenamiento + calidad)
### P0 checklist por estado
En builder y app pregunta:
- `paso actual`
- `checklist`
- `que falta`

Esperado:
- Respuesta con checklist `[x]/[ ]`
- Paso actual y siguiente accion

### P1 entrenamiento por pais
1) Ejecuta:
```
php framework/cron/agent_nurture.php default
```
2) Verifica archivos:
- `project/storage/tenants/default/training_overrides.json`
- `project/storage/tenants/default/country_language_overrides.json`

### P2 dashboard calidad conversacional
API:
```
/api/chat/quality?tenant_id=default&days=7
```
UI:
- `project/public/chat_builder.html` (panel derecho > Calidad conversacional)
- `project/public/chat_app.html` (panel derecho > Calidad conversacional)

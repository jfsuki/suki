# Manual de testing (generador + app + chat)

Este manual valida que el generador, la BD, el CRUD y el chat funcionen de extremo a extremo.

## 0) Requisitos minimos
1) Laragon activo (Apache + MySQL).
2) Proyecto en `C:\laragon\www\suki`.
3) Archivo `.env` correcto.

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
1) Abrir: `/chat_gateway.html`
2) Enviar:
   - `crear tabla productos nombre:texto precio:numero`
   - `crear formulario productos`
3) Verificar:
   - `project/contracts/entities/productos.entity.json`
   - `project/contracts/forms/productos.form.json`

## 5) Probar CRUD desde chat
En `/chat_gateway.html`:
- `crear producto nombre=Camisa precio=50000`
- `listar producto`
- `actualizar producto id=1 precio=55000`
- `eliminar producto id=1`

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

## 8) Errores comunes
- **Access denied root**: DB_PASS incorrecto.
- **mysql_native_password**: usuario MySQL incompatible; crea usuario nuevo con autenticacion moderna.
- **IA no configurada**: faltan API keys, usa comandos simples.


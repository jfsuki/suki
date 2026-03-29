# Plan corto de integracion multimodal (Telegram primero)

Objetivo: el usuario opera solo con palabras, audio, imagenes o documentos.
Primero Telegram (gratis) y luego WhatsApp (pago), con un conector comun.

## 1) Lo comun entre Telegram y WhatsApp
Ambos soportan:
- Webhook HTTPS
- Texto + multimedia (audio, imagen, documento)
- IDs de usuario y chat
- Descarga de archivos por URL o API

Diseño: **un solo adaptador** que normaliza todo a:
```
{ channel, user_id, chat_id, type, text, media_url, media_type, ts }
```

## 2) Pipeline minimo (en Node.js)
1) **Webhook** recibe mensaje (Telegram/WhatsApp).
2) **Normalizer** transforma al formato comun.
3) **Router**:
   - texto corto -> reglas locales (sin IA)
   - texto complejo -> LLM (JSON estricto)
   - audio/imagen/pdf -> pipeline OCR/ASR
4) **Action Engine** ejecuta (CreateRecord/QueryRecords/UpdateRecord).
5) **Respuesta** corta + confirmacion.

## 3) Transcripcion (audio)
Opciones:
- Whisper local (costo 0, usa CPU).
- Whisper API (rapido, costo variable).
Modo ahorro: "low" para notas cortas.

## 4) OCR (imagenes y PDF)
Opciones:
- Tesseract local (costo 0, menos precision).
- API OCR (mejor precision, costo variable).
Usar hashing para no reprocesar archivos repetidos.

## 5) Seguridad y limites
- Limite de tamano por archivo (5-10MB).
- Sanitizar nombres, rutas, extensiones.
- TTL para archivos (7 dias).
- No guardar PII completa en logs (tokenizar).

## 6) Costos (enfoque optimizado)
- Telegram: gratuito.
- WhatsApp: cobro por ventana de 24h (variable).
- OCR/ASR local: costo de CPU (sin costo externo).
- OCR/ASR API: costo por uso (habilitar solo si es necesario).

## 7) Pasos tecnicos minimos (orden recomendado)
1) Crear servicio `chat-gateway` (Node.js) con webhook de Telegram.
2) Implementar normalizador comun.
3) Conectar Command Layer del framework (Create/Query/Update).
4) Agregar transcripcion (Whisper local).
5) Agregar OCR (Tesseract local).
6) Logs + cache + TTL.
7) Habilitar WhatsApp como canal extra (mismo adapter).

## 8) Resultado esperado
El usuario puede:
- Enviar un audio: "Vendí 2 camisas" -> se registra venta.
- Enviar foto del RUT -> se crea cliente.
- Enviar PDF de factura -> se guarda documento y se extraen datos.

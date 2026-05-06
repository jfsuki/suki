# ONTOLOGÍA LATAM — SUKI

**Estado**: ✅ ACTIVA desde 2026-05-05  
**Versión**: 2.0.0  
**Alcance**: Universal — cualquier país LATAM, cualquier sector empresarial

---

## ¿Qué es y para qué sirve?

La ontología LATAM normaliza el lenguaje natural del usuario **antes** de que el texto llegue al embedding de Qdrant o al LLM. Su objetivo: que "dame una mano con el inventario" en Colombia, "ayúdame con el almacén" en México y "asisteme con el stock" en Argentina clasifiquen en el **mismo intent** sin utterances duplicadas por variante regional.

El motor semántico (Qdrant + embeddings) solo entrena con vocabulario canónico. La ontología traduce al canónico en runtime, reutilizando el mismo Qdrant para todos los países y sectores.

---

## Arquitectura de capas

```
Texto del usuario (raw)
        ↓
[1] ConversationGatewayStubsTrait::normalizeWithTraining()
        ↓
[2] OntologyNormalizer::apply($text, $country, $mode, $sector)
    ├── Capa 1a: global.typo_rules       (errores tipográficos universales)
    ├── Capa 1b: global.synonyms         (cel→teléfono, mail→email…)
    ├── Capa 1c: countries.<CC>.typo_rules  (correcciones del país)
    ├── Capa 1d: countries.<CC>.synonyms    (RFC→documento, CUIT→documento…)
    ├── Capa 1e: fiscal_equivalents.<CC>    (DIAN/SAT/SUNAT→autoridad_fiscal)
    ├── Capa 1f: sectors.<sector>.synonyms  (vocabulario sectorial)
    ├── Capa 2a: latam_lexicon.phrase_rules (frases coloquiales multi-palabra)
    ├── Capa 2b: latam_lexicon.synonyms     (modismos → ERP canónico)
    └── Capa 2c: latam_lexicon.stop_tokens  (muletillas eliminadas)
        ↓
Texto normalizado → Qdrant embedding → Intent routing
```

---

## Archivos del sistema

| Archivo | Rol | Editable sin código |
|---------|-----|---------------------|
| `framework/contracts/agents/country_language_overrides.json` | Config principal — países, sectores, fiscalía | ✅ Sí |
| `framework/contracts/agents/latam_es_col_conversation_lexicon.json` | Modismos y frases LATAM base | ✅ Sí |
| `framework/app/Core/Agents/OntologyNormalizer.php` | Motor PHP — aplica los JSONs | ⛔ No tocar sin test |
| `framework/app/Core/Agents/ConversationGatewayStubsTrait.php` | Punto de wire al pipeline | ⛔ No tocar |
| `project/storage/tenants/<tenant>/latam_lexicon_overrides.json` | Override específico del tenant | ✅ Vía chat del tenant |

---

## Países soportados (v2.0)

| Código | País | Autoridad fiscal | Doc. tributario |
|--------|------|-----------------|-----------------|
| CO | Colombia | DIAN | NIT |
| MX | México | SAT | RFC |
| AR | Argentina | AFIP / ARCA | CUIT |
| PE | Perú | SUNAT | RUC |
| CL | Chile | SII | RUT |
| VE | Venezuela | SENIAT | RIF |
| EC | Ecuador | SRI | RUC |
| BO | Bolivia | SIN | NIT |
| UY | Uruguay | DGI | RUT |
| GT | Guatemala | SAT-GT | NIT |
| PA | Panamá | DGI-PA | RUC |
| DO | R. Dominicana | DGII | RNC |

**Para añadir un país nuevo:** agregar entrada en `countries` y `fiscal_equivalents` en el JSON. Cero PHP.

---

## Sectores soportados (v2.0)

| Sector (key) | Tipo de empresa |
|--------------|-----------------|
| `commerce` | Retail, ferretería, tiendas |
| `health` | Clínicas, consultorios, farmacias |
| `production` | Manufactura, maquila, industria |
| `services` | Profesionales, técnicos, soporte |
| `legal` | Bufetes, notarías |
| `education` | Colegios, academias, universidades |
| `hospitality` | Hoteles, restaurantes |

**Para añadir un sector nuevo:** agregar entrada en `sectors` en el JSON con `label` y `synonyms`. Cero PHP.

---

## Cómo el país se detecta en runtime

```
profile['country']        → explícito en el perfil del tenant (más confiable)
profile['tenant_country'] → campo alternativo
default → 'CO'            → fallback si no está configurado
```

Para configurar el país de un tenant: actualizar el campo `country` en el perfil del tenant via Torre o chat del builder.

---

## Equivalentes fiscales — cómo funciona

Un usuario en México escribe: *"el SAT me pide la factura electrónica CFDI"*

La ontología transforma:
- `sat` → `autoridad_fiscal`
- `cfdi` → `factura_electronica`

El texto que llega a Qdrant: *"la autoridad_fiscal me pide la factura_electronica"*

Ese intent ya está entrenado con el vocabulario canónico. El mismo vector clasifica la pregunta de un usuario colombiano que escribió *"la DIAN me pide la factura"* sin un solo utterance duplicado.

---

## Cómo agregar modismos nuevos

**Modismo de un país específico** → `countries.<CC>.synonyms` en `country_language_overrides.json`:
```json
"CO": {
  "synonyms": {
    "fiar": "credito",
    "dar fiado": "vender a credito"
  }
}
```

**Frase coloquial multi-palabra** → `phrase_rules` en `latam_es_col_conversation_lexicon.json`:
```json
{ "match": "haceme el favor", "replace": "ayudame" }
```

**Vocabulario de un sector** → `sectors.<sector>.synonyms`:
```json
"health": {
  "synonyms": {
    "turno urgente": "cita_urgente"
  }
}
```

---

## Override por tenant

Cada empresa puede tener su propio lexicón sin afectar a otros tenants:

`project/storage/tenants/<tenant_id>/latam_lexicon_overrides.json`

```json
{
  "synonyms": { "mi código interno": "sku" },
  "phrase_rules": [{ "match": "referencia interna", "replace": "codigo_producto" }],
  "stop_tokens": ["jefe", "profe"]
}
```

`KnowledgeProvider::loadLatamLexiconPack()` fusiona el base + el override del tenant automáticamente.

---

## Lo que NO hace la ontología

- No reemplaza el entrenamiento semántico en Qdrant — lo **complementa**
- No traduce entre idiomas — solo normaliza variantes del español
- No modifica los contratos JSON de entidades ni los intents canónicos
- No se aplica a respuestas del LLM — solo al input del usuario

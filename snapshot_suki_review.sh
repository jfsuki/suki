#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(pwd)"
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT_DIR="$ROOT_DIR/_snapshot_review_$STAMP"
PKG_NAME="suki_review_bundle_$STAMP"
ARCHIVE_TAR="$ROOT_DIR/${PKG_NAME}.tar.gz"

echo "==> Creando snapshot de revisión en: $OUT_DIR"
mkdir -p "$OUT_DIR"

# ----------------------------
# helpers
# ----------------------------
copy_if_exists() {
  local src="$1"
  local dst="$2"
  if [ -e "$src" ]; then
    mkdir -p "$(dirname "$dst")"
    cp -R "$src" "$dst"
    echo "  + $src"
  fi
}

run_cmd_to_file() {
  local outfile="$1"
  shift
  {
    echo "\$ $*"
    echo "------------------------------------------------------------"
    "$@" || true
  } > "$outfile" 2>&1
}

# ----------------------------
# 1) metadata general
# ----------------------------
mkdir -p "$OUT_DIR/meta"

run_cmd_to_file "$OUT_DIR/meta/git_status.txt" git status
run_cmd_to_file "$OUT_DIR/meta/git_log_last_40.txt" git log --oneline --decorate -n 40
run_cmd_to_file "$OUT_DIR/meta/git_diff_stat.txt" git diff --stat
run_cmd_to_file "$OUT_DIR/meta/git_branch.txt" git branch -vv
run_cmd_to_file "$OUT_DIR/meta/php_version.txt" php -v

# ----------------------------
# 2) tests y health
# ----------------------------
mkdir -p "$OUT_DIR/qa"

run_cmd_to_file "$OUT_DIR/qa/run_tests.txt" php framework/tests/run.php
run_cmd_to_file "$OUT_DIR/qa/db_health.txt" php framework/tests/db_health.php
run_cmd_to_file "$OUT_DIR/qa/chat_acid.txt" php framework/tests/chat_acid.php
run_cmd_to_file "$OUT_DIR/qa/chat_golden.txt" php framework/tests/chat_golden.php
run_cmd_to_file "$OUT_DIR/qa/chat_real_20.txt" php framework/tests/chat_real_20.php
run_cmd_to_file "$OUT_DIR/qa/chat_real_100.txt" php framework/tests/chat_real_100.php
run_cmd_to_file "$OUT_DIR/qa/conversation_kpi_gate.txt" php framework/tests/conversation_kpi_gate.php
run_cmd_to_file "$OUT_DIR/qa/qa_gate_post.txt" php framework/scripts/qa_gate.php post
run_cmd_to_file "$OUT_DIR/qa/codex_self_check.txt" php framework/scripts/codex_self_check.php --strict

# ----------------------------
# 3) memoria estructural
# ----------------------------
copy_if_exists "$ROOT_DIR/AGENTS.md" "$OUT_DIR/AGENTS.md"
copy_if_exists "$ROOT_DIR/docs/ARCHITECTURE_INDEX.md" "$OUT_DIR/docs/ARCHITECTURE_INDEX.md"
copy_if_exists "$ROOT_DIR/docs/CHANGE_MAP.md" "$OUT_DIR/docs/CHANGE_MAP.md"
copy_if_exists "$ROOT_DIR/docs/MODULE_REGISTRY.md" "$OUT_DIR/docs/MODULE_REGISTRY.md"

# ----------------------------
# 4) contratos y canon
# ----------------------------
copy_if_exists "$ROOT_DIR/docs/contracts" "$OUT_DIR/docs/contracts"
copy_if_exists "$ROOT_DIR/framework/contracts/schemas" "$OUT_DIR/framework/contracts/schemas"
copy_if_exists "$ROOT_DIR/docs/canon" "$OUT_DIR/docs/canon"

# ----------------------------
# 5) módulos/core clave
# ----------------------------
mkdir -p "$OUT_DIR/framework/app"

copy_if_exists "$ROOT_DIR/framework/app/Core" "$OUT_DIR/framework/app/Core"
copy_if_exists "$ROOT_DIR/framework/app/Modules" "$OUT_DIR/framework/app/Modules"

# ----------------------------
# 6) tests clave
# ----------------------------
copy_if_exists "$ROOT_DIR/framework/tests" "$OUT_DIR/framework/tests"

# ----------------------------
# 7) scripts clave
# ----------------------------
copy_if_exists "$ROOT_DIR/framework/scripts" "$OUT_DIR/framework/scripts"

# ----------------------------
# 8) migraciones
# ----------------------------
copy_if_exists "$ROOT_DIR/db/migrations" "$OUT_DIR/db/migrations"

# ----------------------------
# 9) config de ejemplo
# ----------------------------
copy_if_exists "$ROOT_DIR/project/.env.example" "$OUT_DIR/project/.env.example"
copy_if_exists "$ROOT_DIR/project/public/api.php" "$OUT_DIR/project/public/api.php"

# ----------------------------
# 10) reporte guía para revisión
# ----------------------------
cat > "$OUT_DIR/REVIEW_GUIDE.md" <<'EOF'
# REVIEW GUIDE — SUKI

## Objetivo de esta revisión
Usar este paquete para:
1. evaluar estado real del proyecto
2. comparar arquitectura vs implementación real
3. cruzar pendientes críticos
4. definir siguiente fase de entrenamiento
5. revisar memoria estructural, memoria continua y AgentOps
6. detectar vacíos para SUKI, agentes y Codex

## Revisar primero
- AGENTS.md
- docs/ARCHITECTURE_INDEX.md
- docs/CHANGE_MAP.md
- docs/MODULE_REGISTRY.md
- qa/run_tests.txt
- qa/qa_gate_post.txt
- qa/conversation_kpi_gate.txt
- meta/git_log_last_40.txt

## Focos de evaluación
- router determinista
- skills/action catalog
- memoria estructural
- memoria continua / improvement pipeline
- POS
- compras
- fiscal
- ecommerce
- multiusuario
- planes y límites
- usage metering
- AgentOps observability
- readiness para entrenamiento alto

## Pendientes a cruzar
- entrenamiento ERP prioritario
- smoke test real RAG con Qdrant/Gemini
- memoria/vector publication gates
- multi-agent business simulation engine
- dashboard maestro
- POS + compras + motor fiscal + ecommerce + media
EOF

cat > "$OUT_DIR/PROJECT_STATUS_TEMPLATE.md" <<'EOF'
# PROJECT STATUS TEMPLATE

## 1. Estado general
- Arquitectura:
- Runtime:
- QA:
- Riesgos:

## 2. Módulos implementados
- Media/Documents:
- Entity Search:
- POS:
- Compras:
- Fiscal:
- Ecommerce:
- Multiusuario:
- SaaS plans:
- Usage metering:
- Agent tools:
- AgentOps:

## 3. Pendientes críticos
- 
- 
- 

## 4. Entrenamiento
- datasets listos:
- datasets faltantes:
- gaps de memoria:
- gaps de RAG:
- gaps de simulación multiagente:

## 5. Próximo bloque recomendado
- 
EOF

# ----------------------------
# 11) limpiar peso innecesario
# ----------------------------
rm -rf \
  "$OUT_DIR/framework/tests/tmp" \
  "$OUT_DIR/project/storage" \
  "$OUT_DIR/vendor" \
  "$OUT_DIR/node_modules" 2>/dev/null || true

# ----------------------------
# 12) empaquetar
# ----------------------------
echo "==> Empaquetando snapshot..."
tar -czf "$ARCHIVE_TAR" -C "$ROOT_DIR" "$(basename "$OUT_DIR")"

echo
echo "OK"
echo "Carpeta: $OUT_DIR"
echo "Archivo para subir: $ARCHIVE_TAR"
echo
echo "Siguiente paso:"
echo "1) súbeme el .tar.gz"
echo "2) yo te devuelvo:"
echo "   - estado actualizado del proyecto"
echo "   - cruce con pendientes"
echo "   - plan de entrenamiento alto"
echo "   - mejoras de memoria para SUKI/agentes/Codex"
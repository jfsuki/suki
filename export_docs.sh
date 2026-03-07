#!/usr/bin/env bash
set -euo pipefail

OUT="suki_docs_snapshot.tar.gz"
TMP_LIST="$(mktemp)"

POSSIBLE_DIRS=(
  "docs"
  "prompts"
  "ai_architecture"
  "framework/docs"
  "framework/prompts"
  "STATUS"
)

for item in "${POSSIBLE_DIRS[@]}"; do
  if [ -e "$item" ]; then
    echo "$item" >> "$TMP_LIST"
  fi
done

if [ ! -s "$TMP_LIST" ]; then
  echo "No se encontraron carpetas o archivos para exportar."
  rm -f "$TMP_LIST"
  exit 1
fi

tar -czf "$OUT" \
  --exclude=".git" \
  --exclude="vendor" \
  --exclude="node_modules" \
  --exclude=".env" \
  --exclude=".env.*" \
  --exclude="storage" \
  --exclude="cache" \
  --exclude="logs" \
  -T "$TMP_LIST"

rm -f "$TMP_LIST"

echo "Snapshot creado: $OUT"
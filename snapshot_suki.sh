#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-.}"
OUT_DIR="${2:-./snapshots}"
TS="$(date +%Y%m%d_%H%M%S)"
NAME="suki_snapshot_${TS}"
TMP_DIR="${OUT_DIR}/${NAME}"

mkdir -p "${TMP_DIR}"

rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'storage/logs' \
  --exclude 'storage/cache' \
  --exclude 'bootstrap/cache' \
  --exclude '.idea' \
  --exclude '.vscode' \
  --exclude '*.zip' \
  --exclude '*.tar' \
  --exclude '*.tar.gz' \
  --exclude '*.rar' \
  --exclude '*.7z' \
  --exclude '*.mp4' \
  --exclude '*.mp3' \
  --exclude '*.png' \
  --exclude '*.jpg' \
  --exclude '*.jpeg' \
  --exclude '*.webp' \
  --exclude '.env' \
  --exclude '.env.*' \
  --exclude 'coverage' \
  --exclude 'tmp' \
  --exclude 'temp' \
  --exclude 'dist' \
  --exclude 'build' \
  "${PROJECT_ROOT}/" "${TMP_DIR}/project/"

cat > "${TMP_DIR}/README_SNAPSHOT.txt" <<'EOF'
Snapshot generado para revisión arquitectónica de SUKI.
Incluye código y contratos principales.
Excluye dependencias pesadas, binarios, logs y secretos.
EOF

tar -czf "${OUT_DIR}/${NAME}.tar.gz" -C "${OUT_DIR}" "${NAME}"
rm -rf "${TMP_DIR}"

echo "Snapshot creado en: ${OUT_DIR}/${NAME}.tar.gz"
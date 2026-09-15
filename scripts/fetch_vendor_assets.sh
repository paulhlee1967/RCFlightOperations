#!/usr/bin/env bash
# Download pinned front-end vendor assets into assets/vendor/ (no CDN at runtime).
# Run from project root: bash scripts/fetch_vendor_assets.sh

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BOOTSTRAP_VER="5.3.8"
ICONS_VER="1.11.3"
FABRIC_VER="7.4.0"
CDN="https://cdn.jsdelivr.net/npm"

mkdir -p assets/vendor/bootstrap/css assets/vendor/bootstrap/js
mkdir -p assets/vendor/bootstrap-icons/fonts
mkdir -p assets/vendor/fabric

echo "Bootstrap ${BOOTSTRAP_VER}..."
curl -fsSL "${CDN}/bootstrap@${BOOTSTRAP_VER}/dist/css/bootstrap.min.css" \
  -o assets/vendor/bootstrap/css/bootstrap.min.css
curl -fsSL "${CDN}/bootstrap@${BOOTSTRAP_VER}/dist/js/bootstrap.bundle.min.js" \
  -o assets/vendor/bootstrap/js/bootstrap.bundle.min.js

echo "Bootstrap Icons ${ICONS_VER}..."
curl -fsSL "${CDN}/bootstrap-icons@${ICONS_VER}/font/bootstrap-icons.min.css" \
  -o assets/vendor/bootstrap-icons/bootstrap-icons.min.css
curl -fsSL "${CDN}/bootstrap-icons@${ICONS_VER}/font/fonts/bootstrap-icons.woff2" \
  -o assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2
curl -fsSL "${CDN}/bootstrap-icons@${ICONS_VER}/font/fonts/bootstrap-icons.woff" \
  -o assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff

echo "Fabric.js ${FABRIC_VER}..."
curl -fsSL "${CDN}/fabric@${FABRIC_VER}/dist/index.min.js" \
  -o assets/vendor/fabric/fabric.min.js

echo "Done. Vendor assets are in assets/vendor/"

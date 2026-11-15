#!/usr/bin/env bash
# Regenerates public/assets/img/emblem-*.png and logo-complet*.jpg from the source logo (needs ffmpeg).
set -euo pipefail
cd "$(dirname "$0")/.."
SRC=docs/conception/maquettes/logo-mmmgci-mohs.jpg
OUT=public/assets/img
mkdir -p "$OUT"
for s in 40 80 160 320; do
  h=$((s / 2)); r=$((h - 1 - s / 320)) # 158 px at 320, as in the approved preview
  ffmpeg -v error -y -i "$SRC" -vf "crop=690:690:172:2,scale=$s:$s:flags=lanczos,format=rgba,geq=r='r(X,Y)':g='g(X,Y)':b='b(X,Y)':a='if(lte(hypot(X-$h,Y-$h),$r),255,0)'" -frames:v 1 "$OUT/emblem-$s.png"
done
ffmpeg -v error -y -i "$SRC" -vf scale=-2:104:flags=lanczos -q:v 3 -frames:v 1 "$OUT/logo-complet.jpg"
ffmpeg -v error -y -i "$SRC" -vf scale=-2:208:flags=lanczos -q:v 3 -frames:v 1 "$OUT/logo-complet@2x.jpg"

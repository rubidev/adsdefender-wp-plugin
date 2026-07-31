#!/usr/bin/env bash
# Phát hành một phiên bản AdsDefender.
#
#   ./release.sh            → dùng version hiện tại trong adsdefender.php
#   ./release.sh 2.5.113    → bump lên version chỉ định rồi phát hành
#
# Chuỗi việc: bump (nếu có) → sinh adsdefender-update.json → commit → tag
#             → đóng gói ZIP đúng cấu trúc adsdefender/ → tạo GitHub release.
#
# Các site khách đọc adsdefender-update.json trên nhánh main qua raw.githubusercontent
# nên chỉ cần push là chúng thấy bản mới — không phải upload đi đâu nữa.

set -euo pipefail
cd "$(dirname "$0")"

REPO="rubidev/adsdefender-wp-plugin"
MAIN="adsdefender.php"

# ── 1. Bump version nếu được truyền vào ──────────────────────────────────────
if [ $# -ge 1 ]; then
  NEW="$1"
  echo "$NEW" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$' || { echo "Version không hợp lệ: $NEW"; exit 1; }
  sed -i -E "s/^( \* Version:      ).*/\1${NEW}/" "$MAIN"
  sed -i -E "s/(define\('ADSDEFENDER_VERSION',\s*')[0-9.]+('\);)/\1${NEW}\2/" "$MAIN"
  echo "Đã bump lên $NEW"
fi

VER=$(sed -nE "s/^define\('ADSDEFENDER_VERSION', *'([0-9.]+)'\);.*/\1/p" "$MAIN")
[ -n "$VER" ] || { echo "Không đọc được version"; exit 1; }
TAG="v${VER}"
ZIP="adsdefender-${VER}.zip"

# Kiểm tra header và hằng số khớp nhau — lệch là site khách không nhận update
HDR=$(sed -nE 's/^ \* Version: +([0-9.]+).*/\1/p' "$MAIN")
[ "$HDR" = "$VER" ] || { echo "Lệch version: header=$HDR hằng=$VER"; exit 1; }

git rev-parse "$TAG" >/dev/null 2>&1 && { echo "Tag $TAG đã tồn tại"; exit 1; }

echo "→ Phát hành $TAG"

# ── 2. Sinh adsdefender-update.json từ header của plugin ─────────────────────
python - "$VER" <<'PYEOF'
import re, io, json, sys
ver = sys.argv[1]
src = io.open('adsdefender.php', encoding='utf-8').read()
block = re.search(r'CHANGELOG.*?═+\n(.*?)\n\s*\*/', src, re.S)
lines = []
if block:
    for l in block.group(1).split('\n'):
        l = re.sub(r'^\s*\*\s?', '', l).rstrip()
        if l.strip(): lines.append(l)
        if len(lines) >= 16: break
base = "https://github.com/rubidev/adsdefender-wp-plugin"
data = {
  "version": ver,
  "download_url": "%s/releases/download/v%s/adsdefender-%s.zip" % (base, ver, ver),
  "details_url":  "%s/releases/tag/v%s" % (base, ver),
  "requires": "5.6", "tested": "6.8", "requires_php": "7.4",
  "changelog": '\n'.join(lines),
}
io.open('adsdefender-update.json','w',encoding='utf-8').write(
    json.dumps(data, ensure_ascii=False, indent=2) + '\n')
PYEOF

# ── 3. Commit + tag + push ───────────────────────────────────────────────────
git add -A
git diff --cached --quiet || git commit -m "Phát hành ${TAG}"
git tag -a "$TAG" -m "AdsDefender ${TAG}"
git push origin main
git push origin "$TAG"

# ── 4. Đóng gói ZIP với đúng thư mục adsdefender/ ────────────────────────────
# ZIP tự động của GitHub giải nén ra "<repo>-<tag>/" nên không dùng được.
BUILD=$(mktemp -d)
mkdir -p "$BUILD/adsdefender"
git archive "$TAG" | tar -x -C "$BUILD/adsdefender"
# Loại các file chỉ phục vụ phát triển, không thuộc plugin
rm -f "$BUILD/adsdefender/.gitignore" \
      "$BUILD/adsdefender/release.sh" \
      "$BUILD/adsdefender/adsdefender-update.json"

( cd "$BUILD" && 7z a -tzip -mx=9 "$ZIP" adsdefender >/dev/null )

python - "$BUILD/$ZIP" <<'PYEOF'
import zipfile, sys
z = zipfile.ZipFile(sys.argv[1]); n = z.namelist()
assert all('\\' not in x for x in n), 'ZIP có backslash'
assert 'adsdefender/adsdefender.php' in n, 'thiếu file chính'
assert z.testzip() is None, 'ZIP hỏng'
print('   ZIP OK - %d entries' % len(n))
PYEOF

# ── 5. Tạo GitHub release ────────────────────────────────────────────────────
gh release create "$TAG" "$BUILD/$ZIP" --repo "$REPO" \
   --title "AdsDefender ${TAG}" \
   --notes "Xem changelog trong adsdefender-update.json hoặc header của plugin."

rm -rf "$BUILD"
echo "✅ Xong $TAG — các site sẽ nhận update trong ~1 giờ (cache transient)."

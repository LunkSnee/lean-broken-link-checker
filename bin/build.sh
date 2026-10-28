#!/usr/bin/env bash
# Build the installable plugin package:
#   dist/lean-broken-link-checker-<version>.zip  (upload via Plugins > Add New > Upload Plugin)
#   dist/lean-broken-link-checker-<version>.zip.sha256
#
# The zip holds one top-level lean-broken-link-checker/ folder with only the runtime files
# listed in FILES. File order, timestamps and permissions are fixed, so the same commit
# always builds a byte-identical zip (timestamps come from SOURCE_DATE_EPOCH, else the
# last commit, else now).
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG=lean-broken-link-checker
FILES=(lean-broken-link-checker.php uninstall.php readme.txt LICENSE includes assets)

bin/version.sh check
version=$(bin/version.sh)
epoch=${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct 2>/dev/null || date +%s)}
zip_path=dist/$SLUG-$version.zip

mkdir -p dist
rm -f "$zip_path" "$zip_path.sha256"

python3 - "$SLUG" "$zip_path" "$epoch" "${FILES[@]}" <<'EOF'
import os, sys, time, zipfile

slug, zip_path, epoch, roots = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4:]
stamp = time.gmtime(max(epoch, 315532800))[:6]  # zip cannot store dates before 1980

paths = []
for root in roots:
    if os.path.isdir(root):
        for d, _, names in os.walk(root):
            paths += [os.path.join(d, n) for n in names]
    else:
        paths.append(root)

with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    dirs = {''}
    for p in paths:
        d = os.path.dirname(p)
        while d:
            dirs.add(d)
            d = os.path.dirname(d)
    dirs = sorted(dirs)
    for d in dirs:
        info = zipfile.ZipInfo(f'{slug}/{d}/' if d else f'{slug}/', stamp)
        info.external_attr = (0o40755 << 16) | 0x10
        z.writestr(info, b'')
    for p in sorted(paths):
        info = zipfile.ZipInfo(f'{slug}/{p}', stamp)
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        with open(p, 'rb') as f:
            z.writestr(info, f.read())
EOF

(cd dist && sha256sum "$(basename "$zip_path")" > "$(basename "$zip_path").sha256")
echo "built $zip_path"
cat "$zip_path.sha256"

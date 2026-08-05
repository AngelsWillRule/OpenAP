#!/usr/bin/env bash

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

section() {
    printf '\n==> %s\n' "$1"
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        printf 'Required command not found: %s\n' "$1" >&2
        exit 1
    fi
}

for command_name in bash git msgfmt node php python3; do
    require_command "$command_name"
done

section "Repository hygiene"
git diff --check

if git ls-files | grep -Eq '(^|/)(node_modules|__pycache__)(/|$)|\.pyc$'; then
    printf 'Generated dependency or cache files are tracked.\n' >&2
    exit 1
fi

secret_pattern='gh[pousr]_[A-Za-z0-9_]{20,}|BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|/home/tacco|57\.131\.21\.254|192\.168\.1\.(22|44|81|95|101|112)|toyotaaygo'
if git grep -I -n -E "$secret_pattern" -- . ':(exclude)tests/ci-checks.sh'; then
    printf 'Possible private infrastructure data or credential found.\n' >&2
    exit 1
fi

section "PHP syntax"
while IFS= read -r -d '' source_file; do
    php -l "$source_file" >/dev/null
done < <(find . -path ./.git -prune -o -type f -name '*.php' -print0)

section "Installed version metadata"
version_value="$(tr -d '\r\n' < VERSION)"
[[ "$version_value" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$ ]]
php -r '
require "includes/openap_version.php";
$path = tempnam(sys_get_temp_dir(), "openap-version-");
file_put_contents($path, "version=0.2.0\nrevision=9b264faf\n");
if (openapInstalledVersion($path) !== "0.2.0 (9b264faf)") exit(1);
file_put_contents($path, "version=0.2.0\n");
if (openapInstalledVersion($path) !== "0.2.0") exit(1);
file_put_contents($path, "version=<invalid>\n");
if (openapInstalledVersion($path) !== "Not available") exit(1);
unlink($path);
if (openapInstalledVersion($path) !== "Not available") exit(1);
'

section "Shell syntax"
while IFS= read -r -d '' source_file; do
    bash -n "$source_file"
done < <(find . -path ./.git -prune -o -type f -name '*.sh' -print0)
bash -n openap-installer/bin/openap-install
python3 -c 'compile(open("openap-installer/bin/openap-detect", encoding="utf-8").read(), "openap-detect", "exec")'

section "JSON syntax"
while IFS= read -r -d '' source_file; do
    python3 -m json.tool "$source_file" >/dev/null
done < <(find . -path ./.git -prune -o -type f -name '*.json' -print0)

section "JavaScript module syntax"
while IFS= read -r -d '' source_file; do
    node --input-type=module --check <"$source_file"
done < <(find app/js -type f -name '*.js' ! -name '*.min.js' -print0)

section "Translations"
compiled_mo="$(mktemp)"
dry_run_log="$(mktemp)"
cleanup() {
    rm -f -- "$compiled_mo" "$dry_run_log"
}
trap cleanup EXIT

while IFS= read -r -d '' po_file; do
    mo_file="${po_file%.po}.mo"
    test -s "$mo_file"
    msgfmt --check --check-format -o "$compiled_mo" "$po_file"
    cmp --silent "$compiled_mo" "$mo_file"
done < <(find locale -type f -name '*.po' -print0)

section "Installer dry run"
openap-installer/bin/openap-install --yes >"$dry_run_log"
grep -Fq 'No changes were made.' "$dry_run_log"
grep -Fq 'DRY-RUN: write OpenAP version metadata to /etc/openap/release' "$dry_run_log"

printf '\nAll CI checks passed.\n'

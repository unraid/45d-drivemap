#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

HOST=""
METHOD="auto"
PLUGIN_DIR="/usr/local/emhttp/plugins/45homelab"
INCLUDE_ASSETS=0
INCLUDE_DEV_PAGES=0
DRY_RUN=0
NO_VERIFY=0
EXTRA_PATHS=()

usage() {
  cat <<'USAGE'
Usage:
  dev/live-deploy.sh --host user@host [options]

Purpose:
  Copy local plugin source files directly into a live Unraid plugin directory.

Options:
  --host <user@host|host>
                         Remote SSH target (required). If user is omitted,
                         defaults to root@host.
  --method <auto|rsync|scp>
                         Transfer method (default: auto)
  --plugin-dir <path>    Remote plugin path
                         (default: /usr/local/emhttp/plugins/45homelab)
  --include-assets       Include assets/ (frontend bundle)
  --include-dev-pages    Include dev-only pages
                         (DriveMapDevTools.page, DriveMapDevToolsSimulator.page)
  --extra-path <path>    Add repo-relative path to deploy (repeatable)
  --dry-run              Show what would be deployed and exit
  --no-verify            Skip remote post-deploy checks
  -h, --help             Show this help

Examples:
  dev/live-deploy.sh --host root@192.168.1.201
  dev/live-deploy.sh --host 192.168.1.201 --include-assets
  dev/live-deploy.sh --host root@192.168.1.201 --extra-path DriveMapDevToolsSimulator.page
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --host)
      HOST="${2:-}"
      shift 2
      ;;
    --method)
      METHOD="${2:-}"
      shift 2
      ;;
    --plugin-dir)
      PLUGIN_DIR="${2:-}"
      shift 2
      ;;
    --include-assets)
      INCLUDE_ASSETS=1
      shift
      ;;
    --include-dev-pages)
      INCLUDE_DEV_PAGES=1
      shift
      ;;
    --extra-path)
      EXTRA_PATHS+=("${2:-}")
      shift 2
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --no-verify)
      NO_VERIFY=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "${HOST}" ]]; then
  echo "--host is required" >&2
  usage >&2
  exit 2
fi

if [[ "${HOST}" != *"@"* ]]; then
  HOST="root@${HOST}"
fi

if [[ "${METHOD}" != "auto" && "${METHOD}" != "rsync" && "${METHOD}" != "scp" ]]; then
  echo "--method must be one of: auto, rsync, scp" >&2
  exit 2
fi

if [[ "${METHOD}" == "auto" ]]; then
  if command -v rsync >/dev/null 2>&1; then
    METHOD="rsync"
  else
    METHOD="scp"
  fi
fi

if [[ "${METHOD}" == "rsync" ]] && ! command -v rsync >/dev/null 2>&1; then
  echo "rsync is not available locally; use --method scp" >&2
  exit 1
fi

if [[ "${METHOD}" == "scp" ]] && ! command -v scp >/dev/null 2>&1; then
  echo "scp is not available locally; use --method rsync" >&2
  exit 1
fi

paths=(
  "DriveMap.page"
  "plugin.cfg"
  "php"
  "scripts"
)

if [[ "${INCLUDE_ASSETS}" -eq 1 ]]; then
  paths+=("assets")
fi

if [[ "${INCLUDE_DEV_PAGES}" -eq 1 ]]; then
  paths+=("DriveMapDevTools.page" "DriveMapDevToolsSimulator.page")
fi

for rel in "${EXTRA_PATHS[@]:-}"; do
  if [[ -n "${rel}" ]]; then
    paths+=("${rel}")
  fi
done

for rel in "${paths[@]}"; do
  if [[ ! -e "${ROOT_DIR}/${rel}" ]]; then
    echo "Local path not found: ${rel}" >&2
    exit 2
  fi
done

echo "Host:        ${HOST}"
echo "Method:      ${METHOD}"
echo "Plugin dir:  ${PLUGIN_DIR}"
echo "Deploy paths:"
for rel in "${paths[@]}"; do
  echo "  - ${rel}"
done

if [[ "${DRY_RUN}" -eq 1 ]]; then
  echo "Dry run enabled; no changes applied."
  exit 0
fi

echo "[1/3] Ensuring remote plugin directory exists"
ssh "${HOST}" "mkdir -p '${PLUGIN_DIR}'"

echo "[2/3] Deploying files via ${METHOD}"
if [[ "${METHOD}" == "rsync" ]]; then
  for rel in "${paths[@]}"; do
    src="${ROOT_DIR}/${rel}"
    if [[ -d "${src}" ]]; then
      rsync -az --delete --exclude '__pycache__' --exclude '.DS_Store' \
        "${src}/" "${HOST}:${PLUGIN_DIR}/${rel}/"
    else
      rsync -az --exclude '.DS_Store' \
        "${src}" "${HOST}:${PLUGIN_DIR}/${rel}"
    fi
  done
else
  tmp_tar=$(mktemp "/tmp/45d-live-deploy.XXXXXX.tgz")
  remote_tar="/tmp/45d-live-deploy-$$.tgz"
  trap 'rm -f "${tmp_tar}"' EXIT
  COPYFILE_DISABLE=1 tar -C "${ROOT_DIR}" -czf "${tmp_tar}" "${paths[@]}"
  scp "${tmp_tar}" "${HOST}:${remote_tar}"
  ssh "${HOST}" "mkdir -p '${PLUGIN_DIR}' && tar -xzf '${remote_tar}' -C '${PLUGIN_DIR}' && rm -f '${remote_tar}'"
  rm -f "${tmp_tar}"
  trap - EXIT
fi

if [[ "${NO_VERIFY}" -eq 1 ]]; then
  echo "[3/3] Deploy complete (verification skipped)"
  exit 0
fi

echo "[3/3] Verifying remote paths"
for rel in "${paths[@]}"; do
  ssh "${HOST}" "test -e '${PLUGIN_DIR}/${rel}'"
done

if ssh "${HOST}" "test -f '${PLUGIN_DIR}/php/api.php'"; then
  ssh "${HOST}" "php -l '${PLUGIN_DIR}/php/api.php' >/dev/null"
fi

echo "Live deploy complete."
echo "If the UI is already open, hard-refresh the browser tab."

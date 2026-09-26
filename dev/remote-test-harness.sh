#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

HOST=""
METHOD="auto"
REMOTE_DIR=""
KEEP_REMOTE=0
FULL_TESTS=0
SIM_FIXTURE=""
SIM_FIXTURE_ABS=""
REMOTE_SIM_FIXTURE=""

usage() {
  cat <<'USAGE'
Usage:
  dev/remote-test-harness.sh --host user@host [options]

Options:
  --host <user@host|host>
                         Remote SSH target (required). If user is omitted,
                         defaults to root@host.
  --method <auto|rsync|scp>
                         Transfer method (default: auto)
  --remote-dir <path>    Remote workspace path
                         (default: /tmp/45homelab-dev-<timestamp>-<pid>)
  --full                 Run full php tests/run.php (requires python3 + vendor tree)
                         If python3 is missing remotely, falls back to smoke tests.
  --simulate-fixture <path>
                         Run smoke tests using a custom alias fixture
                         (absolute path or repo-relative path)
  --keep-remote          Keep remote workspace after successful run
  -h, --help             Show this help

Examples:
  dev/remote-test-harness.sh --host root@192.168.1.201
  dev/remote-test-harness.sh --host root@192.168.1.201 --method rsync --full
  dev/remote-test-harness.sh --host root@192.168.1.201 --simulate-fixture tests/fixtures/vdev_id_h16_q30.conf
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
    --remote-dir)
      REMOTE_DIR="${2:-}"
      shift 2
      ;;
    --full)
      FULL_TESTS=1
      shift
      ;;
    --simulate-fixture)
      SIM_FIXTURE="${2:-}"
      shift 2
      ;;
    --keep-remote)
      KEEP_REMOTE=1
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

# Unraid shells are typically accessed as root. Accept bare host/IP for
# convenience by defaulting to root@ when no user is provided.
if [[ "${HOST}" != *"@"* ]]; then
  HOST="root@${HOST}"
fi

if [[ "${METHOD}" != "auto" && "${METHOD}" != "rsync" && "${METHOD}" != "scp" ]]; then
  echo "--method must be one of: auto, rsync, scp" >&2
  exit 2
fi

if [[ -z "${REMOTE_DIR}" ]]; then
  REMOTE_DIR="/tmp/45homelab-dev-$(date +%Y%m%d-%H%M%S)-$$"
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

if [[ -n "${SIM_FIXTURE}" ]]; then
  if [[ -f "${SIM_FIXTURE}" ]]; then
    SIM_FIXTURE_ABS="$(cd "$(dirname "${SIM_FIXTURE}")" && pwd)/$(basename "${SIM_FIXTURE}")"
  elif [[ -f "${ROOT_DIR}/${SIM_FIXTURE}" ]]; then
    SIM_FIXTURE_ABS="$(cd "${ROOT_DIR}" && cd "$(dirname "${SIM_FIXTURE}")" && pwd)/$(basename "${SIM_FIXTURE}")"
  else
    echo "simulate fixture not found: ${SIM_FIXTURE}" >&2
    exit 2
  fi
fi

echo "[1/4] Preparing remote workspace: ${HOST}:${REMOTE_DIR}"
ssh "${HOST}" "rm -rf '${REMOTE_DIR}' && mkdir -p '${REMOTE_DIR}'"

paths=(
  "DriveMap.page"
  "plugin.cfg"
  "php"
  "scripts"
  "tests"
)

if [[ "${FULL_TESTS}" -eq 1 ]]; then
  paths+=("vendor")
fi

echo "[2/4] Transferring test workspace via ${METHOD}"
if [[ "${METHOD}" == "rsync" ]]; then
  for rel in "${paths[@]}"; do
    if [[ -d "${ROOT_DIR}/${rel}" ]]; then
      rsync -az --delete --exclude '__pycache__' --exclude '.DS_Store' \
        "${ROOT_DIR}/${rel}/" "${HOST}:${REMOTE_DIR}/${rel}/"
    else
      rsync -az --exclude '.DS_Store' "${ROOT_DIR}/${rel}" "${HOST}:${REMOTE_DIR}/${rel}"
    fi
  done
else
  tmp_tar=$(mktemp "/tmp/45d-remote-harness.XXXXXX.tgz")
  trap 'rm -f "${tmp_tar}"' EXIT
  COPYFILE_DISABLE=1 tar -C "${ROOT_DIR}" -czf "${tmp_tar}" "${paths[@]}"
  scp "${tmp_tar}" "${HOST}:${REMOTE_DIR}/repo.tgz"
  ssh "${HOST}" "cd '${REMOTE_DIR}' && tar -xzf repo.tgz && rm -f repo.tgz"
  rm -f "${tmp_tar}"
  trap - EXIT
fi

if [[ -n "${SIM_FIXTURE_ABS}" ]]; then
  REMOTE_SIM_FIXTURE="${REMOTE_DIR}/tests/fixtures/_sim_alias.conf"
  echo "[2/4] Uploading simulation fixture to ${REMOTE_SIM_FIXTURE}"
  scp "${SIM_FIXTURE_ABS}" "${HOST}:${REMOTE_SIM_FIXTURE}"
fi

echo "[3/4] Running remote tests"
smoke_cmd='php tests/remote_smoke.php'
if [[ -n "${REMOTE_SIM_FIXTURE}" ]]; then
  smoke_cmd="DRIVEMAP_SIM_ALIAS_FIXTURE='${REMOTE_SIM_FIXTURE}' php tests/remote_smoke.php"
fi

if [[ "${FULL_TESTS}" -eq 1 ]]; then
  remote_cmd=$'if command -v python3 >/dev/null 2>&1; then\n'
  remote_cmd+=$'  php tests/run.php\n'
  remote_cmd+=$'else\n'
  remote_cmd+=$'  echo "python3 not available remotely; skipping tests/run.php"\n'
  remote_cmd+=$'fi\n'
  remote_cmd+="${smoke_cmd}"$'\n'
else
  remote_cmd="${smoke_cmd}"
fi

set +e
ssh "${HOST}" "cd '${REMOTE_DIR}' && ${remote_cmd}"
test_status=$?
set -e

if [[ "${test_status}" -ne 0 ]]; then
  echo "[4/4] Remote tests failed; workspace kept at ${HOST}:${REMOTE_DIR}" >&2
  exit "${test_status}"
fi

if [[ "${KEEP_REMOTE}" -eq 1 ]]; then
  echo "[4/4] Remote tests passed; workspace kept at ${HOST}:${REMOTE_DIR}"
else
  echo "[4/4] Remote tests passed; cleaning remote workspace"
  ssh "${HOST}" "rm -rf '${REMOTE_DIR}'"
fi

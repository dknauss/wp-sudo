#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd -P)"
DEFAULT_SITE_PLUGIN="/Users/danknauss/Development/Local Sites/multisite-subdomains/app/public/wp-content/plugins/wp-sudo"
SITE_PLUGIN="${SITE_PLUGIN:-${2:-$DEFAULT_SITE_PLUGIN}}"

EXCLUDES=(
  "--exclude=.git/"
  "--exclude=.github/"
  "--exclude=.wp-env/"
  "--exclude=.tmp/"
  "--exclude=node_modules/"
  "--exclude=playwright-report/"
  "--exclude=test-results/"
  "--exclude=tests/e2e/artifacts/"
)

usage() {
  cat <<EOF
Usage: $(basename "$0") <status|sync|link> [site-plugin-path]

Commands:
  status  Show whether the Local site plugin is copied or symlinked, and compare key PHP files.
  sync    Rsync this repo into the Local site plugin directory.
  link    Replace the Local site plugin directory with a symlink to this repo.

Environment:
  SITE_PLUGIN  Override the Local site plugin path.

Default site plugin path:
  $DEFAULT_SITE_PLUGIN
EOF
}

require_wp_sudo_path() {
  if [[ "$(basename "$SITE_PLUGIN")" != "wp-sudo" ]]; then
    printf 'Refusing to operate on non-wp-sudo path: %s\n' "$SITE_PLUGIN" >&2
    exit 1
  fi
}

compare_file() {
  local relative_path="$1"

  if [[ ! -e "$SITE_PLUGIN/$relative_path" ]]; then
    printf '%s: missing at site path\n' "$relative_path"
    return
  fi

  if cmp -s "$REPO_ROOT/$relative_path" "$SITE_PLUGIN/$relative_path"; then
    printf '%s: identical\n' "$relative_path"
  else
    printf '%s: differs\n' "$relative_path"
  fi
}

status() {
  require_wp_sudo_path

  printf 'Repo root: %s\n' "$REPO_ROOT"
  printf 'Site plugin: %s\n' "$SITE_PLUGIN"

  if [[ ! -e "$SITE_PLUGIN" && ! -L "$SITE_PLUGIN" ]]; then
    printf 'Status: missing\n'
    return
  fi

  if [[ -L "$SITE_PLUGIN" ]]; then
    printf 'Mode: symlink\n'
    printf 'Symlink target: %s\n' "$(readlink "$SITE_PLUGIN")"
  else
    printf 'Mode: copied directory\n'
  fi

  printf 'Resolved target: %s\n' "$(cd "$SITE_PLUGIN" && pwd -P)"
  compare_file "includes/class-plugin.php"
  compare_file "includes/class-gate.php"
  compare_file "wp-sudo.php"
}

sync() {
  require_wp_sudo_path
  mkdir -p "$(dirname "$SITE_PLUGIN")"

  if [[ -L "$SITE_PLUGIN" ]]; then
    printf 'Site plugin is a symlink; sync is unnecessary.\n'
    status
    return
  fi

  mkdir -p "$SITE_PLUGIN"
  rsync -a --delete "${EXCLUDES[@]}" "$REPO_ROOT/" "$SITE_PLUGIN/"
  status
}

link() {
  require_wp_sudo_path
  mkdir -p "$(dirname "$SITE_PLUGIN")"

  if [[ -L "$SITE_PLUGIN" ]]; then
    local resolved_target
    resolved_target="$(cd "$SITE_PLUGIN" && pwd -P)"
    if [[ "$resolved_target" == "$REPO_ROOT" ]]; then
      printf 'Site plugin already symlinks to repo.\n'
      status
      return
    fi

    rm "$SITE_PLUGIN"
    ln -s "$REPO_ROOT" "$SITE_PLUGIN"
    status
    return
  fi

  if [[ -e "$SITE_PLUGIN" ]]; then
    local backup_path
    backup_path="${SITE_PLUGIN}.backup-$(date +%Y%m%d-%H%M%S)"
    mv "$SITE_PLUGIN" "$backup_path"
    printf 'Backed up copied plugin directory to: %s\n' "$backup_path"
  fi

  ln -s "$REPO_ROOT" "$SITE_PLUGIN"
  status
}

main() {
  local command="${1:-}"

  case "$command" in
    status)
      status
      ;;
    sync)
      sync
      ;;
    link)
      link
      ;;
    *)
      usage
      exit 1
      ;;
  esac
}

main "$@"

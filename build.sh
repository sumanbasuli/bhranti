#!/usr/bin/env bash
# The only production package entry point. Uses a runtime allowlist.
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec python3 "$SOURCE_DIR/scripts/build.py" "$@"

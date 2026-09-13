#!/usr/bin/env bash
# PostToolUse hook (Write|Edit): lints the API contract right after docs/openapi.yaml is modified.
# Any other file: exits immediately. Lint failure: exit code 2, so Claude receives the errors.
set -uo pipefail

file_path=$(python3 -c 'import json, sys; print(json.load(sys.stdin).get("tool_input", {}).get("file_path", ""))')

case "$file_path" in
  */docs/openapi.yaml | docs/openapi.yaml) ;;
  *) exit 0 ;;
esac

cd "${CLAUDE_PROJECT_DIR:-.}" || exit 0

if ! output=$(npx --yes @redocly/cli@2.52.1 lint 2>&1); then
  echo "docs/openapi.yaml no longer passes the Redocly lint:" >&2
  echo "$output" >&2
  exit 2
fi

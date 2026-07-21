#!/usr/bin/env bash
# Blocks Bash commands that would read/print real secret files (.env, key files).
# Reads the tool payload from stdin (Claude Code PreToolUse hook contract).
set -euo pipefail
payload="$(cat)"
cmd="$(printf '%s' "$payload" | /usr/bin/python3 -c 'import sys,json;print(json.load(sys.stdin).get("tool_input",{}).get("command",""))' 2>/dev/null || true)"

# Allow reading .env.example; block real .env and private keys.
if printf '%s' "$cmd" | grep -Eiq '(^|[^.])\.env([^.]|$)|\.env\.(production|staging|local)|oauth-private\.key|/secrets/|id_rsa'; then
  if ! printf '%s' "$cmd" | grep -Eiq '\.env\.example'; then
    echo "BLOCKED: command appears to access secret material. Use .env.example instead." >&2
    exit 2   # exit 2 = block the tool call
  fi
fi
exit 0

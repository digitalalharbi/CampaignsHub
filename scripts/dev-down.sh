#!/usr/bin/env bash
# Stop the full dev environment cleanly (no zombies left behind).
set -uo pipefail
echo "== stopping dev environment =="
lsof -ti tcp:8000 | xargs kill -9 2>/dev/null
lsof -ti tcp:5173 | xargs kill -9 2>/dev/null
pkill -9 -f "artisan serve" 2>/dev/null
pkill -9 -f "queue:work" 2>/dev/null
pkill -9 -f "schedule:work" 2>/dev/null
pkill -9 -f "vite" 2>/dev/null
echo "stopped."

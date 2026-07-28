#!/usr/bin/env bash
# Quick liveness of the fixed-port dev environment.
set -uo pipefail
p(){ lsof -ti tcp:"$1" >/dev/null 2>&1 && echo "Running" || echo "Stopped"; }
proc(){ pgrep -f "$1" >/dev/null 2>&1 && echo "Running" || echo "Stopped"; }
echo "Frontend  (5173): $(p 5173)"
echo "Backend   (8000): $(p 8000)"
echo "Queue worker    : $(proc 'queue:work')"
echo "Scheduler       : $(proc 'schedule:work')"
echo "Redis           : $(redis-cli ping >/dev/null 2>&1 && echo Running || echo Stopped)"
echo "Backend /up     : $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/up 2>/dev/null)"
echo "dev/status API  : $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/api/v1/dev/status 2>/dev/null)"

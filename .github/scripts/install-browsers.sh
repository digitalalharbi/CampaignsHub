#!/usr/bin/env bash
# BROWSER-INSTALL-PREFETCH-001 — install the gate's browsers and their system packages, and stay fast when the
# runner's apt mirror is not.
#
# usage: install-browsers.sh <browser>     (run from `frontend/`, where Playwright is installed)
#
# ## Why this is a script and not an inline step
#
# `playwright install-deps` hands the system packages to ONE sequential `apt-get`: 166 packages, 109 MB, one
# connection, one file at a time. On a healthy mirror that is a minute. On a degraded one (#798, 2026-10-22,
# azure.archive.ubuntu.com) the package INDEX came down at 9.7 MB/s while individual packages stalled for
# 30-90 s each — `fonts-wqy-zenhei` (7 MB) took 90 s, `libcodec2` (9 MB) was still going after a minute — so
# the sequential download needed more than the thirty minutes the step was given, and the gate failed in
# «Browsers» with no test run. Waiting longer is not a fix; it is a longer wait for the same stall.
#
# ## What it does instead
#
# 1. apt/dpkg must be idle, or the step FAILS naming the holder (#821: a retry racing the previous apt-get's
#    lock is how three gates died in seconds). Nothing here kills apt.
# 2. The packages Playwright would install are listed (`install-deps --dry-run`), resolved to download URLs
#    (`apt-get install --print-uris`), and fetched IN PARALLEL with per-file timeouts, retries and a second mirror
#    for any file the first one stalls on. A stall now costs one slot for a few seconds instead of the whole run.
#    The files go to $APT_DEBS_DIR, which CI caches (and saves even when the job fails, so a retried job resumes
#    instead of starting over) and are then placed where apt looks for archives.
# 3. `install-deps` runs ONCE, as before. apt verifies every archive against the signed package index whether it
#    downloaded it or found it, so the prefetch can only make the install faster, never different. If anything is
#    still missing apt fetches it itself; if the install fails, the step FAILS. The prefetch is the only
#    best-effort part, and it is best-effort because it is an optimisation: the verdict is the install.
# 4. Only the browser DOWNLOAD (a plain fetch, no lock) keeps its three bounded attempts.
set -uo pipefail

browser=${1:?usage: install-browsers.sh <browser>}
DEBS=${APT_DEBS_DIR:-$HOME/apt-debs}
JOBS=${PREFETCH_JOBS:-12}
mkdir -p "$DEBS"

PREFETCH_TIMEOUT=${PREFETCH_TIMEOUT:-600}
out() { [ -n "${GITHUB_OUTPUT:-}" ] && echo "$1=$2" >> "$GITHUB_OUTPUT"; return 0; }
say() { printf '::group::%s\n' "$*"; }
end() { printf '::endgroup::\n'; }

# --- 1. never race apt ------------------------------------------------------------------------------------------
apt_idle() {
  for _ in $(seq 1 120); do
    pgrep -x 'apt-get|apt|dpkg|unattended-upgr' >/dev/null 2>&1 || return 0
    sleep 5
  done
  echo "::error::apt/dpkg still running after ten minutes: $(pgrep -a -x 'apt-get|apt|dpkg|unattended-upgr' | head -3 | tr '\n' ';')"
  return 1
}
apt_idle || exit 1

# A slow mirror is made less slow: short per-request timeouts and retries turn a stalled connection into a fresh one.
printf 'Acquire::Retries "3";\nAcquire::http::Timeout "20";\nAcquire::https::Timeout "20";\n' | sudo tee /etc/apt/apt.conf.d/99ci-mirror >/dev/null

# --- 2. prefetch the system packages, in parallel ----------------------------------------------------------------
prefetch() {
  local started=$SECONDS dry pkgs uris want have
  say "prefetch system packages for chromium + $browser"
  # `--dry-run` prints «Missing system dependencies (N):» and one indented package per line, and exits non-zero while
  # any are missing — that is its answer, not a failure — so the status is ignored and the list is read.
  dry=$(npx playwright install-deps --dry-run chromium "$browser" 2>&1 || true)
  pkgs=$(printf '%s\n' "$dry" | sed -n 's/^  \([a-z0-9][a-z0-9+.:-]*\)$/\1/p' | tr '\n' ' ')
  [ -n "$pkgs" ] || { echo "could not read a package list from: $(printf '%s' "$dry" | head -c 400)"; end; return 1; }
  echo "packages named by Playwright: $(printf '%s\n' $pkgs | wc -l | tr -d ' ')"

  sudo apt-get update -qq >/dev/null 2>&1 || true
  # shellcheck disable=SC2086
  uris=$(sudo apt-get install --print-uris -y -qq --no-install-recommends $pkgs 2>/dev/null | grep "^'" || true)
  want=$(printf '%s\n' "$uris" | grep -c . || true)
  echo "archives apt would download: $want"
  [ "$want" -gt 0 ] || { echo "nothing to fetch (already installed, or the resolver printed no URIs)"; end; return 0; }

  # One line per archive: <url> <file> <size>. Skip what the cache already holds at the right size.
  printf '%s\n' "$uris" | awk '{ gsub(/'"'"'/, "", $1); print $1, $2, $3 }' > /tmp/apt-uris.txt
  have=0
  : > /tmp/apt-todo.txt
  while read -r url file size; do
    if [ -s "$DEBS/$file" ] && [ "$(wc -c < "$DEBS/$file" | tr -d ' ')" = "$size" ]; then have=$((have + 1)); else echo "$url $file $size" >> /tmp/apt-todo.txt; fi
  done < /tmp/apt-uris.txt
  echo "already cached: $have, to fetch: $(grep -c . /tmp/apt-todo.txt || true)"

  # Each worker: the mirror apt chose, then a second mirror for the same pool path; a size check before the rename.
  export DEBS
  # apt does not hand out plain URLs on these runners: it says `mirror+file:/etc/apt/apt-mirrors.txt/pool/...`, meaning
  # «this pool path, from whichever mirror that file lists» (the first run of this script failed every archive on exactly
  # that, `Protocol "mirror+file" not supported`). So the path is taken from the URI and tried against each listed mirror,
  # then against the public Ubuntu archive, so one slow or failing mirror costs a few seconds and not the install.
  PREFETCH_MIRRORS=$( { grep -Eo '^https?://[^[:space:]]+' /etc/apt/apt-mirrors.txt 2>/dev/null; printf '%s\n' 'http://archive.ubuntu.com/ubuntu/' 'http://security.ubuntu.com/ubuntu/'; } | awk '!seen[$0]++')
  export PREFETCH_MIRRORS
  candidates() {
    local url=$1 path m
    case "$url" in
      mirror+file:*) path=${url#*apt-mirrors.txt/}
                     while read -r m; do [ -n "$m" ] && printf '%s%s\n' "${m%/}/" "$path"; done <<< "$PREFETCH_MIRRORS" ;;
      *) printf '%s\n' "$url" ;;
    esac
  }
  fetch_one() {
    local url=$1 file=$2 size=$3 u err got=
    while read -r u; do
      err=$(curl -fsSL --retry 2 --retry-delay 1 --connect-timeout 10 --max-time 90 -o "$DEBS/$file.part" "$u" 2>&1) || { got="curl: ${err:-failed} ($u)"; continue; }
      if [ "$(wc -c < "$DEBS/$file.part" | tr -d ' ')" = "$size" ]; then mv "$DEBS/$file.part" "$DEBS/$file"; return 0; fi
      got="size mismatch for $file (wanted $size, got $(wc -c < "$DEBS/$file.part" | tr -d ' ')) from $u"
    done < <(candidates "$url")
    rm -f "$DEBS/$file.part"
    # One summary line per failure is enough to diagnose; the count is printed after the run.
    echo "FAILED $file :: ${got:-no candidate URL}" >> "$DEBS.failures"
    return 0
  }
  export -f candidates
  export -f fetch_one
  : > "$DEBS.failures"
  # Whatever happens next (the job timing out, the step being killed, install-deps dying), progress so far is worth caching:
  # `fetched` is set BEFORE the first download and corrected afterwards, so a prefetch that never reaches its summary still saves.
  out fetched 1
  # Bounded on its own: a prefetch that cannot finish in ten minutes hands the rest to apt rather than eating the step.
  timeout "$PREFETCH_TIMEOUT" xargs -P "$JOBS" -L1 bash -c 'fetch_one "$0" "$1" "$2"' < /tmp/apt-todo.txt \
    || echo "::warning::prefetch stopped after ${PREFETCH_TIMEOUT}s (or xargs failed); the archives already held are kept and apt fetches the rest"
  rm -f "$DEBS"/*.part
  if [ -s "$DEBS.failures" ]; then
    echo "$(wc -l < "$DEBS.failures" | tr -d ' ') archives could not be prefetched (apt fetches them itself); first reasons:"
    head -3 "$DEBS.failures" | cut -c1-300
  fi

  # Where apt looks for archives. `cp -n`: an archive apt already holds is not replaced.
  sudo cp -n "$DEBS"/*.deb /var/cache/apt/archives/ 2>/dev/null || true
  echo "prefetch took $((SECONDS - started)) s; $(find "$DEBS" -name '*.deb' | wc -l | tr -d ' ') archives held"
  # What the workflow and the probe need to know, written now so it survives a later failure: how many archives were
  # wanted, how many are now held at the right size, and how many of those are new (worth caching).
  held=0
  while read -r _ file size; do
    [ -s "$DEBS/$file" ] && [ "$(wc -c < "$DEBS/$file" | tr -d ' ')" = "$size" ] && held=$((held + 1))
  done < /tmp/apt-uris.txt
  echo "prefetch result: $held of $want archives held at the right size"
  out wanted "$want"; out prefetched "$held"
  out fetched "$(find "$DEBS" -name '*.deb' -newer /tmp/apt-uris.txt | wc -l | tr -d ' ')"
  end
}
prefetch || echo "::warning::prefetch skipped; install-deps will download sequentially (slower, same result)"
# Probe-only: stop here, as a dying install step would, so the cache-recovery path can be exercised on purpose.
if [ -n "${STOP_AFTER_PREFETCH:-}" ]; then echo "STOP_AFTER_PREFETCH set: stopping before install-deps"; exit 75; fi

# --- 3. system dependencies, ONCE; a failure is a failure ----------------------------------------------------------
say "install-deps (once)"
if ! timeout 1500 npx playwright install-deps chromium "$browser"; then
  echo "::error::system dependencies failed to install once; not retrying apt (a retry races the lock the failed run may still hold)"
  pgrep -a -x 'apt-get|apt|dpkg' || true
  exit 1
fi
end

# --- 4. the browsers themselves: a plain download, three bounded attempts ------------------------------------------
for attempt in 1 2 3; do
  if timeout 480 npx playwright install chromium "$browser"; then exit 0; fi
  echo "::warning::browser download attempt ${attempt} stalled or failed — retrying the download only"
  sleep 15
done
echo "::error::browser download failed three times; the runner could not reach the Playwright CDN"
exit 1

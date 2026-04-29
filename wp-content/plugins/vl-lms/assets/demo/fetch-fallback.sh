#!/usr/bin/env bash
#
# fetch-fallback.sh — pre-fetch the bundled fallback images used by
# `wp vl-lms demo seed` when Picsum is unreachable.
#
# Idempotent: every download skips files that already exist on disk.
# Run once on a developer machine that anticipates seeding without
# internet access.
#
# Author: Tymofii Synianskyi

set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"

download() {
  local key="$1"
  local width="$2"
  local height="$3"
  local target="$4"

  if [ -f "${DIR}/${target}" ]; then
    echo "skip: ${target} (already exists)"
    return 0
  fi

  local url="https://picsum.photos/seed/${key}/${width}/${height}"
  echo "fetch: ${target} <- ${url}"
  curl --silent --show-error --location --fail --output "${DIR}/${target}" "${url}"
}

# Course covers: 1920x720.
for i in 1 2 3 4 5 6 7 8; do
  download "gp-course-${i}" 1920 720 "cover-${i}.jpg"
done

# Instructor avatars: 400x400.
for i in 1 2 3; do
  download "gp-instructor-${i}" 400 400 "avatar-${i}.jpg"
done

echo "done."

#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

echo "Running safety check for dangerous DB workflows..."

# We search for any pattern that indicates dangerous tinker usage or destructive DB commands
# We allow the script itself to contain these strings by excluding it.
# We also exclude normal test files from some matches.

# Destructive commands that shouldn't appear outside of this script
DEST_COMMANDS="migrate:fresh\|migrate:refresh\|db:wipe\|schema:dump --prune"

# Tinker commands that combine with test traits
TINKER_TEST_TRAITS='Tests\\TestCase|RefreshDatabase|DatabaseMigrations|DatabaseTransactions'

# 1. Check for destructive commands in docs, scripts, tests, app, database, resources.
# AGENTS.md is intentionally excluded because it documents forbidden commands as policy text.
if grep -RIn "$DEST_COMMANDS" docs scripts tests app database resources \
  --exclude-dir=vendor \
  --exclude-dir=node_modules \
  --exclude-dir=.git \
  --exclude-dir=storage \
  --exclude="check-dangerous-db-workflows.sh" 2>/dev/null | grep -v "Never run migrate:fresh"; then
  echo "ERROR: Found destructive database commands in project files."
  exit 1
fi

# 2. Check for Tinker running Test traits.
# SAFE_TINKER_POLICY_FILTER_V1
# Explicit prohibition documentation is safe; real workflows still fail.
TINKER_TEST_TRAITS='Tests\\TestCase|RefreshDatabase|DatabaseMigrations|DatabaseTransactions'

TINKER_MATCHES="$(
  grep -RInE "tinker.*(${TINKER_TEST_TRAITS})|(${TINKER_TEST_TRAITS}).*tinker" AGENTS.md docs scripts gpt \
    --exclude-dir=vendor \
    --exclude-dir=node_modules \
    --exclude-dir=.git \
    --exclude-dir=storage \
    --exclude="check-dangerous-db-workflows.sh" 2>/dev/null || true
)"

if [ -n "$TINKER_MATCHES" ]; then
  TINKER_MATCHES="$(
    printf '%s\n' "$TINKER_MATCHES" \
      | grep -Eiv '(never[[:space:]]+run|do[[:space:]]+not[[:space:]]+run)[[:space:]]+.*tinker' \
      || true
  )"
fi

if [ -n "$TINKER_MATCHES" ]; then
  printf '%s\n' "$TINKER_MATCHES"
  echo "ERROR: Found dangerous tinker test trait workflows in project files."
  exit 1
fi
echo "Safety check passed."
exit 0

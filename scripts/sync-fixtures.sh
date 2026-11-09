#!/usr/bin/env bash
# Grafida Shell — PHP worker runtime.
#
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# MIT; see LICENSE
#
# Vendors the wire-protocol conformance fixtures from grafida/shell at a given commit.
#
#   scripts/sync-fixtures.sh [path-to-grafida-shell-clone] [ref]
#
# Defaults: ../shell, HEAD. It copies protocol/fixtures/ (every .bin/.json plus SHA256SUMS) *as
# committed at that ref*, not the working tree, and records the commit in protocol/FIXTURES_REF.
# tests/Conformance/FixtureManifestTest checks the copy against SHA256SUMS. CI compares it with
# grafida/shell at FIXTURES_REF, so the ref must be pushed before this repository's CI can pass.
#
# This only ever replaces the copy wholesale. A fixture that fails here is a question about which
# side is wrong, never something to fix by editing the vendored file.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
shell="${1:-$here/../shell}"
ref="${2:-HEAD}"

sha="$(git -C "$shell" rev-parse --verify "$ref^{commit}")"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

git -C "$shell" archive "$sha" protocol/fixtures | tar -x -C "$tmp"
(cd "$tmp/protocol/fixtures" && sha256sum --quiet -c SHA256SUMS)

rm -rf "$here/protocol/fixtures"
mkdir -p "$here/protocol"
cp -R "$tmp/protocol/fixtures" "$here/protocol/fixtures"
echo "$sha" > "$here/protocol/FIXTURES_REF"

echo "Vendored $(grep -c . "$here/protocol/fixtures/SHA256SUMS") fixture files from grafida/shell@$sha"

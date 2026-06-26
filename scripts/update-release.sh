#!/usr/bin/env bash
#
# update-release.sh — bump pinned upstream releases in hermes.plg
#
# Usage:
#   ./update-release.sh --latest
#   ./update-release.sh --agent-tag v2026.6.19 --webui-tag v0.51.688
#
# Computes tarball SHA256s from GitHub archive URLs, patches hermes.plg,
# bumps the .plg version to today's date, and writes a formatted summary.
# After running, review + commit manually.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLG="$(realpath "${SCRIPT_DIR}/../hermes.plg")"
PLG_REL="hermes.plg"

# --- Defaults ----------------------------------------------------------------
AGENT_TAG=""
WEBUI_TAG=""
AUTO_LATEST=false

# --- CLI parse ---------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --agent-tag)   AGENT_TAG="$2"; shift 2;;
    --webui-tag)   WEBUI_TAG="$2"; shift 2;;
    --latest)      AUTO_LATEST=true; shift;;
    -h|--help)
      sed -n '2,/^#/p' "$0" | sed 's/^# \?//'
      exit 0
      ;;
    *) echo "Unknown arg: $1"; exit 1;;
  esac
done

# --- Helpers -----------------------------------------------------------------
gh_latest_tag() {
  local repo="$1"
  curl -sL "https://api.github.com/repos/${repo}/releases/latest" \
    | python3 -c 'import sys,json; print(json.load(sys.stdin).get("tag_name",""))'
}

compute_sha256() {
  local url="$1"
  local tmp
  tmp="$(mktemp)"
  curl -fsSL "$url" -o "$tmp"
  sha256sum "$tmp" | awk '{print $1}'
  rm -f "$tmp"
}

# --- Resolve tags ------------------------------------------------------------
if $AUTO_LATEST; then
  echo "[info] Querying GitHub for latest releases..."
  AGENT_TAG="${AGENT_TAG:-$(gh_latest_tag NousResearch/hermes-agent)}"
  WEBUI_TAG="${WEBUI_TAG:-$(gh_latest_tag nesquena/hermes-webui)}"
  echo "[info] Agent:  $AGENT_TAG"
  echo "[info] WebUI:  $WEBUI_TAG"
fi

if [[ -z "$AGENT_TAG" || -z "$WEBUI_TAG" ]]; then
  echo "Error: both --agent-tag and --webui-tag are required (or use --latest)"
  exit 1
fi

# --- Compute SHA256s ---------------------------------------------------------
echo "[info] Downloading + hashing agent tarball..."
AGENT_SHA=$(compute_sha256 "https://github.com/NousResearch/hermes-agent/archive/refs/tags/${AGENT_TAG}.tar.gz")

echo "[info] Downloading + hashing webui tarball..."
WEBUI_SHA=$(compute_sha256 "https://github.com/nesquena/hermes-webui/archive/refs/tags/${WEBUI_TAG}.tar.gz")

NEW_VERSION="$(date +%Y.%m.%d)"

# --- Patch .plg --------------------------------------------------------------
# Use perl for robust multi-line / variable-width replacement
perl -i -0777 -pe "
s{<!ENTITY version\s+\"[^\"]+\"}{<!ENTITY version     \"${NEW_VERSION}\"};
s{<!ENTITY agentTAG\s+\"[^\"]+\"}{<!ENTITY agentTAG    \"${AGENT_TAG}\"};
s{<!ENTITY agentURL\s+\"[^\"]+\"}{<!ENTITY agentURL    \"https://github.com/NousResearch/hermes-agent/archive/refs/tags/\&agentTAG;.tar.gz\"};
s{<!ENTITY agentTARSHA\s+\"[^\"]+\"}{<!ENTITY agentTARSHA \"${AGENT_SHA}\"};
s{<!ENTITY webuiTAG\s+\"[^\"]+\"}{<!ENTITY webuiTAG    \"${WEBUI_TAG}\"};
s{<!ENTITY webuiURL\s+\"[^\"]+\"}{<!ENTITY webuiURL    \"https://github.com/nesquena/hermes-webui/archive/refs/tags/\&webuiTAG;.tar.gz\"};
s{<!ENTITY webuiTARSHA\s+\"[^\"]+\"}{<!ENTITY webuiTARSHA \"${WEBUI_SHA}\"};
" "$PLG"

# --- Summary -----------------------------------------------------------------
echo ""
echo "Updated ${PLG}:"
echo "  version: ${NEW_VERSION}"
echo "  agent:   ${AGENT_TAG}  (${AGENT_SHA})"
echo "  webui:   ${WEBUI_TAG}  (${WEBUI_SHA})"
echo ""
echo "Next steps:"
echo "  git diff ${PLG_REL}"
echo "  git add ${PLG_REL}"
echo "  git commit -m \"release: bump upstreams (${NEW_VERSION})\""
echo "  git push origin master"
echo ""

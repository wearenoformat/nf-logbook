#!/usr/bin/env bash
# Cut a Noformat Logbook release from GitHub Releases' point of view.
#
#   tools/release.sh <version>
#       Checks that the plugin header version, NOFORMAT_LOGGER_VERSION and the
#       CHANGELOG.md section agree with <version> and that tag v<version> does not
#       exist yet, runs `composer check`, builds nf-logbook.zip (top folder
#       nf-logbook/) from a clean `git archive HEAD` honouring .distignore,
#       writes manifest.json (sha256 of the zip, changelog = that version's
#       CHANGELOG section), tags v<version>, pushes the tag and creates the GitHub
#       release with both assets.
#
#   tools/release.sh --ci <version>
#       GitHub Actions (.github/workflows/release.yml), on a pushed v* tag: the same
#       checks and build from the checked-out tag, then the release is created
#       unless it already exists (when tools/release.sh created it first).
#
#   tools/release.sh --local-only --version <version> --out <dir> [--download-url <url>] [--check]
#       Builds the two assets into <dir> from the working tree, without git or
#       GitHub. A <version> different from the header is written into the built
#       copy (integration tests use 9.9.9). --check also runs `composer check`.
#
# Environment: NOFORMAT_LOGGER_UPDATE_REPO (default wearenoformat/nf-logbook).
# Requires: bash, php, perl, rsync, zip, shasum or sha256sum; git and gh unless --local-only.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO="${NOFORMAT_LOGGER_UPDATE_REPO:-wearenoformat/nf-logbook}"
MODE="release"
VERSION=""
OUT=""
DOWNLOAD_URL=""
RUN_CHECK=1

die() { echo "release.sh: $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --local-only) MODE="local"; RUN_CHECK=0 ;;
    --ci) MODE="ci" ;;
    --version) VERSION="${2:-}"; shift ;;
    --out) OUT="${2:-}"; shift ;;
    --download-url) DOWNLOAD_URL="${2:-}"; shift ;;
    --check) RUN_CHECK=1 ;;
    --skip-check) RUN_CHECK=0 ;;
    -h|--help) sed -n '2,27p' "$0"; exit 0 ;;
    -*) die "unknown option $1" ;;
    *) [ -z "${VERSION}" ] || die "version given twice"; VERSION="$1" ;;
  esac
  shift
done

VERSION="${VERSION#v}"
[[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]] || die "usage: tools/release.sh <x.y.z> (got '${VERSION}')"
TAG="v${VERSION}"
MAIN="${PLUGIN_DIR}/nf-logbook.php"

header() { # header <Field name>
  sed -n "s/^[ *]*$1:[[:space:]]*//p" "${MAIN}" | head -1 | tr -d '\r'
}
HEADER_VERSION="$(header 'Version')"
CONST_VERSION="$(sed -n "s/^define( 'NOFORMAT_LOGGER_VERSION', '\([^']*\)' );/\1/p" "${MAIN}" | head -1)"
changelog_section() { # changelog_section <version>: the body of "## [<version>]" up to the next "## ["
  awk -v v="$1" '
    index($0, "## [" v "]") == 1 { on = 1; next }
    on && /^## \[/ { exit }
    on { print }
  ' "${PLUGIN_DIR}/CHANGELOG.md" | sed -e '/./,$!d' | awk '{ lines[NR] = $0 } END { n = NR; while (n > 0 && lines[n] == "") n--; for (i = 1; i <= n; i++) print lines[i] }'
}
CHANGES="$(changelog_section "${VERSION}")"

if [ "${MODE}" != "local" ]; then
  [ "${HEADER_VERSION}" = "${VERSION}" ] || die "plugin header says Version ${HEADER_VERSION}, not ${VERSION}"
  [ "${CONST_VERSION}" = "${VERSION}" ] || die "NOFORMAT_LOGGER_VERSION is ${CONST_VERSION}, not ${VERSION}"
  [ -n "${CHANGES}" ] || die "CHANGELOG.md has no '## [${VERSION}]' section"
  command -v gh > /dev/null || die "gh (GitHub CLI) is required"
  git -C "${PLUGIN_DIR}" rev-parse --git-dir > /dev/null 2>&1 || die "${PLUGIN_DIR} is not a git repository"
fi
if [ "${MODE}" = "release" ]; then
  [ -z "$(git -C "${PLUGIN_DIR}" status --porcelain)" ] || die "the working tree has uncommitted changes"
  ! git -C "${PLUGIN_DIR}" rev-parse -q --verify "refs/tags/${TAG}" > /dev/null || die "tag ${TAG} exists already"
  [ -z "$(git -C "${PLUGIN_DIR}" ls-remote --tags origin "refs/tags/${TAG}")" ] || die "tag ${TAG} exists on origin already"
fi
if [ "${MODE}" = "ci" ]; then
  [ "$(git -C "${PLUGIN_DIR}" rev-parse -q --verify "refs/tags/${TAG}^{commit}" 2>/dev/null)" = "$(git -C "${PLUGIN_DIR}" rev-parse HEAD)" ] || die "HEAD is not tag ${TAG}"
fi
if [ -z "${CHANGES}" ]; then
  CHANGES="Local test build ${VERSION}."
fi

if [ "${RUN_CHECK}" = "1" ]; then
  echo "==> composer check"
  (cd "${PLUGIN_DIR}" && composer check)
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/nflog-release.XXXXXX")"
trap 'rm -rf "${WORK}"' EXIT
if [ -z "${OUT}" ]; then
  OUT="${WORK}/out"
fi
mkdir -p "${OUT}"
OUT="$(cd "${OUT}" && pwd)"

echo "==> Exporting ${TAG} (honouring .distignore)"
mkdir -p "${WORK}/raw" "${WORK}/build/nf-logbook"
if [ "${MODE}" = "local" ]; then
  rsync -a --exclude .git "${PLUGIN_DIR}/" "${WORK}/raw/"
else
  git -C "${PLUGIN_DIR}" archive --format=tar HEAD | tar -x -C "${WORK}/raw"
fi
rsync -a --exclude-from="${WORK}/raw/.distignore" "${WORK}/raw/" "${WORK}/build/nf-logbook/"
BUILT_MAIN="${WORK}/build/nf-logbook/nf-logbook.php"
[ -f "${BUILT_MAIN}" ] || die "the export has no nf-logbook.php"
if [ "${HEADER_VERSION}" != "${VERSION}" ]; then
  echo "    (writing version ${VERSION} into the built copy; the source says ${HEADER_VERSION})"
  V="${VERSION}" perl -pi -e 's/^( \* Version:\s+)\S+/$1$ENV{V}/; s/define\( '\''NOFORMAT_LOGGER_VERSION'\'', '\''[^'\'']*'\'' \);/define( '\''NOFORMAT_LOGGER_VERSION'\'', '\''$ENV{V}'\'' );/' "${BUILT_MAIN}"
fi
for forbidden in vendor tests tools .git .github .local PLAN.md composer.json; do
  [ ! -e "${WORK}/build/nf-logbook/${forbidden}" ] || die "${forbidden} leaked into the package"
done

echo "==> Building nf-logbook.zip"
rm -f "${OUT}/nf-logbook.zip" "${OUT}/manifest.json"
(cd "${WORK}/build" && find nf-logbook -exec touch -t 202601010000 {} + && zip -r -X -q "${OUT}/nf-logbook.zip" nf-logbook)
if command -v sha256sum > /dev/null; then
  SHA="$(sha256sum "${OUT}/nf-logbook.zip" | awk '{print $1}')"
else
  SHA="$(shasum -a 256 "${OUT}/nf-logbook.zip" | awk '{print $1}')"
fi
[ -n "${DOWNLOAD_URL}" ] || DOWNLOAD_URL="https://github.com/${REPO}/releases/download/${TAG}/nf-logbook.zip"

printf '%s' "${CHANGES}" > "${WORK}/notes.md"
NAME="Noformat Logbook" VERSION="${VERSION}" REQUIRES="$(header 'Requires at least')" REQUIRES_PHP="$(header 'Requires PHP')" \
TESTED="$(header 'Tested up to')" RELEASED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)" DOWNLOAD_URL="${DOWNLOAD_URL}" SHA="${SHA}" NOTES="${WORK}/notes.md" \
php -r '
  $m = [
    "name"         => getenv("NAME"),
    "slug"         => "nf-logbook",
    "version"      => getenv("VERSION"),
    "requires"     => getenv("REQUIRES"),
    "requires_php" => getenv("REQUIRES_PHP"),
    "tested"       => getenv("TESTED"),
    "released_at"  => getenv("RELEASED_AT"),
    "download_url" => getenv("DOWNLOAD_URL"),
    "sha256"       => getenv("SHA"),
    "changelog"    => file_get_contents(getenv("NOTES")),
  ];
  echo json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
' > "${OUT}/manifest.json"
echo "    ${OUT}/nf-logbook.zip  sha256 ${SHA}"
echo "    ${OUT}/manifest.json"

if [ "${MODE}" = "local" ]; then
  exit 0
fi

if [ "${MODE}" = "release" ]; then
  echo "==> Tagging ${TAG} and pushing it"
  git -C "${PLUGIN_DIR}" tag -a "${TAG}" -m "Noformat Logbook ${VERSION}"
  git -C "${PLUGIN_DIR}" push origin "${TAG}"
fi

if gh release view "${TAG}" --repo "${REPO}" > /dev/null 2>&1; then
  echo "==> Release ${TAG} exists already on ${REPO}; nothing to upload"
  exit 0
fi
echo "==> Creating the GitHub release ${TAG} on ${REPO}"
gh release create "${TAG}" --repo "${REPO}" --title "Noformat Logbook ${VERSION}" --notes-file "${WORK}/notes.md" --verify-tag \
  "${OUT}/nf-logbook.zip" "${OUT}/manifest.json"
gh release view "${TAG}" --repo "${REPO}" --json url,assets --jq '.url, (.assets[] | "  " + .name + " " + (.size|tostring) + " bytes")'

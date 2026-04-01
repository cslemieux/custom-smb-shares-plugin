#!/bin/bash
# Release to public repo with squashed commit
# Usage: ./scripts/release-to-public.sh "v2025.12.14c" "Release description"
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

VERSION=$1
MESSAGE=${2:-"Release $VERSION"}
SKIP_E2E=false
AUTO_YES=false

if [ -z "$VERSION" ]; then
    echo -e "${RED}Usage: $0 <version> [message] [--skip-e2e] [--yes]${NC}"
    echo "Example: $0 v2025.12.14c 'Bug fixes and improvements'"
    exit 1
fi

# Check for flags in any position
for arg in "$@"; do
    if [ "$arg" = "--skip-e2e" ]; then
        SKIP_E2E=true
    elif [ "$arg" = "--yes" ] || [ "$arg" = "-y" ]; then
        AUTO_YES=true
    fi
done

echo "╔════════════════════════════════════════════════════════════╗"
echo "║  Release to Public Repository                              ║"
echo "║  Version: $VERSION"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

# Ensure we're on main and clean
if [ -n "$(git status --porcelain)" ]; then
    echo -e "${RED}Error: Working directory not clean. Commit or stash changes first.${NC}"
    exit 1
fi

# Check if README changelog has been updated for this version
VERSION_NUM=${VERSION#v}  # Remove 'v' prefix
if ! grep -q "### $VERSION\|### v$VERSION_NUM" README.md 2>/dev/null; then
    echo -e "${YELLOW}Warning: README.md changelog does not contain entry for $VERSION${NC}"
    echo ""
    echo "Add a changelog entry like:"
    echo "  ### $VERSION"
    echo "  - Your changes here"
    echo ""
    if [ "$AUTO_YES" = true ]; then REPLY=y; else read -p "Continue without changelog update? (y/N) " -n 1 -r; fi
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted. Update README.md changelog and commit, then re-run."
        exit 1
    fi
fi

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$BRANCH" != "main" ]; then
    echo -e "${YELLOW}Warning: Not on main branch (on $BRANCH)${NC}"
    if [ "$AUTO_YES" = true ]; then REPLY=y; else read -p "Continue anyway? (y/N) " -n 1 -r; fi
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Check CI status on public repo before releasing
# Run local E2E tests before releasing
if [ "$SKIP_E2E" = true ]; then
    echo -e "${YELLOW}E2E tests skipped (--skip-e2e flag)${NC}"
elif command -v chromedriver &> /dev/null; then
    if composer test:e2e; then
        echo -e "${GREEN}✓ E2E tests passed${NC}"
    else
        echo -e "${RED}Error: E2E tests failed!${NC}"
        echo "Fix E2E test failures before releasing."
        if [ "$AUTO_YES" = true ]; then REPLY=y; else read -p "Release anyway? (y/N) " -n 1 -r; fi
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
else
    echo -e "${YELLOW}Warning: chromedriver not installed - cannot run E2E tests locally${NC}"
    echo "E2E tests will be verified via CI status instead."
    echo "Install chromedriver: brew install chromedriver"
fi

# Check CI status on public repo before releasing
echo -e "${GREEN}Checking CI status...${NC}"
LATEST_CI_RUN=$(gh run list --repo cslemieux/unraid-custom-smb-shares --workflow=CI --limit 1 --json status,conclusion,headBranch 2>/dev/null)
if [ -n "$LATEST_CI_RUN" ]; then
    CI_STATUS=$(echo "$LATEST_CI_RUN" | jq -r '.[0].conclusion // "unknown"')
    CI_BRANCH=$(echo "$LATEST_CI_RUN" | jq -r '.[0].headBranch // "unknown"')
    if [ "$CI_STATUS" = "failure" ] && [ "$CI_BRANCH" = "main" ]; then
        echo -e "${RED}Error: CI is failing on main branch!${NC}"
        echo "Fix CI issues before releasing."
        echo ""
        echo "Check CI status: gh run list --repo cslemieux/unraid-custom-smb-shares --limit 5"
        if [ "$AUTO_YES" = true ]; then REPLY=y; else read -p "Release anyway? (y/N) " -n 1 -r; fi
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    elif [ "$CI_STATUS" = "success" ]; then
        echo -e "${GREEN}✓ CI passing on main${NC}"
    else
        echo -e "${YELLOW}CI status: $CI_STATUS (branch: $CI_BRANCH)${NC}"
    fi
fi

# Fetch latest from both remotes
echo -e "${GREEN}Fetching from remotes...${NC}"
git fetch origin
git fetch public

# Get the last commit that was pushed to public
PUBLIC_HEAD=$(git rev-parse public/main)
echo "Public repo HEAD: $PUBLIC_HEAD"

# Check if there are new commits to release
COMMITS_AHEAD=$(git rev-list --count public/main..HEAD)
if [ "$COMMITS_AHEAD" -eq 0 ]; then
    echo -e "${YELLOW}No new commits to release.${NC}"
    exit 0
fi
echo "Commits to squash: $COMMITS_AHEAD"

# Create squashed commit message with list of changes
COMMIT_LIST=$(git log --oneline public/main..HEAD | head -20)
FULL_MESSAGE="$MESSAGE

Squashed commits:
$COMMIT_LIST"

echo ""
echo "Commit message:"
echo "---"
echo "$FULL_MESSAGE"
echo "---"
echo ""
echo -e "${YELLOW}>>> Proceed with release? (y/N) ${NC}"
if [ "$AUTO_YES" = true ]; then REPLY=y; else read -n 1 -r REPLY </dev/tty; fi
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 1
fi

# Create a temporary branch for the squash
echo -e "${GREEN}Creating squashed release...${NC}"
git checkout -b release-temp

# Soft reset to public/main to stage all changes
git reset --soft public/main

# Remove files that should not be in public repo
echo -e "${GREEN}Excluding private files from release...${NC}"
git reset HEAD -- .kiro/ 2>/dev/null || true
git checkout -- .kiro/ 2>/dev/null || true

# Commit with the release message
git commit -m "$FULL_MESSAGE"

# Push to public
echo -e "${GREEN}Pushing to public repo...${NC}"
git push public release-temp:main

# Create tag on public
echo -e "${GREEN}Creating tag $VERSION on public...${NC}"
git tag -a "$VERSION" -m "$MESSAGE"
git push public "$VERSION"

# Return to main branch
git checkout main
git branch -D release-temp

# Also push to private to keep in sync
echo -e "${GREEN}Syncing tag to private repo...${NC}"
git push origin "$VERSION" 2>/dev/null || true

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  Release Complete!                                         ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo "Version $VERSION pushed to public repo."
echo ""
echo "Next steps:"
echo "  1. Create GitHub release: gh release create $VERSION --repo cslemieux/unraid-custom-smb-shares"
echo "  2. Attach the .txz package from archive/"

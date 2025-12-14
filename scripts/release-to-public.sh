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

if [ -z "$VERSION" ]; then
    echo -e "${RED}Usage: $0 <version> [message]${NC}"
    echo "Example: $0 v2025.12.14c 'Bug fixes and improvements'"
    exit 1
fi

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

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$BRANCH" != "main" ]; then
    echo -e "${YELLOW}Warning: Not on main branch (on $BRANCH)${NC}"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
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
read -p "Proceed with release? (y/N) " -n 1 -r
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

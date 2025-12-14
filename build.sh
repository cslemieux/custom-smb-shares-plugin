#!/bin/bash
# Production-grade build script for Custom SMB Shares plugin
# Follows Slackware/Unraid packaging conventions while adding quality checks
set -e

# Usage
if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "Usage: $0 [OPTIONS] [VERSION_SUFFIX]"
    echo ""
    echo "Options:"
    echo "  -f, --fast           Fast build (skip tests)"
    echo "  -h, --help           Show this help"
    echo ""
    echo "Arguments:"
    echo "  VERSION_SUFFIX       Optional suffix to append to version (e.g., 'a', 'b')"
    echo ""
    echo "Environment:"
    echo "  GENERATE_COVERAGE=true   Generate coverage report (slow)"
    echo ""
    echo "Examples:"
    echo "  $0                   Full build with tests"
    echo "  $0 --fast            Fast build without tests"
    echo "  $0 a                 Build with version suffix 'a' (e.g., 2025.01.18a)"
    echo "  GENERATE_COVERAGE=true $0   Full build with coverage"
    exit 0
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PLUGIN_NAME="custom.smb.shares"
BASE_VERSION=$(date +"%Y.%m.%d")
BUILD_DIR="build"
ARCHIVE_DIR="archive"
COVERAGE_DIR="coverage"

# Parse arguments
FAST_BUILD=false
VERSION_SUFFIX=""

for arg in "$@"; do
    case $arg in
        -f|--fast)
            FAST_BUILD=true
            ;;
        -h|--help)
            # Already handled above
            ;;
        *)
            # Assume it's a version suffix
            VERSION_SUFFIX="$arg"
            ;;
    esac
done

# Auto-increment point release if version already exists
VERSION="${BASE_VERSION}${VERSION_SUFFIX}"
if [ -z "$VERSION_SUFFIX" ]; then
    # Check for existing packages with same date
    EXISTING=$(ls -1 ${ARCHIVE_DIR}/${PLUGIN_NAME}-${BASE_VERSION}*.txz 2>/dev/null | wc -l | tr -d ' ')
    if [ "$EXISTING" -gt 0 ]; then
        # Find highest point release
        HIGHEST=$(ls -1 ${ARCHIVE_DIR}/${PLUGIN_NAME}-${BASE_VERSION}*.txz 2>/dev/null | \
            sed -E "s/.*${BASE_VERSION}([a-z])?-.*/\1/" | \
            sort | tail -1)
        if [ -z "$HIGHEST" ]; then
            VERSION="${BASE_VERSION}a"
        else
            # Increment letter (a->b, b->c, etc.)
            NEXT=$(echo "$HIGHEST" | tr 'a-y' 'b-z')
            VERSION="${BASE_VERSION}${NEXT}"
        fi
        echo "Note: Version ${BASE_VERSION} exists, auto-incrementing to ${VERSION}"
    fi
fi

# Functions
log_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

log_success() {
    echo -e "${GREEN}✓${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

log_error() {
    echo -e "${RED}✗${NC} $1"
}

run_step() {
    local step_name=$1
    local step_cmd=$2
    
    echo ""
    log_info "Running: $step_name"
    if eval "$step_cmd"; then
        log_success "$step_name passed"
        return 0
    else
        log_error "$step_name failed"
        return 1
    fi
}

# Main build process
main() {
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║  Custom SMB Shares Plugin - Production Build              ║"
    echo "║  Version: $VERSION                                    ║"
    if [ "$FAST_BUILD" = true ]; then
        echo "║  Mode: FAST (skip tests)                                   ║"
    fi
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""
    
    # Check dependencies
    log_info "Checking dependencies..."
    
    if ! command -v composer &> /dev/null; then
        log_error "Composer not found. Please install composer."
        exit 1
    fi
    
    # Check for makepkg (Slackware package builder)
    # If not available, we'll fall back to tar
    USE_MAKEPKG=false
    if command -v makepkg &> /dev/null; then
        USE_MAKEPKG=true
        log_success "makepkg found (Slackware packaging)"
    else
        log_warning "makepkg not found - using tar fallback (install Slackware pkgtools for native packaging)"
    fi
    
    log_success "Dependencies OK"
    
    # Validate source code syntax
    run_step "Source Code Validation" "composer validate:sources" || {
        log_error "Source validation failed. Fix syntax errors before building."
        exit 1
    }
    
    # Install/update dependencies
    if [ ! -d "vendor" ]; then
        log_info "Installing dependencies..."
        composer install --no-interaction --prefer-dist
        log_success "Dependencies installed"
    fi
    
    # Step 1: Code Linting (PSR-12)
    log_info "Auto-fixing linting issues..."
    composer lint:fix > /dev/null 2>&1 || true
    
    run_step "Code Linting (PSR-12)" "composer lint" || {
        log_error "Linting failed after auto-fix. Manual fixes required."
        exit 1
    }
    
    # Step 2: Static Analysis (PHPStan level 8)
    run_step "Static Analysis (PHPStan level 8)" "composer analyze" || {
        log_error "Static analysis failed. Fix errors before building."
        exit 1
    }
    
    # Step 3: JavaScript Syntax Validation
    log_info "Validating JavaScript syntax..."
    if command -v jshint &> /dev/null; then
        JS_ERRORS=0
        for js_file in source/usr/local/emhttp/plugins/${PLUGIN_NAME}/js/*.js; do
            if [ -f "$js_file" ]; then
                if jshint "$js_file" > /dev/null 2>&1; then
                    log_success "$(basename $js_file) - syntax OK"
                else
                    log_error "$(basename $js_file) - syntax errors"
                    jshint "$js_file"
                    JS_ERRORS=$((JS_ERRORS + 1))
                fi
            fi
        done
        
        if [ $JS_ERRORS -gt 0 ]; then
            log_error "$JS_ERRORS JavaScript file(s) have syntax errors"
            exit 1
        fi
    else
        log_warning "JSHint not found - skipping JS validation (install with: npm install -g jshint)"
    fi
    
    # Step 4: Run Tests
    if [ "$FAST_BUILD" = true ]; then
        log_warning "Tests skipped (fast build mode)"
    else
        run_step "Unit Tests" "composer test:unit" || {
            log_error "Unit tests failed"
            exit 1
        }
        
        run_step "Integration Tests" "composer test:integration" || {
            log_error "Integration tests failed"
            exit 1
        }
    fi
    
    # E2E tests disabled in build (run manually with: composer test:e2e)
    log_warning "E2E tests skipped in build. Run manually if needed: composer test:e2e"
    
    # Step 5: Generate Coverage Report (optional, slow)
    if [ "${GENERATE_COVERAGE:-false}" = "true" ]; then
        log_info "Generating test coverage report..."
        composer test:coverage > /dev/null 2>&1 || true
        if [ -d "$COVERAGE_DIR" ]; then
            log_success "Coverage report generated: $COVERAGE_DIR/index.html"
        fi
    else
        log_info "Coverage report skipped (set GENERATE_COVERAGE=true to enable)"
    fi
    
    # Step 6: Build Package
    echo ""
    log_info "Building plugin package..."
    
    # Clean previous builds
    rm -rf ${BUILD_DIR} ${ARCHIVE_DIR}
    mkdir -p ${BUILD_DIR} ${ARCHIVE_DIR}
    
    # Copy source files to build directory
    # Exclude build scripts and dev files (following user.scripts pattern)
    cp -R source/* ${BUILD_DIR}/
    
    # Remove any dev files that shouldn't be in the package
    find ${BUILD_DIR} -name ".DS_Store" -delete 2>/dev/null || true
    find ${BUILD_DIR} -name "*.bak" -delete 2>/dev/null || true
    
    # Set proper permissions
    find ${BUILD_DIR} -type d -exec chmod 755 {} \;
    find ${BUILD_DIR} -type f -exec chmod 644 {} \;
    find ${BUILD_DIR} -name "*.sh" -exec chmod 755 {} \;
    
    # Write VERSION file
    echo "${VERSION}" > ${BUILD_DIR}/usr/local/emhttp/plugins/${PLUGIN_NAME}/VERSION
    
    # Package naming follows Slackware convention: name-version-arch-build.txz
    PACKAGE_NAME="${PLUGIN_NAME}-${VERSION}-x86_64-1.txz"
    
    # Create package
    cd ${BUILD_DIR}
    
    if [ "$USE_MAKEPKG" = true ]; then
        # Use Slackware's makepkg (preferred)
        makepkg -l y -c y ../${ARCHIVE_DIR}/${PACKAGE_NAME}
    else
        # Fallback: use tar directly (works on macOS/Linux without pkgtools)
        tar --owner=root --group=root -cJf ../${ARCHIVE_DIR}/${PACKAGE_NAME} *
    fi
    
    cd ..
    
    # Calculate MD5
    cd ${ARCHIVE_DIR}
    if command -v md5sum &> /dev/null; then
        md5sum ${PACKAGE_NAME} | awk '{print $1}' > ${PLUGIN_NAME}-${VERSION}.md5
    else
        # macOS fallback
        md5 -q ${PACKAGE_NAME} > ${PLUGIN_NAME}-${VERSION}.md5
    fi
    MD5=$(cat ${PLUGIN_NAME}-${VERSION}.md5)
    cd ..
    
    log_success "Package built successfully"
    
    # Summary
    echo ""
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║  Build Complete!                                           ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""
    echo "Package: ${ARCHIVE_DIR}/${PACKAGE_NAME}"
    echo "MD5:     ${MD5}"
    echo "Size:    $(du -h ${ARCHIVE_DIR}/${PACKAGE_NAME} | cut -f1)"
    echo ""
    echo "Update .plg file with:"
    echo "  <!ENTITY version   \"${VERSION}\">"
    echo "  <!ENTITY md5       \"${MD5}\">"
    echo ""
    echo "And update the FILE URL to:"
    echo "  &gitURL;/archive/${PACKAGE_NAME}"
    echo ""
    echo "Next steps:"
    echo "  1. Update custom.smb.shares.plg with new version and MD5"
    echo "  2. Deploy to test: ./deploy.sh"
    echo "  3. Test on Unraid server"
    echo "  4. Commit and push to GitHub"
    echo ""
}

# Run main function
main "$@"

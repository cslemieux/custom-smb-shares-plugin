# Contributing to Custom SMB Shares

Thank you for your interest in contributing! This document provides guidelines for contributing to the project.

## Development Setup

### Prerequisites

- PHP 8.4+ (7.4 compatible)
- Composer
- ChromeDriver (for E2E tests)
- Git

### Installation

```bash
# Clone the repository
git clone https://github.com/YOUR_USERNAME/unraid-custom-smb-shares.git
cd unraid-custom-smb-shares

# Install dependencies
composer install
```

### Running Tests

```bash
# Run all unit and integration tests
vendor/bin/phpunit --testsuite Unit,Integration

# Run E2E tests (requires ChromeDriver)
chromedriver --port=9515 &
vendor/bin/phpunit tests/e2e/ComprehensiveUITest.php

# Run static analysis
vendor/bin/phpstan analyse
```

## Code Standards

### PHP

- Follow PSR-12 coding standard
- Use `declare(strict_types=1)` in all PHP files
- Pass PHPStan level 8 with no errors
- Add PHPDoc comments for all public functions

### JavaScript

- Use ES5+ syntax (jQuery 3.x compatibility)
- Follow existing naming conventions
- Add JSDoc comments for functions

### Testing

- All new features must include tests
- Maintain 100% test pass rate
- Add E2E tests for UI changes

## Pull Request Process

1. **Fork** the repository
2. **Create a branch** for your feature (`git checkout -b feature/my-feature`)
3. **Make changes** following the code standards above
4. **Run tests** and ensure all pass
5. **Commit** with clear messages
6. **Push** to your fork
7. **Open a Pull Request** with:
   - Clear description of changes
   - Reference to any related issues
   - Screenshots for UI changes

## Commit Messages

Follow conventional commits format:

```
type(scope): description

[optional body]

[optional footer]
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

Examples:
- `feat(shares): add bulk delete functionality`
- `fix(validation): handle empty path correctly`
- `docs(readme): update installation instructions`

## Reporting Issues

When reporting bugs, please include:

1. Unraid version
2. Browser and version
3. Steps to reproduce
4. Expected vs actual behavior
5. Screenshots if applicable
6. Relevant log entries

## Questions?

- Open a [GitHub Discussion](https://github.com/YOUR_USERNAME/unraid-custom-smb-shares/discussions)
- Ask on the [Unraid Forums](https://forums.unraid.net/)

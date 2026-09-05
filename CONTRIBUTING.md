# Contributing to Aether

Thank you for considering contributing to Aether! The contribution guide is designed to make it as easy as possible to get involved.

## Local Development

To develop and test your changes locally, follow these steps:

1. **Fork the repository** on GitHub.
2. **Clone your fork** locally:
   ```bash
   git clone https://github.com/YOUR_USERNAME/aether.git
   cd aether
   ```
3. **Install dependencies** via Composer:
   ```bash
   composer install
   ```

## Running Tests

Before submitting a Pull Request, ensure that all tests pass. We use Pest/PHPUnit for testing.

You can run the entire test suite with:

```bash
composer test
```

If you add a new feature, please include corresponding tests to verify your implementation.

## Pull Request Guidelines

* **Linear History**: We require a clean, linear git history. Please `rebase` your branch against `main` rather than creating merge commits.
* **Resolve Conversations**: If a reviewer leaves comments on your PR, please address them and mark the thread as resolved before requesting a merge.
* **Keep it focused**: Submit one Pull Request per feature or bug fix.

## Security Vulnerabilities

If you discover a security vulnerability, please do not open a public issue. Instead, email the repository owner directly.

# Contributing to Aether

Thank you for your interest in contributing to **Aether**! Aether bridges Laravel with Quantum Computing via AWS Braket and local simulators, combining a modern PHP 8.3+ API with Python-based quantum execution.

We welcome contributions of all kinds: bug reports, documentation improvements, feature requests, and code contributions.

---

## Code of Conduct

We are committed to providing a friendly, safe, and welcoming environment for all contributors. Please be respectful, constructive, and considerate in all interactions within issues, pull requests, and discussions.

---

## How to Contribute

### Reporting Bugs

If you discover a bug, please check the [existing issues](https://github.com/corgab/aether/issues) first to make sure it hasn't already been reported. If not, open a new issue with:

- **A clear summary** of the problem.
- **Environment details**:
  - PHP version (`php -v`)
  - Laravel version
  - Python version (`python3 --version`)
  - Operating system
  - `amazon-braket-sdk` version
- **Steps to reproduce** or a minimal reproducible code example.
- **Expected vs. actual behavior**, including relevant error messages or stack traces.

### Suggesting Enhancements

Feature ideas and architectural improvements are very welcome! Please open an issue to discuss:

- The problem or use case you are addressing.
- Your proposed API design or solution.
- Any potential alternatives or trade-offs considered.

Opening an issue before writing code helps ensure the enhancement aligns with the project's roadmap and design principles.

---

## Development Setup

### Prerequisites

- **PHP**: 8.3 or higher
- **Composer**: Latest 2.x
- **Python**: 3.12+

### Installation

1. **Fork and clone the repository:**

   ```bash
   git clone https://github.com/<your-username>/aether.git
   cd aether
   ```

2. **Install PHP dependencies:**

   ```bash
   composer install
   ```

3. **Set up the Python environment:**

   Create and activate a virtual environment, then install the required Python dependencies and test tools:

   ```bash
   python3 -m venv .venv
   source .venv/bin/activate
   pip install -r bin/python/requirements.txt pytest
   ```

4. **Verify your setup:**

   Run the test suites to ensure everything is working correctly:

   ```bash
   composer test
   pytest tests/python/ -v
   ```

---

## Testing

Comprehensive testing is critical when dealing with quantum simulation and hardware drivers.

### PHP Tests (Pest)

Aether uses **Pest** exclusively for all PHP testing.

```bash
# Run all Pest tests in compact mode
composer test

# Run tests using the Pest binary directly
./vendor/bin/pest

# Run a specific test suite
./vendor/bin/pest --testsuite Unit
./vendor/bin/pest --testsuite Feature

# Run a specific test file
./vendor/bin/pest tests/Feature/QuantumManagerTest.php

# Run a specific test by name
./vendor/bin/pest --filter "it generates entropy"
```

#### Writing Pest Tests

- Write tests using Pest's fluent API (`test(...)` or `it(...)` with `expect(...)`).
- Place unit tests in `tests/Unit/` and feature/integration tests in `tests/Feature/`.
- Mock external quantum execution in tests using `Quantum::fake()`.

### Python Tests (Pytest)

The Python bridge logic in `bin/python/` is tested with Pytest:

```bash
pytest tests/python/ -v
```

Python tests live in `tests/python/` and test gate validation, circuit translation, and provider drivers.

---

## Code Quality & Standards

### PHP Standards

- **Strict Types**: Every PHP file must begin with `declare(strict_types=1);`.
- **PHP 8.3+ Features**: Use modern PHP features where appropriate (e.g., `readonly` properties, constructor property promotion, match expressions, named arguments).
- **Code Style (Laravel Pint)**:
  - Format code using Pint before committing:
    ```bash
    composer format
    ```
  - Check formatting without modifying files:
    ```bash
    ./vendor/bin/pint --test
    ```
- **Static Analysis (PHPStan / Larastan)**:
  - We run PHPStan at **Level 8**:
    ```bash
    composer analyse
    ```
- **All-in-One Check**:
  - Run both static analysis and the Pest test suite:
    ```bash
    composer check
    ```

### Architectural Conventions

- **Drivers**: Driver classes extend `AbstractQuantumDriver` and use the `*Driver` suffix (e.g., `LocalSimulatorDriver`, `AwsBraketDriver`).
- **Contracts**: Interfaces reside in `Aether\Contracts\` with semantic names and **without** a `Contract` suffix (e.g., `Contracts\QuantumDevice`, `Contracts\BatchableDevice`).
- **Exceptions**: All domain exceptions extend `AetherException` and provide descriptive static factory methods (e.g., `InvalidCircuitException::missingQubits()`).
- **Python Scripts**: Self-contained scripts live in `bin/python/`. They accept JSON via `stdin`, output JSON via `stdout`, and keep dependencies strictly limited to `amazon-braket-sdk` and `numpy`.

---

## Pull Request Guidelines

### Branching & Commits

1. Create a dedicated topic branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/issue-description
   ```
2. Keep pull requests focused on a single change or fix.
3. Write clear, meaningful commit messages using [Conventional Commits](https://www.conventionalcommits.org/):
   - `feat: add QFT gate support`
   - `fix: validate qubit indices in Python bridge`
   - `docs: update driver comparison table`
   - `test: cover batch driver mismatch error`
   - `refactor: simplify driver resolution`

### Keeping Your Branch Up to Date

If changes have landed on `main` while working on your feature, update your branch from `main` to ensure there are no conflicts:

```bash
git fetch origin
git merge origin/main
# or git rebase origin/main
```

> **Note**: Maintainers manage the merge strategy (such as squash-and-merge) when integrating pull requests into `main`, so you do not need to manually squash your commits prior to review.

### Pre-Flight Checklist

Before opening your pull request, please verify that:

- [ ] All PHP tests pass: `composer test`
- [ ] All Python tests pass: `pytest tests/python/ -v`
- [ ] Static analysis passes at Level 8: `composer analyse`
- [ ] Code is formatted with Laravel Pint: `composer format`
- [ ] New features or bug fixes include corresponding Pest and/or Python tests
- [ ] Relevant documentation or docblocks have been added or updated

Thank you for helping make Aether better!

# Security Policy

## Supported versions

Security fixes land on the latest minor release of Aether. Older releases do
not receive patches; please upgrade before reporting.

## Reporting a vulnerability

Do not open a public issue for a security problem. Use GitHub's private
vulnerability reporting instead:

1. Open the [Security tab](https://github.com/corgab/aether/security/advisories/new)
   of the repository and choose "Report a vulnerability".
2. Describe the impact, the affected code (driver, Python script, job) and,
   when possible, a minimal reproduction.

You will get an acknowledgement within a few days. The fix is developed in
a private advisory fork, released, and the advisory is published together
with credit to the reporter unless you prefer to stay anonymous.

## Scope

Aether executes Python subprocesses and, with the `aws` driver, submits
billable tasks to AWS Braket on the credentials of the host application.
Reports about command injection through circuit payloads, credential
leakage through the Python bridge, or ways to bypass the cost and qubit
ceilings are especially welcome. Vulnerabilities in the Braket SDK, boto3
or Laravel itself should go to those projects.

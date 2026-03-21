# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability in this plugin, please report it responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please email: **security@301.st**

We will acknowledge your report within 48 hours and aim to release a fix within 7 days for critical issues.

## What to Include

- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

## Security Measures

This plugin follows WordPress security best practices:

- All form submissions are protected with nonces (CSRF protection)
- All admin actions require capability checks
- All user inputs are sanitised before processing
- All outputs are escaped before rendering
- All database queries use prepared statements
- No sensitive data is stored in plain text
- Direct file access is blocked on all PHP files
- Template content is sanitised with `wp_kses_post()` on render output

# Security Changelog

All security-relevant changes applied to this fork of [magedia/refurbed-invoices](https://github.com/magedia/refurbed-invoices).

---

## 2026-04-09 — Security Hardening

### CRITICAL Fixes

| # | Issue | Fix | Files |
|---|-------|-----|-------|
| 1 | **Container runs as root** | Created unprivileged `appuser` (groupadd/useradd). Cron job and app run as `appuser`. | `Dockerfile`, `docker/crontab` |
| 2 | **Secrets dumped to world-readable `/etc/environment`** | New `docker/entrypoint.sh` writes env to `/app/.env.runtime` (mode 0600, owned by appuser) | `Dockerfile`, `docker/entrypoint.sh` |
| 3 | **No SSL/TLS certificate verification on cURL calls** | Added `CURLOPT_SSL_VERIFYPEER => true` and `CURLOPT_SSL_VERIFYHOST => 2` to both Shopify GraphQL and Refurbed upload functions | `src/refurbed-invoices.php` |
| 4 | **Path traversal via untrusted email attachment filenames** | Replaced `basename($filename)` with generated safe filename (`invoice_{orderId}_{random}.pdf`) | `src/refurbed-invoices.php` |

### HIGH Fixes

| # | Issue | Fix | Files |
|---|-------|-----|-------|
| 5 | **No cURL timeouts** — requests could hang indefinitely | Added `CURLOPT_TIMEOUT` (30s/60s) and `CURLOPT_CONNECTTIMEOUT` (10s) to all cURL calls | `src/refurbed-invoices.php` |
| 6 | **No shop domain validation** — potential URL injection | Added regex validation (`^[a-zA-Z0-9\-]+\.myshopify\.com$`) before constructing API URL | `src/refurbed-invoices.php` |
| 7 | **Sensitive API responses logged via `print_r()`** | Replaced with structured error logging that never dumps response bodies | `src/refurbed-invoices.php` |
| 8 | **IMAP errors leak server details** | Error messages no longer expose `imap_last_error()` output | `src/refurbed-invoices.php` |

### MEDIUM Fixes

| # | Issue | Fix | Files |
|---|-------|-----|-------|
| 9 | **No input validation for attachment data** | Added PDF magic byte check (`%PDF`), 25 MB size limit, strict base64 decoding | `src/refurbed-invoices.php` |
| 10 | **No env var validation at startup** | Script now validates all required env vars and exits with error if missing | `src/refurbed-invoices.php` |
| 11 | **No retry backoff** — flat 2s sleep | Implemented exponential backoff (2s, 4s) with configurable max retries | `src/refurbed-invoices.php` |
| 12 | **Temp files not cleaned up on failure** | Wrapped upload in `try/finally` to always delete temp invoice files | `src/refurbed-invoices.php` |
| 13 | **No privilege escalation protection** | Added `security_opt: no-new-privileges:true` | `docker-compose.yml` |
| 14 | **No container health monitoring** | Added healthcheck (pgrep cron) | `docker-compose.yml` |
| 15 | **Log output unstructured** | Added timestamped `[INFO]`/`[ERROR]` log format, errors go to STDERR | `src/refurbed-invoices.php` |
| 16 | **Log injection via email subjects** | Added `sanitizeLogOutput()` to strip control characters and limit length | `src/refurbed-invoices.php` |
| 17 | **Overly permissive directory permissions (0755)** | Changed invoice dir to 0750 | `src/refurbed-invoices.php` |
| 18 | **Deprecated `version` key in docker-compose** | Removed `version: "3.8"` (deprecated in Compose V2) | `docker-compose.yml` |
| 19 | **No `.dockerignore`** | Created `.dockerignore` to prevent `.env`, `.git`, and IDE files from entering image | `.dockerignore` |
| 20 | **Minimal `.gitignore`** | Expanded to cover env files, IDE files, OS files, runtime artifacts | `.gitignore` |
| 21 | **Unhelpful `.env.example`** | Replaced test values with obvious placeholders | `.env.example` |
| 22 | **No log rotation** | Added `json-file` logging driver with 10MB/3 file rotation | `docker-compose.yml` |
| 23 | **apt cache left in image** | Added `apt-get clean && rm -rf /var/lib/apt/lists/*` | `Dockerfile` |

### Structural Changes

| Change | Reason |
|--------|--------|
| `refurbed-invoices.php` → `src/refurbed-invoices.php` | Clean repo structure, separates app code from infra |
| `crontab` → `docker/crontab` | Docker-specific config in dedicated directory |
| New `docker/entrypoint.sh` | Secure env handling (`/app/.env.runtime`, mode 0600), replacing the `printenv > /etc/environment` anti-pattern |
| Added `declare(strict_types=1)` | Enforces type safety in PHP |

---

## Remaining Recommendations

These items are **not critical** but should be addressed when time permits:

- [ ] Replace `ipfwd/php-extended:8.3-rr-astra` with an official PHP base image
- [ ] Add IMAP SSL certificate verification (requires mailserver-specific config)
- [ ] Consider Docker secrets or HashiCorp Vault instead of env vars for production
- [ ] Add automated tests for the invoice processing pipeline
- [ ] Pin the cron package version in Dockerfile for reproducible builds

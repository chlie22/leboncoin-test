---
paths:
  - "docker/**"
  - "Dockerfile"
  - "compose.yaml"
  - ".dockerignore"
---

# Nginx, PHP-FPM and Docker

Reference: `docs/conception.md` §7 and §8.1. Validate a change with the smoke test through Nginx (`make smoke`), never with Symfony functional tests alone.

- Nginx is the only source of `Cache-Control: no-store`: `fastcgi_hide_header Cache-Control` in `fastcgi-app.conf`, the header in `headers.conf`. A `location` that declares its own `add_header` stops inheriting the server-level ones: re-include `headers.conf` there.
- Nginx error pages use internal URIs (`error_page 414 /_errors/414;` with `location = /_errors/414 { internal; ... }`), never named locations: an early 414 has an empty URI, and a named location turns it into a 500.
- Quotas: `limit_req` per IP (`$binary_remote_addr`, 1r/s, burst 2) and global (`$server_name`, 10r/s, burst 10), `nodelay`, `limit_req_status 429`. Values come from the `RATE_LIMIT_*` environment variables through the templates. A global burst of 0 would reject simultaneous clients.
- `limit_req_log_level info` keeps quota rejections out of the error log. Access logs use `$uri`, never `$request_uri`: `str1` and `str2` must not be logged. Upstream errors in the Nginx error log still contain the request line: this is the documented exception.
- `/healthz`: outside the quotas, `allow 127.0.0.1`, `allow ${TRUSTED_PROXY_CIDR}`, `deny all`.
- PHP-FPM is never published. `request_terminate_timeout` (10 s) stays below `fastcgi_read_timeout` (15 s).
- Entrypoint, stopping at the first error: migrations, then `bin/console app:statistics:apply-window`, then `exec php-fpm`. An unreachable SQLite at startup must fail the container.
- `var/data` is created and owned by `www-data` in the image, so the named volume inherits the right owner. Containers run as non-root users.
- Never add `zend_extension=opcache.so`: OPcache is built into PHP 8.5.

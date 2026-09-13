---
paths:
  - "docker/**"
  - "Dockerfile"
  - "compose.yaml"
  - "compose.override.yaml"
  - ".dockerignore"
---

# Nginx, PHP-FPM and Docker

Reference: `docs/conception.md` §7 and §8.1. Validate a change with the smoke test through Nginx (`make smoke`), never with Symfony functional tests alone.

- Nginx is the only source of `Cache-Control: no-store`: `fastcgi_hide_header Cache-Control` in `fastcgi-app.conf`, the header in `headers.conf`. A `location` that declares its own `add_header` stops inheriting the server-level ones: re-include `headers.conf` there.
- Nginx error pages use internal URIs (`error_page 414 /_errors/414;` with `location = /_errors/414 { internal; ... }`), never named locations: an early 414 has an empty URI, and a named location turns it into a 500. Documented exception (§4.4): a header over 8 KB (400) and a direct call to `/_errors/*` (404) get Nginx's HTML pages.
- Quotas: `limit_req` per IP (`$binary_remote_addr`, 1r/s, burst 2) and global (`$server_name`, 10r/s, burst 10), `nodelay`, `limit_req_status 429`. Values come from the `RATE_LIMIT_*` environment variables through the templates. A global burst of 0 would reject simultaneous clients. Keep `zone=per_ip` as the **last** `limit_req`: only the last zone checks the burst and writes its excess under one lock (nginx 1.30.4 source), so an earlier per-IP zone let 4 of 5 simultaneous requests through in 2 bursts out of 30 (12 workers), against 0 out of 30 once last.
- `limit_req_log_level info` keeps quota rejections out of the error log. Access logs use `$uri`, never `$request_uri`: `str1` and `str2` must not be logged. The Nginx error log still contains the full request line for upstream errors (502, 504) and for 403 and 413 rejections: this is the documented exception (§8.1, Q15).
- The PHP-FPM access log is disabled (`access.log = /dev/null` in `zz-app.conf`): the image's `docker.conf` sends it to stderr, and its default format (`%r`) contains the query string.
- `/healthz`: outside the quotas, `allow 127.0.0.1`, `allow ${TRUSTED_PROXY_CIDR}`, `deny all`.
- `TRUSTED_PROXY_CIDR` covers the containers' `ip_range` (`172.30.0.128/25`), never the network gateway: on Linux, published host traffic may enter through the gateway, and trusting it would open `/healthz` and let clients forge `X-Forwarded-For`.
- PHP-FPM is never published. `request_terminate_timeout` (10 s) stays below `fastcgi_read_timeout` (15 s). Nginx resolves `php` at startup: keep `depends_on: php: restart: true` so it restarts when `php` is recreated.
- `compose.yaml` is the production stack; `compose.override.yaml` (dev target, bind mount, `php-var` on `/app/var`, `stats-data-dev`) loads automatically. Drive production with `docker compose -f compose.yaml`, in CI too.
- Both services use `restart: unless-stopped` (D16). Always pair `up --wait` with `--wait-timeout` so a container stuck in a restart loop still fails the command.
- Both containers are `read_only`. PHP: `var/data` is the volume, `/tmp`, `var/share` and `var/log` are tmpfs given to `www-data` (`uid=33,gid=33`; a plain tmpfs is root-owned), `var/cache/prod` stays read-only. Nginx: tmpfs on `/tmp` and on `/etc/nginx/conf.d` (`uid=101`), where the image renders the templates.
- In dev, `www-data` takes the host UID and GID (build args `HOST_UID`/`HOST_GID`, exported by the `Makefile`) so the bind mount stays writable on Linux.
- Entrypoint, stopping at the first error: migrations, then `bin/console app:statistics:apply-window`, then `exec php-fpm` (the first two arrive at step 7). An unreachable SQLite at startup must fail the container.
- `var/data` is created and owned by `www-data` in the image, so the named volume inherits the right owner. Containers run as non-root users. The prod image excludes `docker/`: Nginx holds its own configuration.
- Image tags are pinned (`php:8.5.10-fpm`, `nginxinc/nginx-unprivileged:1.30.4-alpine`, `composer:2.10.3`, also `tools: composer:2.10.3` in `.github/workflows/ci.yaml`): bump them on purpose and rerun `make smoke` on the dev and prod stacks.
- Never add `zend_extension=opcache.so`: OPcache is built into PHP 8.5.

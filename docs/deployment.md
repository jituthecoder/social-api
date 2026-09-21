# GitHub Actions Deployment

The workflow at `.github/workflows/deploy.yml` deploys every push to `main` to:

```text
/home/u971218733/domains/a4autopost.com/public_html/laravel-api
```

It builds the Vite assets in GitHub Actions, uploads the application over SSH using the same deployment action as the main website, installs production Composer dependencies on Hostinger, runs migrations, and rebuilds Laravel caches.

## Hostinger preparation

1. Confirm SSH access is enabled for the Hostinger account.
2. Confirm PHP 8.2+, Composer, and the required PHP extensions are available for the SSH user.
3. Configure the domain or API subdomain document root to point to `laravel-api/public`, not the Laravel project root.
4. Create the `.env` file in the deployment directory and set production values, including `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, database, Redis, queue, mail, storage, and social provider settings.
5. Ensure the SSH user can write to `storage` and `bootstrap/cache`.
6. Make sure a Hostinger cron job runs the Laravel scheduler every minute:

```text
* * * * * cd /home/u971218733/domains/a4autopost.com/public_html/laravel-api && php artisan schedule:run >> /dev/null 2>&1
```

Shared web hosting cannot keep a Horizon or `queue:work` daemon running reliably. Do not configure Horizon as a permanent process. For queued jobs, create a Hostinger cron job that processes a bounded batch periodically:

```text
* * * * * cd /home/u971218733/domains/a4autopost.com/public_html/laravel-api && php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> storage/logs/queue-cron.log 2>&1
```

If the Hostinger plan does not allow long-running cron commands, use `php artisan queue:listen --stop-when-empty` or move queue processing to a separate worker service. The scheduler and queue cron jobs must use the same PHP binary and environment as the SSH account.

## GitHub secrets

Add these repository secrets under **Settings > Secrets and variables > Actions**:

| Secret | Value |
| --- | --- |
| `HOSTINGER_HOST` | Hostinger SSH hostname or IP address |
| `HOSTINGER_PORT` | SSH port, usually `65002` or `22` |
| `HOSTINGER_USER` | `u971218733` |
| `HOSTINGER_SSH_KEY` | The complete private key whose public key is authorized on Hostinger |

The workflow deliberately does not upload `.env` or `storage`; those remain on the server. It installs `vendor` on Hostinger because shared hosting may not provide the same PHP platform as GitHub Actions. Push to `main` or run the workflow manually from the Actions tab to deploy.

# Email Mailgun Plugin

Mailgun transport for the Grav Email plugin (Symfony Mailer). Supports Mailgun API, HTTPS and SMTP transports, including region selection (`us`/`eu`).

## Installation

From your Grav root:

```bash
bin/gpm install email-mailgun
```

or install from the Admin panel via **Plugins → Add**.

## Configuration

Copy the default config to `user/config/plugins/email-mailgun.yaml` and adjust:

```yaml
enabled: true
transport: api        # api (recommended), https, or smtp
api_key: YOUR_KEY     # API/HTTPS only
domain: yourdomain.tld # API/HTTPS only
region: us            # us or eu (matches your Mailgun domain region)
username:             # SMTP only
password:             # SMTP only
```

Admin UI exposes the same fields, including the region selector.

### Set the email engine

In `user/config/plugins/email.yaml`:

```yaml
mailer:
  engine: mailgun
```

Also set your `from`/`to` addresses there as usual.

## Usage notes

- **API vs HTTPS vs SMTP**: `api` is fastest and supports modern features. `https` uses basic HTTP auth. `smtp` works if HTTP is blocked.
- **Region**: choose `eu` if your Mailgun domain lives in the EU region; otherwise leave `us`. The DSN is generated with `?region=<us|eu>`.
- **Debugging**: enable `plugins.email.debug: true` in `email.yaml` to log the full transport debug output to `logs/email.log`.
- **Validation**: API/HTTPS require `api_key` **and** `domain`; SMTP requires `username` **and** `password`. Missing fields raise a clear error during transport creation.

## Links

- Mailgun API docs: https://documentation.mailgun.com/en/latest/api-intro.html
- Symfony Mailgun mailer bridge: https://github.com/symfony/mailgun-mailer

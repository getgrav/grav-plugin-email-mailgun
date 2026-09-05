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
signing_key:          # HTTP webhook signing key, only for delivery reports
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

## Delivery reports

Mailgun can tell your site what happened to a message after it left — delivered, bounced, marked as spam, opened, clicked. This plugin knows how to read that, so anything on the site that records deliveries (the KahunaCart newsletter add-on, for one) gets it without carrying any Mailgun code of its own. It needs the Email plugin 5.0.9 or newer; on an older one nothing here does any harm, it simply does nothing.

Once it is set up, a store stops guessing. Addresses that hard bounce get suppressed instead of being mailed for another year, spam complaints show up as complaints, and open and click figures come from Mailgun rather than from nowhere.

**The one button.** Whatever is receiving the events shows a webhook address and a **Set up** button. Pressing it reads what is already registered on your sending domain, adds the address to the six event types this needs, leaves any other webhook you have alone, and reads the HTTP webhook signing key back out of your account and saves it here. Pressing it twice is safe — it only adds what is missing.

That button needs the API key in this plugin's settings to be an **account key with permission to manage webhooks**. A domain sending key can send mail and cannot do either of those things.

**Doing it by hand.** In Mailgun, open **Sending → Webhooks** and pick your sending domain. Add the webhook address once for each of Delivered Messages, Permanent Failure, Temporary Failure, Spam Complaints, Opens and Clicks. Then find the **HTTP webhook signing key** under your profile menu on the **API Security** page — it is a different string from the sending API key, which is the thing people get wrong — and paste it into **Webhook Signing Key** here. Without it, every event Mailgun posts is refused, because there is no way to tell one from anybody else's.

**What Mailgun does not do.** Its delivery events carry only four of a message's headers, so a custom header put on the message never comes back. Events are tied to the message they came from by `Message-ID` instead, which needs nothing setting up.

## Links

- Mailgun API docs: https://documentation.mailgun.com/en/latest/api-intro.html
- Symfony Mailgun mailer bridge: https://github.com/symfony/mailgun-mailer

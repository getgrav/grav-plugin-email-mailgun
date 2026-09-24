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

Mailgun can tell your site what happened to a message after it left — delivered, bounced, marked as spam, opened, clicked, or refused before it went anywhere because the address was already on one of Mailgun's own suppression lists. This plugin knows how to read that, so anything on the site that records deliveries (the KahunaCart newsletter add-on, for one) gets it without carrying any Mailgun code of its own. It needs the Email plugin 5.0.9 or newer; on an older one nothing here does any harm, it simply does nothing.

Once it is set up, a store stops guessing. Addresses that hard bounce get suppressed instead of being mailed for another year, spam complaints show up as complaints, and open and click figures come from Mailgun rather than from nowhere.

**The one button.** Whatever is receiving the events shows a webhook address and a **Set up** button. Pressing it reads what is already registered on your sending domain, adds the address to the six event types this needs, leaves any other webhook you have alone, and reads the HTTP webhook signing key back out of your account and saves it here. Pressing it twice is safe — it only adds what is missing. If the address has changed since — a new secret, or a store that lost its settings — the old one is replaced rather than joined, so the three URLs Mailgun allows per event type are not spent on an address nothing answers any more.

That button needs the API key in this plugin's settings to be an **account key with permission to manage webhooks**. A domain sending key can send mail and cannot do either of those things.

**Doing it by hand.** In Mailgun, open **Sending → Webhooks** and pick your sending domain. Add the webhook address once for each of Delivered Messages, Permanent Failure, Temporary Failure, Spam Complaints, Opens and Clicks. Then find the **HTTP signing key** under **Send → Webhooks → Configuration** — it is a different string from the sending API key, which is the thing people get wrong — and paste it into **Webhook Signing Key** here. Without it, every event Mailgun posts is refused, because there is no way to tell one from anybody else's.

**What Mailgun does not do.** Its delivery events carry only four of a message's headers, so a custom header put on the message never comes back. Events are tied to the message they came from by `Message-ID` instead, which needs nothing setting up.

## Receiving mail

With Email 5.3 or later, this plugin can also read mail sent *to* your site through a Mailgun route, for a plugin that wants it — a helpdesk turning replies into ticket updates, say. That plugin gives you the address to paste and calls the Email plugin's inbound gateway; this plugin is the part that knows what Mailgun sends. On an older Email plugin nothing changes and the inbound part simply isn't offered.

**A receiving domain first.** Mailgun only sees mail for a domain whose MX records point at it. Add a receiving domain in Mailgun (a subdomain such as `inbound.example.com` keeps it apart from the mail you already get) and add the MX records Mailgun lists for it: `mxa.mailgun.org` and `mxb.mailgun.org` with priority 10 in the US region, `mxa.eu.mailgun.org` and `mxb.eu.mailgun.org` in the EU region. To keep your support mailbox where it is, forward it to an address on that domain instead.

**Then a route.** Under **Receiving → Routes**, create a route. For the expression choose Match Recipient and enter the address, for example `support@inbound.example.com`; to catch plus addresses such as `support+abc@` too, use `match_recipient("^support(\+.*)?@inbound\.example\.com$")`. For the action, pick one of three:

- **Forward to the address you were given.** Mailgun posts each message already parsed: `recipient`, `sender`, `from`, `subject`, `body-plain`, `body-html`, `stripped-text`, `message-headers` and, when there are attachments, `attachment-1` to `attachment-n` as files with a `content-id-map` for inline images. This is the default and needs nothing else.
- **Forward to the same address with `?format=mime` on the end.** Mailgun's rule is that a forward URL ending in `mime` gets the whole message as `body-mime` instead of the parsed bodies, and the site then reads it byte for byte.
- **Store and notify, with the same address.** Mailgun keeps the message for up to three days and posts a notice with a `message-url`. The receiving plugin stores that notice straight away and downloads the message later from its own background worker, using the **API key** in this plugin's settings, with `Accept: message/rfc2822`. The download only ever goes to an https address on `mailgun.net`, so a forged notice cannot collect the key. Useful for very large mail.

The address that was delivered to (`recipient`) is kept as the envelope recipient, so a reply to `support+t8f2k@…` keeps its `t8f2k` even when the visible To says `support@`. `stripped-text` is kept as the provider's quote-stripped reply. If the receiving domain's spam filter is set to mark spam with MIME headers, `X-Mailgun-Sscore`, `X-Mailgun-Spf` and `X-Mailgun-Dkim-Check-Result` give the spam score and the SPF and DKIM results.

**Signed with the key you already have.** Mailgun signs every route post with the same HTTP webhook signing key as the delivery reports, in the form fields `timestamp`, `token` and `signature`. So the **Webhook Signing Key** above is all it needs. A post is refused when the signature does not match, when its timestamp is more than 15 minutes from the site's clock, and when its token has been seen before. Used tokens are remembered in Grav's cache for 30 minutes, twice the window, which covers every moment a captured post could still pass the clock check. Clearing the cache forgets them, which at worst reopens that window; the receiving plugin's own duplicate check on the `Message-ID` still catches a copy.

**Size.** Mailgun accepts messages up to 25 MB. The receiver accepts posts up to 50 MB, since a raw forward is url-encoded and grows on the way; the receiving plugin can set a lower limit of its own, and so can PHP's `post_max_size`. Mailgun treats a 406 answer as final and retries anything else but 200 for eight hours.

## Links

- Mailgun API docs: https://documentation.mailgun.com/en/latest/api-intro.html
- Symfony Mailgun mailer bridge: https://github.com/symfony/mailgun-mailer

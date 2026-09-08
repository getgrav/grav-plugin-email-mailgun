# v1.1.2
## 09/08/2026

1. [](#bugfix)
    * **The signing key is where Mailgun keeps it now.** Every mention of it — the settings help, the Set up messages, the by-hand instructions on the card, the README — sent people to the API Security page under the profile menu, and Mailgun has moved it to Send, Webhooks, on the Configuration tab, where it is called the HTTP signing key. Sending somebody to a page that no longer has the thing on it is worse than saying nothing, because they go looking for a second key that does not exist
    * **A domain sending key is told apart from a broken one.** Mailgun offers a sending key on the domain screen, which is where somebody setting a store up is already standing, and it sends mail perfectly well — it just cannot create a webhook, and it cannot read the account's signing key. Pressing Set up with one got "Mailgun refused the API key", which sends a merchant off to re-copy a key that was never mistyped. It now says a sending key cannot create webhooks, that this needs an account API key and where that lives, and it passes on Mailgun's own sentence as well. The key field's help says the same thing before the button is ever pressed. Every test here had missed it because a sending key **reads** the webhook list with a 200 and is only refused on the create — so any check that stopped at the listing call declared the key good
    * **The API key and the sending domain are marked required, and the form says why.** Mailgun's API address contains the sending domain — `/v3/<domain>/messages` — so unlike every other provider here a key on its own cannot send anything, and this plugin threw when asked to build a transport without one. Thrown from where it was, that took down every page of the site rather than failing a send; the Email plugin 5.2.0 now catches it, and marking the two fields required stops the store reaching that state at all. Both fields carry help text: the key is the sending key rather than the public validation key, and the domain is written exactly as Mailgun lists it

# v1.1.1
## 09/05/2026

1. [](#bugfix)
    * **Set up now repairs a webhook whose address has changed.** A store that generated a new secret, or lost its settings, was told nothing was registered while Mailgun still held the old address on every event type, and pressing Set up added the new address beside the dead one — on a type already holding the three URLs Mailgun allows, it could not add anything at all. Set up now recognises the store's own address by its endpoint and replaces it on each event type, leaving anybody else's webhook where it is.

# v1.1.0
## 09/05/2026

1. [](#new)
    * Mailgun's delivery reports now belong to this plugin. It registers a provider on the Email plugin's new `onEmailProviders` event, so anything on the site that records deliveries reads bounces, complaints, opens and clicks without carrying any Mailgun code of its own
    * A **Set up** button that creates the six webhooks on your sending domain through Mailgun's API, leaves any other webhook you have alone, and is safe to press twice
    * The HTTP webhook signing key is read back out of your Mailgun account and saved here, so there is nothing to hunt for on the API Security page
    * New `signing_key` setting, for pasting that key by hand when the API key is a domain sending key rather than an account key
    * The plugin now says what it does to a message's headers, what a Mailgun sending domain's DNS should look like, and how to find a domain's DKIM selector — all things the newsletter add-on used to have to know on Mailgun's behalf
    * A `failed` that Mailgun never attempted is now reported as the contract's `dropped` rather than as a bounce. Mailgun sends a `failed` for both, and tells them apart in the `reason`: one beginning `suppress-` means the address was already on one of Mailgun's own suppression lists and no receiving server ever saw the message. Reporting that as a bounce said a mail server had refused an address when nothing of the sort had happened. Every one of those drops says the address was refused rather than the message, because that is what the prefix means: Mailgun already holds the address on one of its three lists and will refuse the next message to it as well, so a store may treat it as permanent. Mailgun has nothing that is the other half — a message it will not send for a reason of its own is refused by the API call rather than reported later
    * The user variable a send id travels in is now named by the Email plugin rather than by this one. It is `X-Grav-Send-Id`, or whatever `providers.send_header` in the Email plugin's configuration says; it used to be `X-KahunaCart-Send`, which was another product's name sitting in a Team Grav plugin
2. [](#improved)
    * A `classes/` directory with a test suite behind it, matching the other Email transport plugins

# v1.0.2
## 05/01/2026

1. [](#improved)
    * Added 1.7|2.0 compatibility flags

# v1.0.1
## 05/09/2023

1. [](#bugfix)
   * fix null config bug

# v1.0.0
## 05/09/2023

1. [](#new)
   * Initial public release
   
# v0.1.0
##  10/01/2022

1. [](#new)
    * ChangeLog started...

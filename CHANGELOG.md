# v1.1.0
## 09/05/2026

1. [](#new)
    * Mailgun's delivery reports now belong to this plugin. It registers a provider on the Email plugin's new `onEmailProviders` event, so anything on the site that records deliveries reads bounces, complaints, opens and clicks without carrying any Mailgun code of its own
    * A **Set up** button that creates the six webhooks on your sending domain through Mailgun's API, leaves any other webhook you have alone, and is safe to press twice
    * The HTTP webhook signing key is read back out of your Mailgun account and saved here, so there is nothing to hunt for on the API Security page
    * New `signing_key` setting, for pasting that key by hand when the API key is a domain sending key rather than an account key
    * The plugin now says what it does to a message's headers, what a Mailgun sending domain's DNS should look like, and how to find a domain's DKIM selector — all things the newsletter add-on used to have to know on Mailgun's behalf
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

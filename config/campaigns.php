<?php

declare(strict_types=1);

return [
    /*
     * The master switch for sending. While it is false nothing is claimed or
     * sent, campaigns can't start and no double opt-in email goes out;
     * subscriptions, the unsubscribe and confirmation pages and the consent
     * records keep working, since they only record what people chose.
     * Default: false.
     */
    'enabled' => (bool) env('CAMPAIGNS_ENABLED', false),

    /*
     * The base URL of every link the package puts in an email: unsubscribe,
     * preferences, confirmation and the web version. Mail already delivered
     * keeps pointing at this host, so it must keep answering these links
     * without a redirect for as long as that mail exists. Default: APP_URL.
     */
    'url' => env('CAMPAIGNS_URL'),

    /*
     * What the unsubscribe and confirmation pages and emails call the app, as
     * in "Unsubscribe from all <name> emails?". Default: APP_NAME.
     */
    'name' => env('CAMPAIGNS_NAME'),

    /*
     * The mailer campaigns and the package's own emails go through, and the
     * sender campaigns start with. Use a mailer on your marketing domain, so
     * its reputation stays apart from your account emails. A null address
     * takes the mailer's own sender. A campaign's From address must be on
     * the marketing domain: "domain", else the sender's domain. Replies go
     * to "reply_to", an inbox someone reads (it may be on another domain).
     * Default mailer: "campaigns", which you define in config/mail.php. With
     * the Mailgun provider it uses the mailgun transport with
     * 'scheme' => 'api' (or the log mailer locally); a mailgun mailer with
     * no 'client' options gets a 10-second idle timeout and 30 seconds in
     * all, so a stuck request ends before the job that sends it.
     */
    'mail' => [
        'mailer' => env('CAMPAIGNS_MAILER', 'campaigns'),
        'from' => [
            'address' => env('CAMPAIGNS_FROM_ADDRESS'),
            'name' => env('CAMPAIGNS_FROM_NAME'),
        ],
        'reply_to' => env('CAMPAIGNS_REPLY_TO'),
        'domain' => env('CAMPAIGNS_DOMAIN'),
    ],

    /*
     * The service that delivers campaign email: "mailgun", "fake" (keeps
     * what it is given and sends nothing, for tests and local runs), or the
     * class name of your own Mozex\Campaigns\Contracts\CampaignProvider.
     * Default: mailgun.
     */
    'provider' => env('CAMPAIGNS_PROVIDER', 'mailgun'),

    /*
     * Mailgun's settings beyond the campaigns mailer. Its webhooks post to
     * /campaigns/webhooks/mailgun and are verified with the account's HTTP
     * webhook signing key (Settings, API Security in Mailgun), which is
     * neither the API key nor a domain sending key.
     *
     * api_key reads Mailgun's logs and suppression lists, which the
     * reconciler, the suppressions import and an unblock's check need: an
     * account API key, since a domain sending key can only send. Where your
     * plan offers roles, the Support role is the narrowest that reads logs
     * and suppressions. endpoint is the region's API host, such as
     * api.eu.mailgun.net (default: the campaigns mailer's endpoint, else
     * api.mailgun.net). log_days is how many days back your plan keeps logs
     * (5 on Foundation, 30 on Scale): nothing older is looked for.
     */
    'mailgun' => [
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
        'api_key' => env('CAMPAIGNS_MAILGUN_API_KEY'),
        'endpoint' => env('CAMPAIGNS_MAILGUN_ENDPOINT'),
        'log_days' => 5,
    ],

    /*
     * Countries whose subscribers' opens are never tracked, whatever a
     * campaign says, as two-letter codes: France's data protection authority
     * requires consent for a tracking pixel. Clicks are still tracked.
     * Default: ['FR'].
     */
    'no_open_tracking_countries' => ['FR'],

    /*
     * Shown by the {{footer}} and {{postal_address}} placeholders. Some laws
     * (CAN-SPAM in the US among them) require a postal address in every
     * marketing email. Default: none.
     */
    'postal_address' => env('CAMPAIGNS_POSTAL_ADDRESS'),

    /*
     * Your privacy policy, linked by {{footer}} and {{privacy_url}}. Mailgun's
     * acceptable use policy asks for a link to it in every email. Default:
     * none.
     */
    'privacy_url' => env('CAMPAIGNS_PRIVACY_URL'),

    /*
     * Who is told, through the app's default mailer, when a campaign pauses
     * itself, gets its first spam report, or has emails waiting on a queue
     * no worker takes (at most once every six hours). Default: nobody.
     */
    'alert_address' => env('CAMPAIGNS_ALERT_ADDRESS'),

    /*
     * The only addresses a test email may go to, comma-separated in the
     * environment. Default: none, so no test email goes out until you list
     * one.
     */
    'test_addresses' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CAMPAIGNS_TEST_ADDRESSES', '')),
    ))),

    /*
     * The language an email's main content is written in. Every revision of
     * a template or campaign email holds it, and a recipient whose language
     * a revision lacks gets it. Default: the app's configured locale
     * (app.locale), read when the app starts, never a visitor's.
     */
    'locale' => env('CAMPAIGNS_LOCALE'),

    /*
     * Where the images emails load are stored: a disk whose files anyone can
     * read and whose url() gives https addresses that never change, since
     * delivered mail keeps loading them. An image may be at most max_size
     * bytes (default 5 MB) and is a JPEG, PNG, GIF or WebP file.
     */
    'assets' => [
        'disk' => env('CAMPAIGNS_ASSETS_DISK', 'public'),
        'path' => 'campaigns/assets',
        'max_size' => 5 * 1024 * 1024,
    ],

    /*
     * Where files sent through an upload link wait until a tool uses them:
     * a private disk, since an upload may hold an unsent email or a list of
     * contacts. A file may be at most max_size bytes (default 25 MB); keep
     * it under what your proxy accepts in one request.
     */
    'uploads' => [
        'disk' => env('CAMPAIGNS_UPLOADS_DISK', 'local'),
        'path' => 'campaigns/uploads',
        'max_size' => 25 * 1024 * 1024,
    ],

    /*
     * The checks run on an email before it is sent. "spam_score" sends the
     * rendered email, with a placeholder recipient, to Postmark's free
     * SpamAssassin checker. Default: off.
     */
    'checks' => [
        'spam_score' => false,
    ],

    /*
     * The UTM tags added to a campaign's links to your own sites when it
     * starts (a campaign may change or switch them off): utm_source and
     * utm_medium from here, utm_campaign the campaign's key. A link that
     * already carries a utm_ parameter keeps its own. "hosts" are your sites:
     * a link to one of them or a subdomain of it is tagged. Default hosts:
     * those of APP_URL and the links' base URL, without a leading "www.".
     */
    'utm' => [
        'source' => 'newsletter',
        'medium' => 'email',
        'hosts' => [],
    ],

    /*
     * The dynamic lists fed from the app's own records, keyed by the list's
     * key: ['accounts' => App\Campaigns\AccountList::class]. Each class
     * implements Mozex\Campaigns\Contracts\ListSource, and its model uses the
     * Mozex\Campaigns\Concerns\HasCampaignSubscriber trait. Run
     * `php artisan campaigns:sync-list <key>` once to bring in the records
     * that already exist.
     */
    'list_sources' => [],

    /*
     * Named audiences an agent can pick for a campaign, keyed by name:
     * ['buyers' => App\Campaigns\BuyersAudience::class]. Each class
     * implements Mozex\Campaigns\Contracts\Audience: its parameters, their
     * validation rules and a query on your own records (or on subscribers).
     */
    'audiences' => [],

    /*
     * Read-only data agents may read to choose an audience or write an
     * email, such as your products, keyed by name:
     * ['courses' => App\Campaigns\CourseResource::class]. Each class
     * implements Mozex\Campaigns\Contracts\AgentResource.
     */
    'resources' => [],

    /*
     * The MCP server agents run campaigns through, at "path" on this app.
     * It answers only to a principal of one of "guards", tried in order:
     * "campaigns" is the package's own tokens
     * (`php artisan campaigns:token create <name>`), which carry read or
     * full rights. Add your OAuth guard after it (such as Passport's "api")
     * to let people sign in, and define the gate abilities campaigns.read
     * and campaigns.full for the people who may; anyone signed in with
     * neither is refused (403). "middleware" runs after sign-in.
     * "instructions" is your own text for agents, added after the package's
     * (both together stay under 4,096 characters, which is what Claude Code
     * keeps). Default path: mcp/campaigns.
     */
    'mcp' => [
        'enabled' => (bool) env('CAMPAIGNS_MCP_ENABLED', true),
        'path' => env('CAMPAIGNS_MCP_PATH', 'mcp/campaigns'),
        'guards' => ['campaigns'],
        'middleware' => [],
        'instructions' => null,
    ],

    /*
     * How recently someone must have opened or clicked an email, or done
     * something in your app (Campaigns::recordActivity()), to count as
     * engaged or active; anyone else is silent. An address that stays
     * silent for sunset_months after the later of its last engagement and
     * its first campaign email is no longer mailed; null never stops one.
     */
    'engagement' => [
        'engaged_days' => 90,
        'active_days' => 365,
        'sunset_months' => 12,
    ],

    /*
     * Where the send jobs and webhook processing run. A null connection uses
     * the app's default queue connection. Give this queue its own worker
     * (one or two processes), with a job timeout below the worker's and the
     * worker's below the connection's retry_after.
     */
    'queue' => [
        'connection' => env('CAMPAIGNS_QUEUE_CONNECTION'),
        'name' => env('CAMPAIGNS_QUEUE', 'campaigns'),
    ],

    /*
     * The cache store that holds the scheduled commands' overlap locks, the
     * one waiting stop-loss check per campaign, when the pump last ran, and
     * the marks that limit a misconfigured webhook's or an unreadable
     * provider's error report to one an hour and the stalled-queue alert to
     * one every six. Point it at a store
     * your deploys don't flush, so a release can't free a lock a running pump
     * still holds. Null uses the app's default store.
     */
    'cache_store' => env('CAMPAIGNS_CACHE_STORE'),

    /*
     * Whether the package registers its scheduled commands (the pump every
     * minute, reconciliation every 15 minutes, pruning daily). Turn it off
     * to schedule them yourself. Default: true.
     */
    'schedule' => true,

    /*
     * How many days the provider's events are kept (who opened, clicked or
     * bounced, without addresses). A finished campaign's report is frozen
     * before its events go; a campaign still sending or paused keeps them
     * until it completes or is cancelled. Default: 180.
     */
    'event_retention_days' => 180,

    /*
     * The pace a campaign falls back to for any value it leaves unset. Each
     * campaign sets its own pace when it starts and can change it while it
     * runs. first_day is the number of emails on the first day, and each
     * later day sends daily_growth percent more than the day before, up to
     * daily_cap (null: no cap beyond the growth). per_minute is the most the
     * pump hands to the queue in one minute. sending_hours limits sending to
     * a daily window in the campaign's timezone, such as ['09:00', '18:00'];
     * null sends at any hour.
     */
    'pace' => [
        'first_day' => 100,
        'daily_growth' => 20,
        'daily_cap' => null,
        'per_minute' => 10,
        'sending_hours' => null,
        'timezone' => 'UTC',
    ],

    /*
     * The most emails each sending domain may send in a day across all its
     * campaigns, keyed by domain: ['news.example.com' => 5000]. A domain
     * that isn't listed is capped by the smallest daily cap among its
     * running campaigns.
     */
    'domain_daily_caps' => [],

    /*
     * When a campaign pauses itself. Rates are percentages of the emails it
     * has sent. Before early_until sends, it pauses at early_hard_bounces
     * hard bounces, or at early_hard_bounce_rate once it has sent
     * early_min_sends; after that, when hard bounces pass hard_bounce_rate of
     * its last hard_bounce_window sends. Before complaints_until sends it
     * pauses at `complaints` spam reports, and after that above
     * complaint_rate. It also pauses when unsubscribes pass unsubscribe_rate
     * once it has sent unsubscribe_min_sends. The same bounce and complaint
     * measures over a sending domain's last 24 hours pause every campaign on
     * that domain. A campaign may override any of these for itself; the
     * domain keeps these. What paused a campaign or a domain counts again
     * from that pause, so a campaign resumed after a look is not paused again
     * by the same numbers.
     */
    'stop_loss' => [
        'early_until' => 200,
        'early_hard_bounces' => 10,
        'early_hard_bounce_rate' => 5.0,
        'early_min_sends' => 50,
        'hard_bounce_rate' => 2.0,
        'hard_bounce_window' => 500,
        'complaints_until' => 2500,
        'complaints' => 2,
        'complaint_rate' => 0.08,
        'unsubscribe_rate' => 1.4,
        'unsubscribe_min_sends' => 500,
    ],
];

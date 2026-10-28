<?php

declare(strict_types=1);

return [
    /*
     * The master switch for sending. While it is false nothing is claimed or
     * sent and campaigns can't start; subscriptions, unsubscribes and the
     * consent records keep working, since they only record what people chose.
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
     * The cache store that holds the scheduled commands' overlap locks. Point
     * it at a store your deploys don't flush, so a release can't free a lock
     * a running pump still holds. Null uses the app's default store.
     */
    'cache_store' => env('CAMPAIGNS_CACHE_STORE'),

    /*
     * Whether the package registers its scheduled commands (the pump every
     * minute, reconciliation every 15 minutes, pruning daily). Turn it off
     * to schedule them yourself. Default: true.
     */
    'schedule' => true,

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
     * that domain. A campaign may override any of these.
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

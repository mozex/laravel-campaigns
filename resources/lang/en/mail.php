<?php

declare(strict_types=1);

return [
    'confirm' => [
        'subject' => 'Confirm your subscription to :list',
        'heading' => 'Confirm your subscription',
        'body' => 'Someone, hopefully you, asked for :list emails from :app at this address. Confirm, and they will start coming.',
        'button' => 'Confirm my subscription',
        'link' => "If the button doesn't work, open this link:",
        'ignore' => "Didn't ask for this? Ignore this email and you won't be added.",
    ],

    'alert' => [
        'paused_subject' => ':app: the campaign ":campaign" paused itself',
        'paused' => 'The campaign ":campaign" (:key) is paused, and nothing more goes out until it is resumed. Why:',
        'paused_next' => 'Read its report and find the cause before resuming it.',
        'complaint_subject' => ':app: the campaign ":campaign" got its first spam report',
        'complaint' => 'Someone marked an email of the campaign ":campaign" (:key) as spam. It keeps sending: it pauses itself at :count spam reports before :until emails, and above :rate% after that.',
        'stalled_subject' => ':app: campaign emails are waiting on the queue',
        'stalled' => ':count emails of the campaign ":campaign" (:key) have waited on the ":queue" queue since :since, and it sends nothing more until they move.',
        'stalled_next' => 'Check that a worker runs that queue. This notice comes at most once every :hours hours.',
    ],

    'footer' => [
        'sent_to' => ':app sent this email to :email.',
        'unsubscribe' => 'Unsubscribe or change your email preferences',
        'privacy' => 'Privacy policy',
    ],
];

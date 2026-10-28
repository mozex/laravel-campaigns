<?php

declare(strict_types=1);

return [
    'subscribed' => [
        'title' => 'Unsubscribe',
        'heading' => 'Unsubscribe from all :app emails?',
        'body' => "You'll stop getting news and offers from :app.",
        'button' => 'Unsubscribe',
    ],

    'unsubscribed' => [
        'title' => 'Unsubscribed',
        'heading' => "You're unsubscribed",
        'body' => "You won't get any more news or offers from :app.",
        'again' => 'Changed your mind? You can subscribe again.',
        'button' => 'Subscribe again',
    ],

    'resubscribed' => [
        'title' => 'Subscribed',
        'heading' => "You're subscribed again",
        'body' => "You'll get news and offers from :app again.",
    ],

    'blocked' => [
        'title' => 'Not sending',
        'heading' => "We've stopped emailing this address",
        'body' => 'Emails from :app to this address bounced or were marked as spam, so we no longer send any.',
    ],

    'gone' => [
        'title' => 'Not on our lists',
        'heading' => "This address isn't on our lists",
        'body' => "You won't get news or offers from :app here.",
    ],

    'test' => 'This link came from a test email, so using it changes nothing.',

    'confirm' => [
        'title' => 'Confirm your subscription',
        'heading' => 'Confirm your subscription',
        'body' => 'Confirm that you want :list emails from :app.',
        'button' => 'Confirm',
    ],

    'confirmed' => [
        'title' => 'Subscribed',
        'heading' => "You're subscribed",
        'body' => 'Thanks for confirming. :list emails from :app will come to this address.',
    ],

    'invalid' => [
        'title' => 'Link not valid',
        'heading' => "This link doesn't work",
        'body' => 'It may be incomplete, or it was already used. If you copied it from an email, check that you copied all of it.',
    ],
];

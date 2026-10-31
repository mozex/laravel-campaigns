<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Mail;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Support\Sender;
use Throwable;

/**
 * What a driver that sends through the app's campaigns mailer
 * (campaigns.mail.mailer) checks before each send: a mailer is named, it
 * can be built, and its transport is the one the driver knows how to drive.
 * The log and array mailers are allowed too, for local runs and tests,
 * except in production, where they would mark a whole campaign sent with
 * nothing delivered and write every message, links' tokens and all, to the
 * app's log.
 */
trait UsesCampaignsMailer
{
    /**
     * @param  class-string  $transport  the transport the driver drives
     * @param  string  $service  what the mailer must send through, as in "must send through Mailgun's API"
     * @param  string  $setup  how to give the mailer that transport, as in "the smtp transport"
     *
     * @throws SendFailed
     */
    protected function campaignsMailer(string $transport, string $service, string $setup): MailerContract
    {
        $name = trim(Sender::mailer());

        // A blank name would resolve the app's default mailer, which is
        // usually the transactional domain's.
        if ($name === '') {
            throw new SendFailed(SendFailure::DomainRefused, 'No campaigns mailer is named (campaigns.mail.mailer, CAMPAIGNS_MAILER).');
        }

        try {
            $mailer = Mail::mailer($name);
        } catch (Throwable $exception) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" can't be used: ".SendFailed::scrub($exception->getMessage()));
        }

        // A faked mailer (Mail::fake()) has no transport to check.
        if (! $mailer instanceof Mailer) {
            return $mailer;
        }

        $symfonyTransport = $mailer->getSymfonyTransport();
        $delivers = $symfonyTransport instanceof $transport;

        if (! $delivers && ! $symfonyTransport instanceof LogTransport && ! $symfonyTransport instanceof ArrayTransport) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" must send through {$service}: give it {$setup} (or the log or array mailer locally).");
        }

        if (! $delivers && app()->isProduction()) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" delivers nothing, which production never sends through: give it {$setup}.");
        }

        return $mailer;
    }
}

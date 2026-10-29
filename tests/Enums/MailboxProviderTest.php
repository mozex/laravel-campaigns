<?php

declare(strict_types=1);

use Mozex\Campaigns\Enums\MailboxProvider;

it('tells mailbox providers apart by the address\'s domain', function (string $email, MailboxProvider $provider): void {
    expect(MailboxProvider::fromEmail($email))->toBe($provider);
})->with([
    ['a@gmail.com', MailboxProvider::Gmail],
    ['a@googlemail.com', MailboxProvider::Gmail],
    ['a@hotmail.co.uk', MailboxProvider::Microsoft],
    ['a@outlook.fr', MailboxProvider::Microsoft],
    ['a@live.com', MailboxProvider::Microsoft],
    ['a@yahoo.com.br', MailboxProvider::Yahoo],
    ['a@aol.com', MailboxProvider::Yahoo],
    ['a@icloud.com', MailboxProvider::Apple],
    ['a@gmail.com.example.org', MailboxProvider::Other],
    ['a@company.com', MailboxProvider::Other],
]);

<?php

declare(strict_types=1);

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Imports\ContactsCsv;

it('reads each contact by its header, custom fields and meta by name', function (): void {
    $csv = "\u{FEFF}Email;Name;custom_fields.Company;META.source;opted_out\r\n"
        ."ann@example.com;\"Doe; Ann\";Acme;expo;\r\n"
        ."\r\n"
        ."bob@example.com;Bob;;;yes\n"
        ."\"multi\nline@example.com\";Line\n";

    expect(ContactsCsv::read($csv))->toBe([
        ['email' => 'ann@example.com', 'name' => 'Doe; Ann', 'custom_fields' => ['Company' => 'Acme'], 'meta' => ['source' => 'expo'], 'opted_out' => ''],
        ['email' => 'bob@example.com', 'name' => 'Bob', 'opted_out' => 'yes'],
        ['email' => "multi\nline@example.com", 'name' => 'Line', 'opted_out' => ''],
    ])
        ->and(ContactsCsv::read("email\tname\nann@example.com\tAnn\n"))->toBe([['email' => 'ann@example.com', 'name' => 'Ann']])
        ->and(ContactsCsv::read("email,name\nann@example.com,Ann,,\n"))->toBe([['email' => 'ann@example.com', 'name' => 'Ann']]);
});

it('refuses a file it can\'t read rightly, naming columns and rows, never addresses', function (string $csv, string $problem): void {
    $message = '';

    try {
        ContactsCsv::read($csv);
    } catch (CampaignsException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toContain($problem)
        ->and(str_contains($message, '@'))->toBeFalse("The refusal quotes an address: {$message}");
})->with([
    'no header line' => ["ann@example.com,Ann Smith\nbob@example.com,Bob\n", 'The first line looks like a contact. It must name the columns: email, name,'],
    'too many custom fields' => ['email,'.implode(',', array_map(fn (int $number): string => "custom_fields.f{$number}", range(1, 101)))."\n", 'It has 101 custom field columns; a contact holds at most 100.'],
    'not UTF-8' => ["email\n\xE9t\xE9@example.com\n", 'It isn\'t UTF-8 text.'],
    'empty' => ["\n\n", 'It is empty'],
    'an unknown column' => ["email,segment\n", 'Column 2 ("segment") isn\'t a contact field.'],
    'a custom field without a name' => ["email,custom_fields.\n", 'Column 2 needs a name after "custom_fields.":'],
    'a column twice' => ["email,name,Name\n", 'Column 3 repeats column 2.'],
    'no email' => ["name\nAnn\n", 'No column is named email.'],
    'a value past the header' => ["email,name\nann@example.com,Doe, Ann\n", "The CSV can't be imported:\n- Row 1 has more values than the header has columns. Quote a value that holds a comma."],
    'too many contacts' => ["email\n".str_repeat("a@example.com\n", ContactsCsv::MAX_ROWS + 1), 'It holds more than 2,000 contacts.'],
]);

it('names at most twenty problems', function (): void {
    $message = '';

    try {
        ContactsCsv::read("email\n".str_repeat("a@example.com,extra\n", 25));
    } catch (CampaignsException $exception) {
        $message = $exception->getMessage();
    }

    expect(substr_count($message, 'has more values'))->toBe(20)
        ->and($message)->toEndWith('- And 5 more like these.')
        ->and($message)->not->toContain('@');
});

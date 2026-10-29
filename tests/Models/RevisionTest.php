<?php

declare(strict_types=1);

use Mozex\Campaigns\Models\Revision;

it('finds a recipient\'s version by locale, then language, then the default', function (?string $locale, string $expected): void {
    $revision = Revision::factory()
        ->withContent('en', ['subject' => 'en'])
        ->withContent('fr', ['subject' => 'fr'])
        ->withContent('fr_BE', ['subject' => 'fr_BE'])
        ->withContent('pt-BR', ['subject' => 'pt-BR'])
        ->create();

    expect($revision->contentFor($locale)->subject)->toBe($expected);
})->with([
    'exact' => ['fr_BE', 'fr_BE'],
    'written another way' => ['FR-be', 'fr_BE'],
    'a region it lacks, to the language' => ['fr_CA', 'fr'],
    'the language' => ['fr', 'fr'],
    'a region, to another of the language' => ['pt_PT', 'pt-BR'],
    'the language, to its one regional version' => ['pt', 'pt-BR'],
    'another language' => ['de', 'en'],
    'none' => [null, 'en'],
]);

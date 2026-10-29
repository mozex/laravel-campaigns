<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Mcp\Tools\PreviewContactsImportTool;
use Mozex\Campaigns\Mcp\Tools\RunContactsImportTool;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;

beforeEach(function (): void {
    Storage::fake('local');
});

it('previews an uploaded CSV, then imports it with the same numbers', function (string $driver): void {
    useDatabase($driver);
    CampaignList::factory()->create(['key' => 'events']);
    $rows = implode("\n", array_map(fn (int $number): string => "nobody-{$number}", range(1, 60)));
    // A row's meta.by never replaces who ran the import.
    $upload = contactsUpload("email,name,opted_out,meta.by\nann@example.com,Ann,,someone else\nbob@example.com,Bob,yes,someone else\n{$rows}\n");
    $arguments = ['upload_id' => $upload->uuid, 'source' => 'expo-2026', 'list' => 'events', 'basis' => 'soft_opt_in'];

    $preview = structuredContent(agent()->tool(PreviewContactsImportTool::class, $arguments)->assertOk());
    $saved = Subscriber::query()->count();
    $run = structuredContent(agent()->tool(RunContactsImportTool::class, $arguments)->assertOk());

    expect($saved)->toBe(0)
        ->and($preview)->toBe([...$run, 'dry_run' => true])
        ->and($run)
        ->rows->toBe(62)
        ->never_mailed->toBe(1)
        ->problems_total->toBe(60)
        ->and($run['problems'])->toHaveCount(50)
        ->and(array_filter($run['counts']))->toBe(['created' => 2, 'joined' => 2, 'opted_out' => 1])
        ->and(Subscriber::findByEmail('bob@example.com')->opted_out_at)->not->toBeNull()
        ->and(ConsentRecord::query()->first())
        ->source->toBe('expo-2026')
        ->basis->toBe(ConsentBasis::SoftOptIn)
        ->meta->toBe(['by' => 'laptop'])
        ->ip_address->toBeNull()
        ->user_agent->toBeNull();

    agent()->tool(RunContactsImportTool::class, $arguments)->assertHasErrors(['it was already used']);
})->with('databases');

it('refuses a file it can\'t read, leaving the upload for a fixed one', function (): void {
    $upload = contactsUpload("email,segment\nann@example.com,vip\n");

    agent()->tool(RunContactsImportTool::class, ['upload_id' => $upload->uuid, 'source' => 'expo'])
        ->assertHasErrors(['Column 2 ("segment") isn\'t a contact field.']);
    agent()->tool(PreviewContactsImportTool::class, ['upload_id' => $upload->uuid, 'source' => str_repeat('s', 126)])
        ->assertHasErrors(['The consent source may be at most 125 characters long.']);

    expect($upload->fresh()->claimed_at)->toBeNull();
});

<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Sending\Pace;
use Mozex\Campaigns\Support\CampaignSettings;

/**
 * A draft campaign's settings, as create_campaign and update_campaign take
 * them and Support\CampaignSettings checks them. Only the settings given
 * change; pace and thresholds merge into what the campaign has.
 */
trait DescribesCampaignSettings
{
    protected static function settingsInput(JsonSchema $schema): Type
    {
        return $schema->object([
            'name' => $schema->string(),
            'from_name' => $schema->string()->description('The sender\'s name; null for none.'),
            'from_email' => $schema->string()->description('An address on the sending domain the overview names.'),
            'reply_to' => $schema->string()->description('Where replies go; null for none.'),
            'lists' => $schema->array()->items($schema->string())->description('The target lists\' keys: everyone subscribed on any of them, once each.'),
            'audience' => $schema->object()->description(Schemas::AUDIENCE),
            'track_opens' => $schema->boolean(),
            'track_clicks' => $schema->boolean(),
            'pace' => $schema->object()->description('Any of '.implode(', ', Pace::KEYS).': first_day (sends on day 1), daily_growth (each day\'s growth, a percentage from 0 to 1000), daily_cap (most a day; null for none), per_minute, sending_hours (["09:00", "17:00"]; null for any time), timezone (like Europe/Paris). Left out, a value follows the app\'s config.'),
            'thresholds' => $schema->object()->description('Its own stop-loss limits, by the keys of the app\'s campaigns.stop_loss config; left out, a limit follows the config.'),
        ])->description('Any of '.implode(', ', CampaignSettings::KEYS).'. utm takes true for the default UTM tags (source and medium from the config, the key as campaign), false for none, or {"source", "medium", "campaign", "content", "term"} to replace them.');
    }

    /**
     * The settings as given: an empty object arrives as an empty array.
     *
     * @return array<string, mixed>
     */
    protected static function settings(mixed $given): array
    {
        /** @var array<string, mixed> */
        return is_array($given) ? $given : [];
    }
}

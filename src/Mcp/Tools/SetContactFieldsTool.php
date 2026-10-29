<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\SetCustomFieldsAction;
use Mozex\Campaigns\Reports\SubscriberSummary;

#[Name('set_contact_fields')]
#[Title('Set a contact\'s custom fields')]
#[Description('Sets custom fields on one address, the free-form data filters and saved audiences target: the fields given replace the ones it has, null removes one, the rest stay. Names are letters, digits and underscores; values text (up to 1,000 characters), whole numbers, true or false. A field the app keeps in step for its own records is written again by its next sync.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class SetContactFieldsTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->required(),
            'fields' => $schema->object()->required()->description('Names to values; null removes a field.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string(),
            'custom_fields' => $schema->object()->description('Every custom field it has now, text cut to '.SubscriberSummary::FIELD_LENGTH.' characters.'),
        ];
    }

    public function perform(Request $request, SetCustomFieldsAction $set): ResponseFactory
    {
        $input = $request->validate([
            'email' => ['required', 'string'],
            'fields' => ['required', 'array'],
        ]);

        /** @var array<string, string|int|bool|null> $fields */
        $fields = $request->get('fields');
        $subscriber = $set->execute(static::subscriber($input['email']), $fields);

        return Response::structured([
            'email' => $subscriber->email,
            'custom_fields' => (object) SubscriberSummary::customFields($subscriber),
        ]);
    }
}

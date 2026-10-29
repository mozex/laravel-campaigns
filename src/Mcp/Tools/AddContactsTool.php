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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\AddContactsAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ImportChange;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\AnswersImports;
use Mozex\Campaigns\Models\CampaignList;

#[Name('add_contacts')]
#[Title('Add contacts to a list')]
#[Description('Adds up to 100 contacts to a static list; for more, upload a CSV and import it. Nobody opted out or blocked is mailed again, someone already on the list stays as they are, and an existing person gains only details they lack. Say why the app may email them (basis). On a double opt-in list they wait to confirm, and this sends no confirmation email.')]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class AddContactsTool extends CampaignsTool
{
    use AnswersImports;

    public function schema(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()->required()->description('The static list\'s key.'),
            'basis' => $schema->string()->enum(array_column(CreateListTool::BASES, 'value'))->required()->description('Why the app may email them: consent (they asked for it), soft_opt_in (customers offered a way out when their details were collected), imported (from another system).'),
            'contacts' => $schema->array()->items($schema->object([
                'email' => $schema->string()->required(),
                'name' => $schema->string(),
                'first_name' => $schema->string(),
                'locale' => $schema->string()->description('Like en, pt_BR.'),
                'timezone' => $schema->string()->description('Like Europe/Istanbul.'),
                'country' => $schema->string()->description('Two letters, like TR.'),
                'custom_fields' => $schema->object()->description('Names (letters, digits, underscores) to text, whole numbers, true or false.'),
            ]))->min(1)->max(AddContactsAction::MAX_CONTACTS)->required(),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            ...Schemas::importResult($schema),
            'waiting_to_confirm' => $schema->integer()->description('Contacts added to a double opt-in list: none is mailed until they confirm, and only the app\'s own sign-up sends the confirmation email.'),
        ];
    }

    public function perform(Request $request, AddContactsAction $add): ResponseFactory
    {
        $input = $request->validate([
            'list' => ['required', 'string'],
            'basis' => ['required', 'string', 'in:'.implode(',', array_column(CreateListTool::BASES, 'value'))],
            'contacts' => ['required', 'array', 'list'],
            'contacts.*' => ['array'],
        ]);

        $list = CampaignList::forKey($input['list']);

        /** @var list<array<array-key, mixed>> $contacts */
        $contacts = $request->get('contacts');
        $result = $add->execute($list, $contacts, ConsentBasis::from($input['basis']), static::agentContext());

        return Response::structured([
            ...static::importAnswer($result),
            'waiting_to_confirm' => $list->double_opt_in ? $result->count(ImportChange::Joined) : 0,
        ]);
    }
}

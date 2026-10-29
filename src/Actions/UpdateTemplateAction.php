<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Support\TextValue;

/**
 * Changes a template's name, description or default mark. Its email changes
 * through AddRevisionAction, and its key never changes, since agents and
 * campaigns name it by its key.
 */
class UpdateTemplateAction
{
    public const FIELDS = ['name', 'description', 'is_default'];

    /**
     * @param  array<string, mixed>  $changes  name, description (null clears it) and is_default
     *
     * @throws CampaignsException
     */
    public function execute(Template $template, array $changes): Template
    {
        $unknown = array_diff(array_keys($changes), static::FIELDS);

        if ($unknown !== []) {
            throw CampaignsException::invalidValue('change', 'can only be to '.implode(', ', static::FIELDS).', not '.implode(', ', $unknown));
        }

        $values = [];

        if (array_key_exists('name', $changes)) {
            if (! is_string($changes['name'])) {
                throw CampaignsException::invalidValue('name', 'must be text');
            }

            $values['name'] = TextValue::required('name', $changes['name'], 255);
        }

        if (array_key_exists('description', $changes)) {
            $description = $changes['description'];

            if ($description !== null && ! is_string($description)) {
                throw CampaignsException::invalidValue('description', 'must be text or null');
            }

            $values['description'] = TextValue::optional('description', $description, 5000);
        }

        if (array_key_exists('is_default', $changes)) {
            if (! is_bool($changes['is_default'])) {
                throw CampaignsException::invalidValue('is_default', 'must be true or false');
            }

            $values['is_default'] = $changes['is_default'];
        }

        $template->fill($values)->save();

        return $template;
    }
}

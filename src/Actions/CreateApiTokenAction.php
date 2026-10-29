<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Support\TextValue;
use Mozex\Campaigns\Support\Token;

/**
 * Makes a token for the MCP server, named for the machine it will live on.
 * The raw token is returned once and never stored.
 */
class CreateApiTokenAction
{
    /**
     * @return array{0: ApiToken, 1: string} the token's row and the token itself
     *
     * @throws CampaignsException
     */
    public function execute(string $name, Rights $rights): array
    {
        $name = TextValue::required('name', $name, 125);

        if (ApiToken::query()->where('name', $name)->exists()) {
            throw CampaignsException::tokenNameTaken($name);
        }

        $token = Token::generate();

        try {
            $row = ApiToken::create([
                'name' => $name,
                'token_hash' => Token::hash($token),
                'rights' => $rights,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw CampaignsException::tokenNameTaken($name);
        }

        return [$row, $token];
    }
}

<?php

declare(strict_types=1);

namespace Mozex\Campaigns;

/**
 * A database error with its SQL left out (Support\DatabaseErrors). A
 * CampaignsException, so callers that answer one as a refusal still do, but
 * one that reports nothing for a refusal tells this apart and reports it.
 */
class DatabaseFailedException extends CampaignsException {}

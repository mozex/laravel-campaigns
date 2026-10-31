## mozex/laravel-campaigns

AI agents run this app's email marketing through the package's MCP server; the app feeds it lists, consent, audiences and data. Activate the `laravel-campaigns` skill for any work on lists, newsletters, marketing consent, imports, audiences, Mailgun or another sending driver, or the MCP setup.

- Change subscribers only through the package's actions (`Mozex\Campaigns\Actions`, such as `SubscribeAction`, `OptOutAction`, `BlockAction`, `ForgetSubscriberAction`): each writes the consent record that answers a complaint. Never write `opted_out_at`, `blocked_at` or subscription rows with Eloquent.
- A model registered as a list source (`campaigns.list_sources`, the `HasCampaignSubscriber` trait) syncs itself; nobody is added to or removed from a dynamic list by hand. Create a record from a form that asks about marketing email inside `Model::withCampaignConsent(ConsentContext::fromRequest(...), fn () => ...)`.
- Only the person clears an opt-out (`accepted: true`, or a confirmed double opt-in); one unsubscribe stops all of the app's marketing.
- Sending stays off until `CAMPAIGNS_ENABLED=true`; every email goes through the driver `CAMPAIGNS_DRIVER` names (`mailgun` by default), and tests set it to `fake`.
- Redact the package's link tokens (`/campaigns/u/`, `/campaigns/c/`, `/campaigns/v/`), signed links' `signature`, the body of `POST /campaigns/webhooks/<driver>` (Mailgun's `event-data`) and MCP tool arguments from error reports.

<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Traits\Localizable;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\Support\PageLocale;

/**
 * The pages an email's links open. They run outside the app's web
 * middleware (no session, no CSRF token, nothing to sign in to), since mail
 * apps post to them with no cookies, and render in the subscriber's language
 * on the package's own layout.
 */
abstract class PageController
{
    use Localizable;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<?string>  $locales  The first the package's strings exist in wins, else the app's (PageLocale::pick()).
     */
    protected function render(string $view, array $data, array $locales = [], int $status = 200): Response
    {
        $locale = PageLocale::pick(...$locales);

        return $this->withLocale($locale, fn (): Response => response()->view($view, [
            ...$data,
            'app' => app(Campaigns::class)->name(),
            'lang' => PageLocale::htmlLang($locale),
            'dir' => PageLocale::direction($locale),
        ], $status));
    }

    protected function invalid(): Response
    {
        return $this->render('campaigns::invalid', [], status: 404);
    }
}

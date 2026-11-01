<?php

declare(strict_types=1);

use Mozex\Campaigns\Content\Tracking;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\Token;

it('never puts the pixel inside a mark it takes out', function (): void {
    // The renderer's body search doesn't end a comment at <!--> as a
    // browser does, so it finds the body's start inside the attribute.
    $token = Token::generate();
    $html = '<!--><a data-campaigns-no-track="-->" href="https://shop.test/">A</a>';

    $tracked = Tracking::apply($html, [], $token, opens: true, clicks: false);

    // Where the mark ended, the rest of the email left whole.
    expect($tracked)->toBe('<!--><a <img src="'.Links::open($token).'" width="1" height="1" alt="" style="display:block;border:0"> href="https://shop.test/">A</a>');
});

it('wraps and marks in one pass, every change where it was written', function (): void {
    $token = Token::generate();
    $links = str_repeat('<a href="https://shop.test/a">é</a><a href="https://shop.test/b" data-campaigns-no-track>b</a>', 500);

    $tracked = Tracking::apply('<body>'.$links.'</body>', ['https://shop.test/a' => str_repeat('k', 22)], $token, opens: true, clicks: true);

    expect(substr_count($tracked, 'href="'.Links::click(str_repeat('k', 22), $token).'">é</a>'))->toBe(500)
        ->and(substr_count($tracked, '<a href="https://shop.test/b" >b</a>'))->toBe(500)
        ->and($tracked)->toStartWith('<body><img src="'.Links::open($token).'"')
        ->and(Tracking::trackedLength('<body>'.$links.'</body>', '<body>'.$links.'</body>'))->toBe(strlen($tracked));
});

it('tracks no address too long for a redirect\'s header', function (): void {
    $longest = 'https://shop.test/?q='.str_repeat('x', Tracking::MAX_URL_LENGTH - 21);
    $over = $longest.'x';

    expect(strlen($longest))->toBe(2048)
        ->and(Tracking::urls('<a href="'.$longest.'">A</a><a href="'.$over.'">B</a>'))->toBe([$longest]);
});

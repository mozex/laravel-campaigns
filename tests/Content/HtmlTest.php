<?php

declare(strict_types=1);

use Mozex\Campaigns\Content\Html;

describe('reading the images an email loads', function (): void {
    it('reads every form an image is written in, Outlook\'s blocks included, and nothing else', function (): void {
        $html = <<<'HTML'
            <html><head><title><img src="http://old.test/title.png"></title>
            <style>@import "https://cdn.test/fonts.css"; .s { background-image: image-set("https://cdn.test/set.png" 1x, url(https://cdn.test/set-url.png) 2x, "https://cdn.test/set.avif" type("image/avif")); }
            .t { behavior: url(#default#VML); } /* url(http://old.test/comment.png) */ .u::after { content: "url(http://old.test/text.png)"; }</style></head>
            <body>
            <!--[if mso]><v:rect fill="true"><v:fill type="frame" src="https://cdn.test/fill.png" /></v:rect><v:image src="https://cdn.test/vml.png" /><v:imagedata src="https://cdn.test/data.png" />
            <table><tr><td background="https://cdn.test/td.png"><img src="https://cdn.test/outlook.png" alt=""></td></tr></table><style>.o { background: url(https://cdn.test/outlook-css.png); }</style><![endif]-->
            <!--[if !mso]><!--><img src="https://cdn.test/others.png" alt=""><!--<![endif]-->
            <!-- <img src="http://old.test/commented.png"> -->
            <picture><source srcset="https://cdn.test/hero@2x.png 2x, https://cdn.test/hero@3x.png 3x"><img src="https://cdn.test/hero.png" alt=""></picture>
            <input type="IMAGE" src="https://cdn.test/button.png" alt="Go"><input type="text" src="http://old.test/text-input.png">
            <video poster="https://cdn.test/poster.png"><source src="http://old.test/clip.mp4"></video>
            <img lowsrc="https://cdn.test/low.png" src="https://cdn.test/a.png" alt="">
            <svg><image href="https://cdn.test/svg.png" /><image xlink:href="https://cdn.test/svg-xlink.png" /></svg>
            <div style="background-image: -webkit-image-set(&quot;https://cdn.test/entity.png&quot; 1x)">x</div>
            <a href="http://old.test/page">A link</a>
            <script>var image = '<img src="http://old.test/script.png">';</script>
            <textarea><img src="http://old.test/textarea.png"></textarea>
            </body></html>
            HTML;

        expect(Html::imageUrls($html))->toEqualCanonicalizing([
            'https://cdn.test/fonts.css',
            'https://cdn.test/set.png',
            'https://cdn.test/set-url.png',
            'https://cdn.test/set.avif',
            'https://cdn.test/fill.png',
            'https://cdn.test/vml.png',
            'https://cdn.test/data.png',
            'https://cdn.test/td.png',
            'https://cdn.test/outlook.png',
            'https://cdn.test/outlook-css.png',
            'https://cdn.test/others.png',
            'https://cdn.test/hero@2x.png',
            'https://cdn.test/hero@3x.png',
            'https://cdn.test/hero.png',
            'https://cdn.test/button.png',
            'https://cdn.test/poster.png',
            'https://cdn.test/low.png',
            'https://cdn.test/a.png',
            'https://cdn.test/svg.png',
            'https://cdn.test/svg-xlink.png',
            'https://cdn.test/entity.png',
        ]);
    });

    it('ends comments, decodes references and skips CSS escapes as a browser does', function (): void {
        $html = implode('', [
            '<!--><img src="https://cdn.test/a.png" alt=""><!---><img src="https://cdn.test/b.png" alt=""><!-- x --!><img src="https://cdn.test/c.png" alt="">',
            '<div style=\'--a:"x&quot ;background-image:url(https://cdn.test/d.png);--b:"\'>x</div>',
            '<img src="https://cdn.test/e.png" srcset="https://cdn.test/f.png 1x&#44 https://cdn.test/g.png 2x" alt="">',
            '<input type="&#105mage" src="https://cdn.test/h.png" alt="">',
            '<div style=\'--a:\" ;background-image:url(https://cdn.test/i.png);--b:\"\'>x</div>',
            '<div style="--a:\/*;background-image:url(https://cdn.test/j.png);--b:/**/">x</div>',
            // A carriage return, which PHP's decoder leaves as written, ends a CSS string.
            '<div style=\'--a:"x&#13;;background-image:url(https://cdn.test/k.png);--b:"\'>x</div>',
            // A comment opened inside a condition can't close before it does, so the rest is read.
            '<!--[if mso]><!-- note <v:image src="https://cdn.test/l.png" /><![endif]-->',
            '<!--[if mso--]><img src="https://cdn.test/m.png" alt=""><![endif]-->',
            '<!-- y -->',
        ]);

        expect(Html::imageUrls($html))->toEqualCanonicalizing(array_map(fn (string $name): string => "https://cdn.test/{$name}.png", str_split('abcdefghijklm')));
    });

    it('reads conditional comments opened inside one another in one pass', function (): void {
        $html = str_repeat('<!--[if mso]>', 70000).'<img src="https://cdn.test/a.png" alt=""><![endif]-->';

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = microtime(true);

        // Read once each, it takes a tenth of a second and a megabyte; read
        // again per opener, gigabytes, or minutes.
        expect(Html::imageUrls($html))->toBe(['https://cdn.test/a.png'])
            ->and(microtime(true) - $started)->toBeLessThan(2.0)
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(20 * 1024 * 1024);
    });

    it('reads to the end what is never closed, and a megabyte of unclosed openers in one pass', function (): void {
        $openers = '<div style="'.str_repeat('url(', 250000).'">x</div><style>.a { background: url(http://cdn.test/after.png) }</style>';

        expect(Html::imageUrls($openers))->toContain('http://cdn.test/after.png')
            ->and(Html::imageUrls('<img src="https://cdn.test/a.png"><style>.a { background: url(http://cdn.test/open.png'))
            ->toBe(['https://cdn.test/a.png', 'http://cdn.test/open.png']);
    });
});

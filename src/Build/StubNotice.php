<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use ThreadSafe\Web\Html;
use ThreadSafe\Web\Logo;

/**
 * The page that the stub of `install.php` and of `restore.php` shows when it cannot go on: PHP too old, the key file missing, a directory it cannot make.
 * The stub is written for PHP 5.2 and runs before any code of the program is loaded, so this is source text for it: the same palette and logo as the
 * pages of the wizards ({@see Html}, {@see Logo}), in one block that reads `$threadsafeNotice` - `array(status line, title, HTML)` - and ends the run.
 * On the command line only the text of the notice is printed.
 */
final class StubNotice
{
    /**
     * The script of the page: a button "Copy to clipboard" after every code block. It uses the clipboard API where the page is a secure context (https,
     * localhost) and selects the text and copies it as the fallback; without a script the block can still be selected by hand. The page allows this one
     * script by its nonce.
     */
    private const string COPY = "(function(){var blocks=document.getElementsByTagName('pre');for(var i=0;i<blocks.length;i++){(function(block){"
        . "var button=document.createElement('button');button.type='button';button.className='secondary';button.textContent='Copy to clipboard';"
        . "var report=function(ok){button.textContent=ok?'Copied':'Selected: press Ctrl+C';setTimeout(function(){button.textContent='Copy to clipboard';},2500);};"
        . "var select=function(){var range=document.createRange();range.selectNodeContents(block);var selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);var ok=false;try{ok=document.execCommand('copy');}catch(e){}report(ok);};"
        . "button.onclick=function(){if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(block.textContent).then(function(){report(true);},select);}else{select();}};"
        . "block.parentNode.insertBefore(button,block.nextSibling);})(blocks[i]);}})();";

    /**
     * The block of PHP source for the stub.
     *
     * @param string $name what the page belongs to, as in the heading of the wizard ("installer", "restore")
     */
    public static function source(string $name): string
    {
        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>{TITLE} - ThreadSafe ' . $name . '</title><link rel="icon" type="image/svg+xml" href="' . Logo::faviconUri() . '"><style>' . Html::css() . '</style></head>'
            . '<body><main><h1>' . Logo::svg() . '<span>ThreadSafe ' . $name . '</span></h1><h2>{TITLE}</h2>{BODY}</main><script nonce="{NONCE}">' . self::COPY . '</script></body></html>';
        // the page is cut at its variable places; the stub puts it together from single-quoted strings
        $pieces = array_map(static fn (string $piece): string => "'" . addcslashes($piece, "'\\") . "'", preg_split('/\{TITLE\}|\{BODY\}|\{NONCE\}/', $page) ?: []);

        return <<<PHP
if (count(\$threadsafeNotice) > 0) {
    if (!isset(\$_SERVER['REQUEST_METHOD'])) {
        echo html_entity_decode(strip_tags(str_replace(array('</p>', '</pre>'), "\\n\\n", \$threadsafeNotice[2]))) . "\\n";
        exit(1);
    }
    \$threadsafeNonce = md5(uniqid(mt_rand(), true) . microtime());
    if (!headers_sent()) {
        header('HTTP/1.1 ' . \$threadsafeNotice[0]);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'nonce-" . \$threadsafeNonce . "'");
    }
    \$threadsafeTitle = htmlspecialchars(\$threadsafeNotice[1]);
    echo {$pieces[0]} . \$threadsafeTitle . {$pieces[1]} . \$threadsafeTitle . {$pieces[2]} . \$threadsafeNotice[2] . {$pieces[3]} . \$threadsafeNonce . {$pieces[4]};
    exit(1);
}
PHP;
    }

    private function __construct()
    {
    }
}

<?php

namespace Callcocam\Rastro\Http;

use Callcocam\Rastro\Widget;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The compiled chat, from inside the package. Cached for a year when asked
 * by its current version (?v=, as <x-rastro::widget /> does), for five
 * minutes otherwise.
 */
class WidgetScriptController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $current = $request->query('v') === Widget::version();

        return response()->file(Widget::path(), [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
        ]);
    }
}

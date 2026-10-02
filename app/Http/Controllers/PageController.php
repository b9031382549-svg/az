<?php

namespace App\Http\Controllers;

use App\Services\Api\ModelVersion;
use App\Services\Import\InvoiceLinesImporter;
use App\Support\ApiAbilities;
use Illuminate\View\View;

class PageController extends Controller
{
    public function settings(): View
    {
        return view('pages.settings');
    }

    /**
     * The API reference for the client's developers. Every number and list on it — the
     * address, limits, optional fields, abilities, similarity values, the version — is read
     * from the running code and config, so the page cannot drift from the API it describes.
     */
    public function apiDocs(ModelVersion $version): View
    {
        return view('pages.api-docs', [
            'base' => url('/api'),
            'limits' => (array) config('api.classify'),
            'fields' => array_values(array_diff(InvoiceLinesImporter::columns(), ['item_name'])),
            'abilities' => ApiAbilities::labels(),
            'similarity' => collect((array) config('api.similarity'))->sortDesc()->all(),
            'version' => $version->toArray(),
        ]);
    }
}

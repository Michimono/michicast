<?php

namespace App\Http\Controllers;

use App\Support\DestinationProviderRegistry;
use App\Support\DestinationTypePresentation;
use App\Support\RegistrationSettings;
use App\Support\SourceProviderRegistry;
use App\Support\SourceTypePresentation;
use Illuminate\Contracts\View\View;

/**
 * The public landing page. The sources and destinations it names are read
 * from the provider registries, so the page never promises a provider this
 * instance does not ship.
 */
class HomeController extends Controller
{
    public function __invoke(
        SourceProviderRegistry $sources,
        DestinationProviderRegistry $destinations,
        RegistrationSettings $registration,
    ): View {
        $repositoryUrl = trim((string) config('michicast.repository_url'));

        return view('home', [
            'sources' => $this->sourceNames($sources),
            'destinations' => $this->destinationNames($destinations),
            'registrationOpen' => $registration->acceptsRegistrations(),
            'repositoryUrl' => $repositoryUrl === '' ? null : $repositoryUrl,
        ]);
    }

    /**
     * The short names of the source types a user can pick.
     *
     * @return list<string>
     */
    private function sourceNames(SourceProviderRegistry $sources): array
    {
        $names = [];

        foreach ($sources->options() as $type => $label) {
            $names[] = SourceTypePresentation::for($type, $label)['name'];
        }

        return $names;
    }

    /**
     * The short names of the live destination types. Testing-only types are
     * left out even where they are offered: they deliver nowhere.
     *
     * @return list<string>
     */
    private function destinationNames(DestinationProviderRegistry $destinations): array
    {
        $testing = (array) config('michicast.test_destinations', []);
        $names = [];

        foreach ($destinations->options() as $type => $label) {
            if (in_array($type, $testing, true)) {
                continue;
            }

            $names[] = DestinationTypePresentation::for($type, $label)['name'];
        }

        return $names;
    }
}

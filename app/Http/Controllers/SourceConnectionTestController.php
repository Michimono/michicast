<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSourceConnectionTestRequest;
use App\Models\Organization;
use App\Models\Source;
use App\Support\ConnectionTestOutcome;
use App\Support\ConnectionTestResult;
use App\Support\SecretRedactor;
use App\Support\SourceDefinition;
use App\Support\SourceProviderRegistry;
use Illuminate\View\View;
use Throwable;

class SourceConnectionTestController extends Controller
{
    public function __construct(
        private SourceProviderRegistry $providers,
        private SecretRedactor $secrets,
    ) {}

    public function store(
        StoreSourceConnectionTestRequest $request,
        Organization $organization,
        Source $source,
        SourceController $form,
    ): View {
        $config = array_merge($source->config ?? [], $request->configuration());
        $result = $this->runTest($source, $config);
        $outcome = $this->persistResult($source, $result);

        return $form->form(
            $source,
            name: $request->name(),
            config: $config,
            supportsConnectionTest: $this->providers->capabilities($source->type)->supportsConnectionTest,
            testOutcome: $outcome,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function runTest(Source $source, array $config): ConnectionTestResult
    {
        if (! $this->providers->has($source->type)) {
            return ConnectionTestResult::unsupported();
        }

        $provider = $this->providers->get($source->type);

        if (! $provider->capabilities()->supportsConnectionTest) {
            return ConnectionTestResult::unsupported();
        }

        try {
            return $provider->testConnection(new SourceDefinition(
                type: $source->type,
                config: $config,
                projectId: (int) $source->project_id,
                sourceId: (int) $source->getKey(),
            ));
        } catch (Throwable $e) {
            report($e);

            return ConnectionTestResult::failure(
                'The connection test failed unexpectedly. Check the configuration and try again.',
            );
        }
    }

    private function persistResult(Source $source, ConnectionTestResult $result): ConnectionTestOutcome
    {
        $message = mb_substr((string) $this->secrets->redact($result->message), 0, 255);

        $source->forceFill([
            'last_tested_at' => now(),
            'last_test_successful' => $result->successful,
            'last_test_message' => $message,
        ])->save();

        return new ConnectionTestOutcome($result->successful, $message);
    }
}

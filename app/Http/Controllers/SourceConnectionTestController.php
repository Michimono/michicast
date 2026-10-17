<?php

namespace App\Http\Controllers;

use App\Enums\QuotaMetric;
use App\Http\Requests\StoreSourceConnectionTestRequest;
use App\Models\Organization;
use App\Models\Source;
use App\Support\ConnectionTestOutcome;
use App\Support\ConnectionTestResult;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\QuotaMeter;
use App\Support\SecretRedactor;
use App\Support\SourceDefinition;
use App\Support\SourceProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Throwable;

class SourceConnectionTestController extends Controller
{
    public function __construct(
        private SourceProviderRegistry $providers,
        private SecretRedactor $secrets,
        private QuotaMeter $meter,
    ) {}

    public function store(
        StoreSourceConnectionTestRequest $request,
        Organization $organization,
        Source $source,
        SourceController $form,
        PipelineEditorPayload $payload,
    ): View|JsonResponse {
        $config = array_merge($source->config ?? [], $request->configuration());
        $result = $this->runTest($organization, $source, $config);
        $outcome = $this->persistResult($source, $result);

        // The pipeline inspector tests in place and only needs the outcome.
        if ($request->expectsJson()) {
            return response()->json([
                'successful' => $outcome->successful,
                'message' => $outcome->message,
                'source' => $payload->source($source),
            ]);
        }

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
    private function runTest(Organization $organization, Source $source, array $config): ConnectionTestResult
    {
        if (! $this->providers->has($source->type)) {
            return ConnectionTestResult::unsupported();
        }

        $provider = $this->providers->get($source->type);

        if (! $provider->capabilities()->supportsConnectionTest) {
            return ConnectionTestResult::unsupported();
        }

        // Meter before the provider call; on denial skip the test (#160).
        $decision = $this->meter->consume($organization, QuotaMetric::SourceFetches);

        if (! $decision->allowed) {
            return ConnectionTestResult::failure($decision->message(QuotaMetric::SourceFetches));
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

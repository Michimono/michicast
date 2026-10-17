<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDestinationConnectionTestRequest;
use App\Models\Destination;
use App\Models\Organization;
use App\Support\ConnectionTestOutcome;
use App\Support\ConnectionTestResult;
use App\Support\DestinationDefinition;
use App\Support\DestinationProviderRegistry;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Throwable;

class DestinationConnectionTestController extends Controller
{
    public function __construct(
        private DestinationProviderRegistry $providers,
        private SecretRedactor $secrets,
    ) {}

    public function store(
        StoreDestinationConnectionTestRequest $request,
        Organization $organization,
        Destination $destination,
        DestinationController $form,
        PipelineEditorPayload $payload,
    ): View|JsonResponse {
        $config = array_merge($destination->config ?? [], $request->configuration());
        $credentials = $request->credentials();
        $result = $this->runTest($destination, $config, $credentials);
        $outcome = $this->persistResult($destination, $result);

        // The pipeline inspector tests in place; the outcome never echoes credentials.
        if ($request->expectsJson()) {
            return response()->json([
                'successful' => $outcome->successful,
                'message' => $outcome->message,
                'destination' => $payload->destination($destination),
            ]);
        }

        return $form->form(
            $destination,
            name: $request->name(),
            config: $config,
            supportsConnectionTest: $this->providers->capabilities($destination->type)->supportsConnectionTest,
            testOutcome: $outcome,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    private function runTest(Destination $destination, array $config, array $credentials): ConnectionTestResult
    {
        if (! $this->providers->has($destination->type)) {
            return ConnectionTestResult::unsupported();
        }

        $provider = $this->providers->get($destination->type);

        if (! $provider->capabilities()->supportsConnectionTest) {
            return ConnectionTestResult::unsupported();
        }

        try {
            return $provider->testConnection(new DestinationDefinition(
                type: $destination->type,
                config: $config,
                credentials: $credentials,
                projectId: (int) $destination->project_id,
                destinationId: (int) $destination->getKey(),
            ));
        } catch (Throwable $e) {
            report($e);

            return ConnectionTestResult::failure(
                'The connection test failed unexpectedly. Check the configuration and try again.',
            );
        }
    }

    private function persistResult(Destination $destination, ConnectionTestResult $result): ConnectionTestOutcome
    {
        $message = mb_substr((string) $this->secrets->redact($result->message), 0, 255);

        $destination->forceFill([
            'last_tested_at' => now(),
            'last_test_successful' => $result->successful,
            'last_test_message' => $message,
        ])->save();

        return new ConnectionTestOutcome($result->successful, $message);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\Organization;
use App\Support\ExecutingProjectRunner;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class ContentPreviewController extends Controller
{
    public function store(
        Organization $organization,
        Broadcast $broadcast,
        ExecutingProjectRunner $runner,
        BroadcastController $form,
    ): View {
        Gate::authorize('preview', $broadcast);

        $broadcast->loadMissing(['source', 'destination']);

        $preview = null;
        $previewError = null;

        try {
            $preview = $runner->preview($broadcast);
        } catch (Throwable $e) {
            report($e);
            $previewError = $e->getMessage();
        }

        return $form->form($broadcast, $preview, $previewError);
    }
}

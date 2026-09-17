<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBroadcastTransformRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Support\BroadcastTransforms\BroadcastTransformer;
use Illuminate\View\View;

class BroadcastTransformPreviewController extends Controller
{
    public function store(
        UpdateBroadcastTransformRequest $request,
        Organization $organization,
        Broadcast $broadcast,
        BroadcastTransformer $transformer,
        BroadcastTransformController $form,
    ): View {
        $config = $request->transform();
        $preview = $transformer->preview($config);

        return $form->form($broadcast, $config, $preview->body);
    }
}

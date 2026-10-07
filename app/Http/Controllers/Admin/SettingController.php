<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Enums\RegistrationMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRegistrationSettingsRequest;
use App\Support\RecordAdminAction;
use App\Support\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function edit(RegistrationSettings $registration): View
    {
        return view('admin.settings', [
            'mode' => $registration->mode(),
            'effectiveMode' => $registration->effectiveMode(),
            'dailyCap' => $registration->dailyCap(),
            'capReached' => $registration->dailyCapReached(),
            'registrationsToday' => $registration->registrationsToday(),
            'isStored' => $registration->isStored(),
            'configuredMode' => $registration->configuredMode(),
            'configuredDailyCap' => $registration->configuredDailyCap(),
            'modeOptions' => RegistrationMode::options(),
            'modes' => RegistrationMode::cases(),
        ]);
    }

    public function update(
        UpdateRegistrationSettingsRequest $request,
        RegistrationSettings $registration,
        RecordAdminAction $record,
    ): RedirectResponse {
        $mode = RegistrationMode::from($request->string('mode')->toString());
        $dailyCap = $request->filled('daily_cap') ? $request->integer('daily_cap') : null;
        $reason = $request->string('reason')->toString();

        $beforeMode = $registration->mode();
        $beforeCap = $registration->dailyCap();

        if ($beforeMode === $mode && $beforeCap === $dailyCap) {
            return back()->with('error', 'Registration settings are unchanged.');
        }

        DB::transaction(function () use ($registration, $record, $mode, $dailyCap, $reason, $beforeMode, $beforeCap): void {
            $setting = $registration->update($mode, $dailyCap);

            $record(
                AdminAction::RegistrationSettingsChanged,
                $setting,
                $reason,
                [
                    'mode' => ['before' => $beforeMode->value, 'after' => $mode->value],
                    'daily_cap' => ['before' => $beforeCap, 'after' => $dailyCap],
                ],
            );
        });

        return redirect()
            ->route('admin.settings.edit')
            ->with('success', 'Registration is now '.strtolower($mode->label()).'.');
    }
}

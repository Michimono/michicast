<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $adminId = $request->integer('admin_id') ?: null;
        $action = AdminAction::tryFrom($request->string('action')->toString());
        $subjectType = $request->string('subject_type')->toString();
        $subjectId = $request->integer('subject_id') ?: null;

        $actions = AdminActionLog::query()
            ->with(['admin', 'subject'])
            ->when(
                $adminId !== null,
                fn (Builder $query) => $query->where('admin_id', $adminId),
            )
            ->when(
                $action !== null,
                fn (Builder $query) => $query->where('action', $action),
            )
            ->when(
                $subjectType !== '',
                fn (Builder $query) => $query->where('subject_type', $subjectType),
            )
            ->when(
                $subjectId !== null,
                fn (Builder $query) => $query->where('subject_id', $subjectId),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $admins = User::query()
            ->whereIn('id', AdminActionLog::query()->select('admin_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $subjectTypes = AdminActionLog::query()
            ->select('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');

        return view('admin.audit', [
            'actions' => $actions,
            'admins' => $admins,
            'actionOptions' => AdminAction::options(),
            'subjectTypes' => $subjectTypes,
            'filters' => [
                'admin_id' => $adminId,
                'action' => $action?->value,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ],
        ]);
    }
}

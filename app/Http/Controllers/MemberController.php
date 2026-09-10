<?php

namespace App\Http\Controllers;

use App\Support\CurrentOrganization;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function index(): View
    {
        $organization = $this->current->organization();

        return view('members.index', [
            'organization' => $organization,
            'members' => $organization->members()->with('user')->oldest('id')->get(),
        ]);
    }
}

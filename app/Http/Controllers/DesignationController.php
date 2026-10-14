<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\StoreDesignationRequest;
use App\Http\Requests\Organization\UpdateDesignationRequest;
use App\Models\Designation;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DesignationController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Designation::class);
        $query = $this->activeCompany->current()->designations()->orderBy('name');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($builder) => $builder->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }

        return Inertia::render('organizations/index', ['type' => 'designations', 'title' => 'Designations', 'records' => $query->paginate(15)->withQueryString(), 'filters' => $request->only('search'), 'canCreate' => $request->user()->can('designation.create')]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', Designation::class);

        return Inertia::render('organizations/form', ['type' => 'designations', 'title' => 'Designation', 'record' => null]);
    }

    public function store(StoreDesignationRequest $request): RedirectResponse
    {
        $this->activeCompany->current()->designations()->create($request->validated());

        return to_route('designations.index');
    }

    public function edit(int $designation): InertiaResponse
    {
        $record = $this->activeCompany->current()->designations()->findOrFail($designation);
        Gate::authorize('update', $record);

        return Inertia::render('organizations/form', ['type' => 'designations', 'title' => 'Designation', 'record' => $record]);
    }

    public function update(UpdateDesignationRequest $request, int $designation): RedirectResponse
    {
        $record = $this->activeCompany->current()->designations()->findOrFail($designation);
        Gate::authorize('update', $record);
        $record->update($request->validated());

        return to_route('designations.index');
    }
}

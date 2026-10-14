<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\StoreBranchRequest;
use App\Http\Requests\Organization\UpdateBranchRequest;
use App\Models\Branch;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BranchController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Branch::class);
        $query = $this->activeCompany->current()->branches()->orderBy('name');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($builder) => $builder->where('name', 'like', $term)->orWhere('code', 'like', $term)->orWhere('city', 'like', $term));
        }

        return Inertia::render('organizations/index', ['type' => 'branches', 'title' => 'Branches', 'records' => $query->paginate(15)->withQueryString(), 'filters' => $request->only('search'), 'canCreate' => $request->user()->can('branch.create')]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', Branch::class);

        return Inertia::render('organizations/form', ['type' => 'branches', 'title' => 'Branch', 'record' => null]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $this->activeCompany->current()->branches()->create($request->validated());

        return to_route('branches.index');
    }

    public function edit(int $branch): InertiaResponse
    {
        $record = $this->activeCompany->current()->branches()->findOrFail($branch);
        Gate::authorize('update', $record);

        return Inertia::render('organizations/form', ['type' => 'branches', 'title' => 'Branch', 'record' => $record]);
    }

    public function update(UpdateBranchRequest $request, int $branch): RedirectResponse
    {
        $record = $this->activeCompany->current()->branches()->findOrFail($branch);
        Gate::authorize('update', $record);
        $record->update($request->validated());

        return to_route('branches.index');
    }
}

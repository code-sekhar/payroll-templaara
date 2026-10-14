<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\StoreDepartmentRequest;
use App\Http\Requests\Organization\UpdateDepartmentRequest;
use App\Models\Department;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DepartmentController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Department::class);
        $query = $this->activeCompany->current()->departments()->orderBy('name');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($builder) => $builder->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }

        return Inertia::render('organizations/index', ['type' => 'departments', 'title' => 'Departments', 'records' => $query->paginate(15)->withQueryString(), 'filters' => $request->only('search'), 'canCreate' => $request->user()->can('department.create')]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', Department::class);

        return Inertia::render('organizations/form', ['type' => 'departments', 'title' => 'Department', 'record' => null]);
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $this->activeCompany->current()->departments()->create($request->validated());

        return to_route('departments.index');
    }

    public function edit(int $department): InertiaResponse
    {
        $record = $this->activeCompany->current()->departments()->findOrFail($department);
        Gate::authorize('update', $record);

        return Inertia::render('organizations/form', ['type' => 'departments', 'title' => 'Department', 'record' => $record]);
    }

    public function update(UpdateDepartmentRequest $request, int $department): RedirectResponse
    {
        $record = $this->activeCompany->current()->departments()->findOrFail($department);
        Gate::authorize('update', $record);
        $record->update($request->validated());

        return to_route('departments.index');
    }
}

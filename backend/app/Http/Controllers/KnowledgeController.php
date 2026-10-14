<?php

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeRequest;
use App\Http\Requests\SupportListRequest;
use App\Models\CannedResponse;
use App\Models\IntegrationClient;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Setting;
use App\Models\SlaPolicy;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KnowledgeController extends Controller
{
    public function categories(SupportListRequest $request)
    {
        $this->requirePermission('view knowledge base'); $query = KbCategory::withCount('articles')->orderBy('sort_order')->orderBy('name');
        if ($request->filled('search')) { $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($request->search).'%']); }
        if ($request->export === 'csv') { return ListResponse::csv($query, 'knowledge-categories.csv', ['Name', 'Sort order', 'Articles'], fn ($row) => [$row->name, $row->sort_order, $row->articles_count]); }

        return ListResponse::paginate($query, $request, ['total' => (clone $query)->count()]);
    }
    public function storeCategory(KnowledgeRequest $request)
    {
        $this->requirePermission('create knowledge base'); $category = KbCategory::create($request->validated()); request()->attributes->set('audit.entity_type', 'KbCategory'); request()->attributes->set('audit.entity_id', $category->id);

        return response()->json($category, 201);
    }
    public function updateCategory(KnowledgeRequest $request, KbCategory $category) { $this->requirePermission('edit knowledge base'); $category->update($request->validated()); return response()->json($category->fresh()); }
    public function destroyCategory(KbCategory $category) { $this->requirePermission('delete knowledge base'); $category->delete(); return response()->json(['message' => 'Knowledge category deleted successfully.']); }

    public function articles(SupportListRequest $request)
    {
        $integration = $request->user() instanceof IntegrationClient;
        if (! $integration) { $this->requirePermission('view knowledge base'); }
        $query = KbArticle::with('category');
        if ($integration) { $query->where('status', 'published'); }
        if ($request->filled('status') && ! $integration) {
            if (! in_array($request->status, ['draft', 'published'], true)) { throw ValidationException::withMessages(['status' => ['Choose draft or published.']]); }
            $query->where('status', $request->status);
        }
        if ($request->filled('category_id')) { $query->where('category_id', $request->category_id); }
        if ($request->filled('search')) { $search = '%'.mb_strtolower($request->search).'%'; $query->where(fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [$search])->orWhereRaw('LOWER(body) LIKE ?', [$search])); }
        $query = ListResponse::sort($query, $request, ['created_at', 'title']);
        $summary = ['total' => (clone $query)->count(), 'published' => (clone $query)->where('status', 'published')->count(), 'draft' => (clone $query)->where('status', 'draft')->count()];
        if ($request->export === 'csv') { return ListResponse::csv($query, 'knowledge-articles.csv', ['Title', 'Slug', 'Category', 'Status', 'Body'], fn ($row) => [$row->title, $row->slug, $row->category?->name, $row->status, $row->body]); }

        return ListResponse::paginate($query, $request, $summary);
    }
    public function storeArticle(KnowledgeRequest $request)
    {
        $this->requirePermission('create knowledge base'); $values = $request->validated(); if (($values['status'] ?? 'draft') === 'published') { $values['published_at'] = now(); }
        $article = KbArticle::create($values); request()->attributes->set('audit.entity_type', 'KbArticle'); request()->attributes->set('audit.entity_id', $article->id);

        return response()->json($article->load('category'), 201);
    }
    public function showArticle(KbArticle $article) { $this->requirePermission('view knowledge base'); return response()->json($article->load('category')); }
    public function updateArticle(KnowledgeRequest $request, KbArticle $article)
    {
        $this->requirePermission('edit knowledge base'); $values = $request->validated(); if (($values['status'] ?? $article->status) === 'published' && ! $article->published_at) { $values['published_at'] = now(); } if (($values['status'] ?? null) === 'draft') { $values['published_at'] = null; } $article->update($values);

        return response()->json($article->fresh('category'));
    }
    public function destroyArticle(KbArticle $article) { $this->requirePermission('delete knowledge base'); $article->delete(); return response()->json(['message' => 'Knowledge article deleted successfully.']); }
    public function publicArticle(string $slug) { return response()->json(KbArticle::with('category')->where('slug', $slug)->where('status', 'published')->firstOrFail()); }

    public function canned(SupportListRequest $request)
    {
        $this->requirePermission('view canned responses'); $query = CannedResponse::with('user:id,name');
        if (! $request->user()->hasRole('Admin')) { $query->where(fn ($q) => $q->where('is_shared', true)->orWhere('user_id', $request->user()->id)); }
        if ($request->has('is_shared')) { $query->where('is_shared', $request->boolean('is_shared')); }
        if ($request->filled('search')) { $search = '%'.mb_strtolower($request->search).'%'; $query->where(fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [$search])->orWhereRaw('LOWER(body) LIKE ?', [$search])); }
        $query = ListResponse::sort($query, $request, ['created_at', 'title']); $summary = ['total' => (clone $query)->count(), 'shared' => (clone $query)->where('is_shared', true)->count(), 'personal' => (clone $query)->where('is_shared', false)->count()];
        if ($request->export === 'csv') { return ListResponse::csv($query, 'canned-responses.csv', ['Title', 'Body', 'Visibility'], fn ($row) => [$row->title, $row->body, $row->is_shared ? 'shared' : 'personal']); }

        return ListResponse::paginate($query, $request, $summary);
    }
    public function storeCanned(KnowledgeRequest $request)
    {
        $this->requirePermission('create canned responses'); $row = CannedResponse::create($request->validated() + ['user_id' => $request->user()->id]); request()->attributes->set('audit.entity_type', 'CannedResponse'); request()->attributes->set('audit.entity_id', $row->id);

        return response()->json($row, 201);
    }
    private function ownCanned(CannedResponse $response): void { abort_unless(request()->user()->hasRole('Admin') || $response->user_id === request()->user()->id, 403, 'Only the author or an Admin can change this canned response.'); }
    public function updateCanned(KnowledgeRequest $request, CannedResponse $response) { $this->requirePermission('edit canned responses'); $this->ownCanned($response); $response->update($request->validated()); return response()->json($response->fresh()); }
    public function destroyCanned(CannedResponse $response) { $this->requirePermission('delete canned responses'); $this->ownCanned($response); $response->delete(); return response()->json(['message' => 'Canned response deleted successfully.']); }

    public function policies(Request $request)
    {
        $this->requirePermission('view sla policies'); $rows = SlaPolicy::orderBy('id')->get();
        if ($request->query('export') === 'csv') { return ListResponse::csv(SlaPolicy::orderBy('id'), 'sla-policies.csv', ['Priority', 'First response hours', 'Resolution hours'], fn ($row) => [$row->priority, $row->first_response_hours, $row->resolution_hours]); }

        return response()->json(['data' => $rows, 'summary' => ['total' => $rows->count()]]);
    }
    public function updatePolicies(KnowledgeRequest $request)
    {
        $this->requirePermission('edit sla policies'); $policies = $request->validated()['policies']; request()->attributes->set('audit.entity_type', 'SlaPolicy'); request()->attributes->set('audit.old_values', ['policies' => SlaPolicy::all()->toArray()]);
        DB::transaction(function () use ($policies) { foreach ($policies as $priority => $hours) { SlaPolicy::updateOrCreate(['priority' => $priority], $hours); } Setting::updateOrCreate(['key' => 'sla_policies'], ['value' => $policies]); }); request()->attributes->set('audit.new_values', ['policies' => $policies]);

        return response()->json(['data' => SlaPolicy::orderBy('id')->get(), 'summary' => ['total' => 4]]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\PaperExtraction\MesinAliasStore;
use App\Services\PaperExtraction\Repositories\EloquentMesinCandidateProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MesinAliasAdminController extends Controller
{
    public function __construct(
        protected MesinAliasStore $aliasStore,
        protected EloquentMesinCandidateProvider $candidateProvider,
    ) {
    }

    public function index(): View
    {
        $aliases = collect($this->aliasStore->getAll())
            ->map(fn ($data, $rawKey) => [
                'raw_key' => $rawKey,
                'resrceno' => $data['resrceno'],
                'confirmed_at' => $data['confirmed_at'],
            ])
            ->sortByDesc('confirmed_at')
            ->values();

        return view('admin.mesin-aliases.index', [
            'aliases' => $aliases,
            'mesinOptions' => $this->candidateProvider->all(),
        ]);
    }

    public function update(Request $request, string $rawKey): JsonResponse
    {
        $request->validate(['resrceno' => 'required|string']);

        $this->aliasStore->put($rawKey, $request->input('resrceno'));

        return response()->json(['status' => 'ok']);
    }
}
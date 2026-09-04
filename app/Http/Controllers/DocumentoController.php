<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\Documento;
use App\Models\DocumentoAcesso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * M6 - Documentos.
 *
 * A compressao de imagem (RF-6.5) e a montagem do PDF multi-pagina (RF-6.1)
 * acontecem no cliente, antes do upload: o caso de uso e foto de celular no
 * corredor do forum, com 4G ruim. Aqui so chega o arquivo ja tratado.
 */
class DocumentoController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $max = config('mithrandir.uploads.max_mb') * 1024;

        $dados = $request->validate([
            'arquivo' => ['required', 'file', "max:{$max}"],
            'processo_id' => ['nullable', 'integer', 'exists:processos,id'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'checklist_item_id' => ['nullable', 'integer', 'exists:checklists,id'],
            'tipo' => ['nullable', 'string', 'max:60'],
            'nome' => ['nullable', 'string', 'max:255'],
            'origem' => ['nullable', 'in:upload,camera'],
        ]);

        $advogado = $request->user();
        $arquivo = $request->file('arquivo');

        $path = $arquivo->store("documentos/{$advogado->id}", config('mithrandir.uploads.disk'));

        $documento = Documento::create([
            'advogado_id' => $advogado->id,
            'processo_id' => $dados['processo_id'] ?? null,
            'cliente_id' => $dados['cliente_id'] ?? null,
            'nome' => $dados['nome'] ?? $arquivo->getClientOriginalName(),
            'tipo' => $dados['tipo'] ?? 'outro',
            'path' => $path,
            'mime' => $arquivo->getClientMimeType(),
            'tamanho' => $arquivo->getSize(),
            'origem' => $dados['origem'] ?? 'upload',
            'hash' => hash_file('sha256', $arquivo->getRealPath()),
        ]);

        // Amarra ao item do checklist, se veio de la (RF-6.3).
        if (! empty($dados['checklist_item_id'])) {
            ChecklistItem::where('id', $dados['checklist_item_id'])
                ->where('processo_id', $documento->processo_id)
                ->update(['documento_id' => $documento->id]);
        }

        return back()->with('sucesso', 'Documento anexado.');
    }

    /**
     * Secao 12: todo acesso a documento e registrado (quem abriu, quando).
     */
    public function download(Request $request, Documento $documento): StreamedResponse
    {
        abort_unless($documento->advogado_id === contexto()->advogadoId(), 403);
        abort_unless(contexto()->podeVerProcesso($documento->processo_id), 403);

        $disco = Storage::disk(config('mithrandir.uploads.disk'));

        abort_unless($disco->exists($documento->path), 404);

        DocumentoAcesso::create([
            'documento_id' => $documento->id,
            'advogado_id' => contexto()->advogadoId(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'em' => now(),
        ]);

        return $disco->response($documento->path, $documento->nome);
    }

    public function destroy(Request $request, Documento $documento): RedirectResponse
    {
        abort_unless($documento->advogado_id === contexto()->advogadoId(), 403);
        abort_unless(contexto()->podeVerProcesso($documento->processo_id), 403);

        ChecklistItem::where('documento_id', $documento->id)->update(['documento_id' => null]);

        Storage::disk(config('mithrandir.uploads.disk'))->delete($documento->path);
        $documento->delete();

        return back()->with('sucesso', 'Documento removido.');
    }
}

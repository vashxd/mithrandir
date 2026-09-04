<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\Prazo;
use App\Models\Processo;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela 3. "Como esta minha semana?"
 *
 * RF-3.1: timeline unica. Prazo, audiencia, compromisso e tarefa moram todos
 * na tabela `eventos`; o prazo tambem, espelhado pelo PrazoRepository.
 */
class AgendaController extends Controller
{
    public function index(Request $request): Response
    {
        $advogado = contexto()->advogado();
        $tz = $advogado->timezone ?: config('mithrandir.timezone');

        $visao = $request->string('visao')->toString() ?: 'semana';
        $referencia = $request->filled('data')
            ? CarbonImmutable::parse($request->string('data')->toString(), $tz)
            : CarbonImmutable::today($tz);

        [$de, $ate] = $this->janela($visao, $referencia);

        $eventos = Evento::with('processo.cliente')
            ->visivel()
            ->entre($de->startOfDay(), $ate->endOfDay())
            ->orderBy('inicio')
            ->get()
            ->map(fn (Evento $e) => $this->paraLista($e));

        return Inertia::render('Agenda/Index', [
            'eventos' => $eventos,
            'visao' => $visao,
            'referencia' => $referencia->toDateString(),
            'janela' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'processos' => Processo::visivel()
                ->whereNull('arquivado_em')
                ->orderBy('titulo')
                ->get(['id', 'titulo', 'numero_cnj'])
                ->map(fn (Processo $p) => ['id' => $p->id, 'rotulo' => $p->rotulo]),
        ]);
    }

    /**
     * RF-3.2: Hoje, Semana, Mes e "Fatais em 7 dias".
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function janela(string $visao, CarbonImmutable $referencia): array
    {
        return match ($visao) {
            'hoje' => [$referencia, $referencia],
            'mes' => [$referencia->startOfMonth(), $referencia->endOfMonth()],
            'fatais' => [$referencia, $referencia->addDays(7)],
            default => [$referencia->startOfWeek(CarbonImmutable::MONDAY), $referencia->endOfWeek(CarbonImmutable::SUNDAY)],
        };
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', 'in:audiencia,compromisso,tarefa'],
            'titulo' => ['required', 'string', 'max:200'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'inicio' => ['required', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'dia_inteiro' => ['boolean'],
            'local' => ['nullable', 'string', 'max:255'],
            'link' => ['nullable', 'url', 'max:500'],
            'lembrete_deslocamento_min' => ['nullable', 'integer', 'min:0', 'max:480'],
            'processo_id' => ['nullable', 'integer', 'exists:processos,id'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
        ]);

        Evento::create($dados + ['advogado_id' => contexto()->advogadoId()]);

        return back()->with('sucesso', 'Compromisso criado.');
    }

    public function update(Request $request, Evento $evento): RedirectResponse
    {
        abort_unless($evento->advogado_id === contexto()->advogadoId(), 403);
        abort_unless(contexto()->podeVerProcesso($evento->processo_id), 403);

        $dados = $request->validate([
            'titulo' => ['sometimes', 'string', 'max:200'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'inicio' => ['sometimes', 'date'],
            'fim' => ['nullable', 'date'],
            'local' => ['nullable', 'string', 'max:255'],
            'link' => ['nullable', 'url', 'max:500'],
            'concluido' => ['boolean'],
        ]);

        $evento->update($dados);

        return back()->with('sucesso', 'Compromisso atualizado.');
    }

    public function destroy(Request $request, Evento $evento): RedirectResponse
    {
        abort_unless($evento->advogado_id === contexto()->advogadoId(), 403);
        abort_unless(contexto()->podeVerProcesso($evento->processo_id), 403);

        // Evento espelhado de prazo nao se apaga pela agenda: o prazo e a fonte.
        if ($evento->eventable_type === Prazo::class) {
            return back()->with('erro', 'Este item vem de um prazo. Mude o status do prazo.');
        }

        $evento->delete();

        return back()->with('sucesso', 'Compromisso removido.');
    }

    /**
     * RF-3.6: exportar .ics do evento.
     */
    public function ics(Request $request, Evento $evento): HttpResponse
    {
        abort_unless($evento->advogado_id === contexto()->advogadoId(), 403);
        abort_unless(contexto()->podeVerProcesso($evento->processo_id), 403);

        $formato = fn ($data) => CarbonImmutable::parse($data)->utc()->format('Ymd\THis\Z');

        $linhas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Mithrandir//PT-BR',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:evento-'.$evento->id.'@mithrandir',
            'DTSTAMP:'.$formato(now()),
            'DTSTART:'.$formato($evento->inicio),
            'DTEND:'.$formato($evento->fim ?? $evento->inicio->addHour()),
            'SUMMARY:'.$this->escapar($evento->titulo),
            'DESCRIPTION:'.$this->escapar((string) $evento->descricao),
            'LOCATION:'.$this->escapar((string) $evento->local),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return response(implode("\r\n", $linhas), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="evento-'.$evento->id.'.ics"',
        ]);
    }

    private function escapar(string $texto): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $texto);
    }

    /**
     * @return array<string, mixed>
     */
    private function paraLista(Evento $evento): array
    {
        $prazo = $evento->eventable_type === Prazo::class
            ? Prazo::find($evento->eventable_id)
            : null;

        return [
            'id' => $evento->id,
            'tipo' => $evento->tipo,
            'titulo' => $evento->titulo,
            'descricao' => $evento->descricao,
            'inicio' => $evento->inicio?->toIso8601String(),
            'fim' => $evento->fim?->toIso8601String(),
            'dia' => $evento->inicio?->toDateString(),
            'dia_inteiro' => (bool) $evento->dia_inteiro,
            'local' => $evento->local,
            'link' => $evento->link,
            'concluido' => (bool) $evento->concluido,
            'processo' => $evento->processo ? [
                'id' => $evento->processo->id,
                'rotulo' => $evento->processo->rotulo,
                'cliente' => $evento->processo->cliente?->nome,
            ] : null,
            'prazo_id' => $prazo?->id,
            // RF-3.3: a cor da agenda vem da criticidade do prazo.
            'criticidade' => $prazo?->criticidade ?? ($evento->concluido ? 'concluido' : 'neutro'),
        ];
    }
}

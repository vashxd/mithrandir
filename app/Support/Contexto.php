<?php

namespace App\Support;

use App\Models\Membro;
use App\Models\User;

/**
 * De quem e o espaco de trabalho ativo, e o que a pessoa logada pode ver nele.
 *
 * Esta classe existe por causa da decisao 2 da secao 16: como `advogado_id`
 * esta em todas as tabelas desde a primeira migration, dar acesso a equipe nao
 * exigiu re-arquitetar nada. Bastou trocar DE ONDE vem o id do tenant.
 *
 *   antes:  doAdvogado(auth()->id())
 *   agora:  doAdvogado(contexto()->advogadoId())
 *
 * O titular continua sendo o dono de tudo. O colaborador entra no espaco dele,
 * com recorte por caso quando o acesso nao e total.
 */
class Contexto
{
    private ?User $usuario = null;

    private ?User $advogado = null;

    private ?Membro $membro = null;

    private bool $resolvido = false;

    /** @var array<int, int>|null */
    private ?array $processosVisiveis = null;

    private bool $processosCarregados = false;

    public const CHAVE_SESSAO = 'contexto_advogado_id';

    public function para(?User $usuario, ?int $advogadoEscolhido = null): self
    {
        $this->usuario = $usuario;
        $this->advogado = null;
        $this->membro = null;
        $this->resolvido = false;
        $this->processosCarregados = false;
        $this->processosVisiveis = null;

        if ($usuario === null) {
            $this->resolvido = true;

            return $this;
        }

        // Sem escolha, ou escolhendo o proprio espaco: a pessoa e a titular.
        if ($advogadoEscolhido === null || $advogadoEscolhido === $usuario->id) {
            $this->advogado = $usuario;
            $this->resolvido = true;

            return $this;
        }

        $membro = Membro::where('usuario_id', $usuario->id)
            ->where('titular_id', $advogadoEscolhido)
            ->where('ativo', true)
            ->whereNotNull('aceito_em')
            ->first();

        // Convite revogado ou inexistente: cai de volta no proprio espaco em
        // vez de dar erro. Perder acesso nao pode travar a pessoa fora do app.
        if ($membro === null) {
            $this->advogado = $usuario;
            $this->resolvido = true;

            return $this;
        }

        $this->membro = $membro;
        $this->advogado = $membro->titular;
        $this->resolvido = true;

        return $this;
    }

    public function usuario(): ?User
    {
        return $this->usuario;
    }

    public function advogado(): ?User
    {
        return $this->advogado;
    }

    public function advogadoId(): int
    {
        return (int) ($this->advogado?->id ?? 0);
    }

    public function membro(): ?Membro
    {
        return $this->membro;
    }

    /** A pessoa esta no proprio espaco de trabalho. */
    public function ehTitular(): bool
    {
        return $this->membro === null && $this->usuario !== null;
    }

    public function papel(): string
    {
        return $this->ehTitular() ? 'titular' : (string) $this->membro?->papel;
    }

    public function ehEstagiario(): bool
    {
        return $this->papel() === 'estagiario';
    }

    /**
     * Ids dos processos visiveis. `null` = a carteira inteira.
     *
     * @return array<int, int>|null
     */
    public function processosVisiveis(): ?array
    {
        if ($this->processosCarregados) {
            return $this->processosVisiveis;
        }

        $this->processosCarregados = true;
        $this->processosVisiveis = $this->ehTitular() ? null : $this->membro?->processosVisiveis();

        return $this->processosVisiveis;
    }

    public function temRecortePorCaso(): bool
    {
        return $this->processosVisiveis() !== null;
    }

    public function podeVerProcesso(?int $processoId): bool
    {
        $permitidos = $this->processosVisiveis();

        if ($permitidos === null) {
            return true;
        }

        return $processoId !== null && in_array($processoId, $permitidos, true);
    }

    /**
     * Permissoes por acao.
     *
     * A regra que mais importa e `prazo.cumprir`: quem responde pela perda do
     * prazo perante a OAB e o titular. Estagiario marca "fiz a minha parte" e
     * o prazo segue aberto ate a conferencia.
     */
    public function pode(string $acao): bool
    {
        if ($this->usuario === null) {
            return false;
        }

        if ($this->ehTitular()) {
            return true;
        }

        if (! $this->membro?->ativo) {
            return false;
        }

        return match ($this->papel()) {
            'advogado' => ! in_array($acao, [
                // So o titular mexe na propria conta e na equipe.
                'conta.editar', 'conta.excluir', 'equipe.gerir', 'radar.gerir', 'dados.exportar',
            ], true),

            'estagiario' => in_array($acao, [
                'processo.ver', 'processo.editar',
                'prazo.ver', 'prazo.criar', 'prazo.assumir', 'prazo.pedir_conferencia',
                'publicacao.ver', 'publicacao.triar',
                'cliente.ver', 'atendimento.registrar',
                'documento.ver', 'documento.enviar',
                'agenda.ver', 'agenda.criar',
            ], true),

            default => false,
        };
    }

    public function naoPode(string $acao): bool
    {
        return ! $this->pode($acao);
    }

    /**
     * @return array<string, mixed>
     */
    public function paraTela(): array
    {
        return [
            'advogado_id' => $this->advogadoId(),
            'advogado_nome' => $this->advogado?->name,
            'eh_titular' => $this->ehTitular(),
            'papel' => $this->papel(),
            'recorte_por_caso' => $this->temRecortePorCaso(),
            'permissoes' => [
                'cumprir_prazo' => $this->pode('prazo.cumprir'),
                'ver_financeiro' => $this->pode('financeiro.ver'),
                'gerir_equipe' => $this->pode('equipe.gerir'),
                'gerir_radar' => $this->pode('radar.gerir'),
            ],
        ];
    }
}

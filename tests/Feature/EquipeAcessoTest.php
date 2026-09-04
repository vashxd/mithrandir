<?php

namespace Tests\Feature;

use App\Models\Membro;
use App\Models\Processo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipeAcessoTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Membro $membro;

    private Processo $a;

    private Processo $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::create([
            'name' => 'Ana', 'email' => 'ana@t.test', 'password' => 'x',
            'oab' => '1', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $pedro = User::create([
            'name' => 'Pedro', 'email' => 'pedro@t.test', 'password' => 'x',
            'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $this->a = Processo::create(['advogado_id' => $this->titular->id, 'titulo' => 'Caso A']);
        $this->b = Processo::create(['advogado_id' => $this->titular->id, 'titulo' => 'Caso B']);

        $this->membro = Membro::create([
            'titular_id' => $this->titular->id,
            'usuario_id' => $pedro->id,
            'email' => $pedro->email,
            'nome' => 'Pedro',
            'papel' => 'estagiario',
            'ativo' => true,
            'aceito_em' => now(),
        ]);

        $this->membro->processos()->sync([$this->a->id]);
    }

    public function test_adiciona_caso_ao_estagiario(): void
    {
        $this->actingAs($this->titular)->patch("/equipe/{$this->membro->id}", [
            'papel' => 'estagiario',
            'ativo' => true,
            'acesso_total' => false,
            'processos' => [$this->a->id, $this->b->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->a->id, $this->b->id],
            $this->membro->fresh()->processos->pluck('id')->all()
        );
    }

    public function test_remove_todos_os_casos(): void
    {
        $this->actingAs($this->titular)->patch("/equipe/{$this->membro->id}", [
            'papel' => 'estagiario',
            'ativo' => true,
            'acesso_total' => false,
            'processos' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([], $this->membro->fresh()->processos->pluck('id')->all());
    }

    public function test_troca_para_acesso_total(): void
    {
        $this->actingAs($this->titular)->patch("/equipe/{$this->membro->id}", [
            'papel' => 'advogado',
            'ativo' => true,
            'acesso_total' => true,
            'processos' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $m = $this->membro->fresh();
        $this->assertTrue((bool) $m->acesso_total);
        $this->assertSame('advogado', $m->papel);
    }

    public function test_tela_de_equipe_abre_para_o_titular(): void
    {
        $r = $this->actingAs($this->titular)->get('/equipe');
        $r->assertOk();

        $membros = $r->viewData('page')['props']['membros'];
        $this->assertCount(1, $membros);
        $this->assertCount(1, $membros[0]['processos']);
    }
}

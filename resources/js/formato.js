const FUSO = 'America/Sao_Paulo';

const formatadorMoeda = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

export function moeda(valor) {
    return formatadorMoeda.format(Number(valor ?? 0));
}

/**
 * Datas do servidor chegam como 'YYYY-MM-DD'. Fazer new Date() nelas as
 * interpreta como UTC e, no fuso do Brasil, mostra o dia anterior — que num
 * app de prazo é o tipo de bug que custa caro. Por isso o parse é manual.
 */
export function dataCurta(iso) {
    if (!iso) return '';
    const [ano, mes, dia] = iso.slice(0, 10).split('-');
    return `${dia}/${mes}/${ano}`;
}

export function dataLonga(iso) {
    if (!iso) return '';
    const [ano, mes, dia] = iso.slice(0, 10).split('-').map(Number);
    return new Date(ano, mes - 1, dia).toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    });
}

export function diaDaSemana(iso) {
    if (!iso) return '';
    const [ano, mes, dia] = iso.slice(0, 10).split('-').map(Number);
    return new Date(ano, mes - 1, dia).toLocaleDateString('pt-BR', { weekday: 'long' });
}

export function hora(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: FUSO,
    });
}

export function dataHora(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: FUSO,
    });
}

export function hoje() {
    return new Date().toLocaleDateString('sv-SE', { timeZone: FUSO });
}

/**
 * "Faltam 3 dias" comunica mais que uma data solta na tela de quem está com
 * pressa no corredor do fórum.
 */
export function contagem(dias) {
    if (dias === null || dias === undefined) return '';
    if (dias < -1) return `${Math.abs(dias)} dias em atraso`;
    if (dias === -1) return 'venceu ontem';
    if (dias === 0) return 'vence HOJE';
    if (dias === 1) return 'vence amanhã';
    return `faltam ${dias} dias`;
}

/**
 * A escala de criticidade (RF-3.3).
 *
 * Os três estados vivos dividem o mesmo fundo — L .965, C .030 — e se
 * distinguem pelo matiz. Quem carrega a urgência é a barra e o peso do texto,
 * não a claridade do cartão: uma rampa em que "atenção" pesa mais que "normal"
 * ensina o olho ao contrário.
 *
 * Vencido rompe a regra de propósito. É o único estado que não pode passar
 * despercebido, então vira sólido, com texto branco.
 */
export const CORES_CRITICIDADE = {
    vencido: 'bg-vencido text-white ring-vencido',
    critico: 'bg-critico-fundo text-critico-tinta ring-critico-borda',
    atencao: 'bg-atencao-fundo text-atencao-tinta ring-atencao-borda',
    normal: 'bg-normal-fundo text-normal-tinta ring-normal-borda',
    concluido: 'bg-superficie-2 text-tinta-2 ring-borda',
    neutro: 'bg-superficie-2 text-tinta-2 ring-borda',
};

export const BARRA_CRITICIDADE = {
    /* Sobre o cartão sólido a barra vira um fio claro, não some. */
    vencido: 'bg-white/30',
    critico: 'bg-critico',
    atencao: 'bg-atencao',
    normal: 'bg-normal',
    concluido: 'bg-superficie-3',
    neutro: 'bg-superficie-3',
};

/**
 * Segundo canal, além da cor: triângulo cheio, disco, anel, tique. É o que
 * mantém a escala legível em tons de cinza e para daltonismo vermelho-verde —
 * cerca de 8% dos homens, num app em que errar o estado custa um prazo.
 */
export const ICONE_CRITICIDADE = {
    vencido: 'triangulo',
    critico: 'triangulo',
    atencao: 'disco',
    normal: 'anel',
    concluido: 'check',
    neutro: 'anel',
};

export const ROTULO_STATUS_PRAZO = {
    aberto: 'Aberto',
    em_andamento: 'Em andamento',
    cumprido: 'Cumprido',
    perdido: 'Perdido',
    prejudicado: 'Prejudicado',
};

export function iniciais(nome) {
    if (!nome) return '?';
    return nome
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((parte) => parte[0].toUpperCase())
        .join('');
}

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

export const CORES_CRITICIDADE = {
    vencido: 'bg-red-100 text-red-900 ring-red-300',
    critico: 'bg-red-50 text-red-800 ring-red-200',
    atencao: 'bg-amber-50 text-amber-900 ring-amber-200',
    normal: 'bg-sky-50 text-sky-900 ring-sky-200',
    concluido: 'bg-slate-100 text-slate-600 ring-slate-200',
    neutro: 'bg-slate-50 text-slate-700 ring-slate-200',
};

export const BARRA_CRITICIDADE = {
    vencido: 'bg-red-600',
    critico: 'bg-red-500',
    atencao: 'bg-amber-500',
    normal: 'bg-sky-500',
    concluido: 'bg-slate-300',
    neutro: 'bg-slate-300',
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

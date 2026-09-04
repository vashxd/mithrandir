<script setup>
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    ja_aceitou: { type: Boolean, default: false },
    versao: { type: String, required: true },
});

const formulario = useForm({ aceite: false });

function enviar() {
    formulario.post('/termo');
}
</script>

<template>
    <Head title="Termo de uso" />

    <div class="min-h-screen bg-fundo px-5 py-8">
        <div class="mx-auto w-full max-w-lg">
            <h1 class="text-2xl font-bold text-slate-900">Termo de uso</h1>
            <p class="mt-1 text-sm text-slate-500">Versão {{ versao }}</p>

            <div class="cartao mt-5 space-y-5 p-6 text-sm leading-relaxed text-slate-700">
                <!-- A cláusula que mais importa vem primeiro, não escondida no meio. -->
                <section class="rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200">
                    <h2 class="font-bold text-amber-900">1. O app não é fonte única de prazo</h2>
                    <p class="mt-2 text-amber-900">
                        O Mithrandir calcula prazos a partir das comunicações do DJEN e de um calendário
                        de feriados mantido manualmente. Esse calendário <strong>pode estar incompleto ou
                        desatualizado</strong>, porque não existe fonte pública unificada e confiável de
                        feriados forenses no Brasil.
                    </p>
                    <p class="mt-2 font-semibold text-amber-900">
                        A conferência das publicações no diário oficial e a validação de cada data
                        continuam sendo obrigação sua. Toda data exibida traz a cadeia de cálculo completa,
                        justamente para que você possa auditá-la.
                    </p>
                </section>

                <section>
                    <h2 class="font-bold text-slate-900">2. O que o app faz</h2>
                    <p class="mt-2">
                        Monitora o DJEN pelos termos que você cadastrar, organiza publicações, calcula e
                        acompanha prazos, agenda, casos, clientes, documentos e honorários. Ele não redige
                        peças, não pesquisa jurisprudência, não peticiona e não substitui seu julgamento
                        profissional.
                    </p>
                </section>

                <section>
                    <h2 class="font-bold text-slate-900">3. Falhas de captura</h2>
                    <p class="mt-2">
                        A API do CNJ pode ficar indisponível, mudar de contrato ou deixar de retornar uma
                        comunicação. Quando a varredura falhar duas vezes seguidas, você recebe um alerta
                        de “radar cego”. A ausência de publicações no app nunca significa que não houve
                        publicação.
                    </p>
                </section>

                <section>
                    <h2 class="font-bold text-slate-900">4. Dados e sigilo (LGPD)</h2>
                    <p class="mt-2">
                        Você é o <strong>controlador</strong> dos dados dos seus clientes; o Mithrandir
                        atua como <strong>operador</strong>, tratando esses dados apenas para prestar o
                        serviço. Ninguém da equipe do produto acessa conteúdo de caso sem seu consentimento
                        explícito, e todo acesso a documento é registrado (EOAB, art. 34).
                    </p>
                    <p class="mt-2">
                        Você pode exportar todos os seus dados a qualquer momento e pedir a exclusão da
                        conta, que se efetiva após 30 dias de carência.
                    </p>
                </section>

                <section>
                    <h2 class="font-bold text-slate-900">5. Limitação de responsabilidade</h2>
                    <p class="mt-2">
                        O serviço é fornecido no estado em que se encontra. O Mithrandir não responde por
                        perda de prazo, preclusão, revelia ou qualquer prejuízo decorrente de erro de
                        cálculo, falha de captura, indisponibilidade ou calendário desatualizado. A
                        responsabilidade profissional pelo processo é, e continua sendo, do advogado.
                    </p>
                </section>
            </div>

            <form class="mt-5" @submit.prevent="enviar">
                <label class="flex items-start gap-2.5 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <input v-model="formulario.aceite" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300">
                    <span class="text-sm leading-relaxed text-slate-800">
                        Li e aceito o termo, em especial a cláusula 1: <strong>a conferência do diário
                        oficial e a validação dos prazos continuam sendo minha responsabilidade.</strong>
                    </span>
                </label>

                <p v-if="formulario.errors.aceite" class="mt-2 text-sm text-red-600">{{ formulario.errors.aceite }}</p>

                <button type="submit" class="btn-primario mt-4 w-full" :disabled="formulario.processing">
                    {{ ja_aceitou ? 'Aceitar a nova versão' : 'Aceitar e continuar' }}
                </button>
            </form>
        </div>
    </div>
</template>

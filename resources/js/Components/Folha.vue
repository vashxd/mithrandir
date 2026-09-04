<script setup>
import { watch } from 'vue';
import Icone from './Icone.vue';

/**
 * Formulário em tela cheia. Regra de UI da seção 11: nada de modal com mais de
 * dois campos no mobile. No desktop a mesma folha vira um painel centrado —
 * ocupar 27 polegadas para preencher quatro campos é o que faz um app web
 * parecer um app de celular esticado.
 */
const props = defineProps({
    aberta: { type: Boolean, default: false },
    titulo: { type: String, required: true },
});

const emit = defineEmits(['fechar']);

watch(
    () => props.aberta,
    (aberta) => {
        document.body.style.overflow = aberta ? 'hidden' : '';
    }
);
</script>

<template>
    <Teleport to="body">
        <!-- Fundo escurecido: só existe no desktop, onde a folha não é tela cheia. -->
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-if="aberta"
                class="fixed inset-0 z-[55] hidden bg-slate-900/40 lg:block"
                @click="emit('fechar')"
            />
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-full lg:translate-y-2 lg:scale-[0.98] lg:opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="translate-y-full lg:translate-y-2 lg:scale-[0.98] lg:opacity-0"
        >
            <div
                v-if="aberta"
                class="fixed inset-0 z-[60] flex flex-col bg-slate-50
                       lg:inset-auto lg:left-1/2 lg:top-1/2 lg:max-h-[85vh] lg:w-full lg:max-w-2xl
                       lg:-translate-x-1/2 lg:-translate-y-1/2 lg:overflow-hidden lg:rounded-2xl
                       lg:border lg:border-slate-200 lg:bg-white lg:shadow-2xl"
            >
                <header class="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 pt-safe lg:pt-3">
                    <button
                        type="button"
                        class="-ml-2 flex h-11 w-11 items-center justify-center rounded-full text-slate-600 transition active:bg-slate-100 lg:order-3 lg:-ml-0 lg:-mr-2 lg:h-9 lg:w-9 lg:hover:bg-slate-100"
                        aria-label="Fechar"
                        @click="emit('fechar')"
                    >
                        <Icone nome="x" class="h-6 w-6 lg:h-5 lg:w-5" />
                    </button>
                    <h2 class="flex-1 truncate text-lg font-bold text-slate-900 lg:order-1">{{ titulo }}</h2>
                    <div class="flex shrink-0 items-center gap-2 lg:order-2">
                        <slot name="acao" />
                    </div>
                </header>

                <div class="flex-1 overflow-y-auto overscroll-contain px-4 py-4 pb-24 lg:px-6 lg:pb-6">
                    <div class="mx-auto max-w-2xl">
                        <slot />
                    </div>
                </div>

                <div
                    v-if="$slots.rodape"
                    class="border-t border-slate-200 bg-white px-4 py-3 pb-safe lg:px-6 lg:pb-3"
                >
                    <div class="mx-auto max-w-2xl">
                        <slot name="rodape" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

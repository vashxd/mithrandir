<script setup>
defineProps({
    titulo: { type: String, required: true },
    texto: { type: String, default: null },
});
</script>

<template>
    <div class="px-6 py-14 text-center">
        <p class="text-base font-semibold text-slate-700">{{ titulo }}</p>
        <p v-if="texto" class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-slate-500">
            {{ texto }}
        </p>
        <div class="mt-5">
            <slot />
        </div>
    </div>
</template>

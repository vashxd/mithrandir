import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from './Layouts/AppLayout.vue';
import { registrarServiceWorker } from './pwa';

const paginas = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    title: (titulo) => (titulo ? `${titulo} · Mithrandir` : 'Mithrandir'),

    resolve: async (nome) => {
        const importar = paginas[`./Pages/${nome}.vue`];

        if (!importar) {
            throw new Error(`Página não encontrada: ${nome}`);
        }

        const pagina = await importar();

        // Telas de autenticação e onboarding não usam a bottom bar.
        if (pagina.default.layout === undefined && !nome.startsWith('Auth/') && nome !== 'Onboarding') {
            pagina.default.layout = AppLayout;
        }

        return pagina;
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        color: '#0ea5e9',
        showSpinner: false,
    },
});

registrarServiceWorker();

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { enfileirarAnexo } from '../offline';
import Folha from './Folha.vue';
import Icone from './Icone.vue';

/**
 * RF-6.1, RF-6.4 e RF-6.5.
 *
 *  - captura por câmera, multi-página, virando um PDF único;
 *  - compressão no cliente antes do upload (4G ruim é a regra);
 *  - sem sinal, a foto entra na fila e sobe depois.
 */
const props = defineProps({
    processoId: { type: Number, default: null },
    clienteId: { type: Number, default: null },
    checklistItemId: { type: Number, default: null },
    tipos: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['enviado']);

const folhaAberta = ref(false);
const paginas = ref([]);
const tipo = ref('outro');
const nome = ref('');
const enviando = ref(false);
const mensagem = ref(null);

const entradaCamera = ref(null);
const entradaArquivo = ref(null);

const LARGURA_MAXIMA = 1600;
const QUALIDADE = 0.72;

function abrir() {
    paginas.value = [];
    nome.value = '';
    mensagem.value = null;
    folhaAberta.value = true;
}

/**
 * Reduz a foto antes de qualquer coisa: um JPEG de câmera moderna tem 4-8 MB,
 * e subir isso no 4G do fórum simplesmente não acontece.
 */
async function comprimir(arquivo) {
    const bitmap = await createImageBitmap(arquivo);
    const escala = Math.min(1, LARGURA_MAXIMA / Math.max(bitmap.width, bitmap.height));

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * escala);
    canvas.height = Math.round(bitmap.height * escala);

    const contexto = canvas.getContext('2d');
    contexto.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();

    const blob = await new Promise((resolver) => canvas.toBlob(resolver, 'image/jpeg', QUALIDADE));

    return {
        blob,
        largura: canvas.width,
        altura: canvas.height,
        previa: URL.createObjectURL(blob),
    };
}

async function adicionar(evento) {
    const arquivos = [...(evento.target.files ?? [])];
    evento.target.value = '';

    for (const arquivo of arquivos) {
        if (arquivo.type.startsWith('image/')) {
            paginas.value.push(await comprimir(arquivo));
        } else {
            // PDF ou outro anexo: sobe como veio, sem página de prévia.
            paginas.value.push({ blob: arquivo, nomeOriginal: arquivo.name, previa: null });
        }
    }
}

function remover(indice) {
    const pagina = paginas.value[indice];
    if (pagina.previa) URL.revokeObjectURL(pagina.previa);
    paginas.value.splice(indice, 1);
}

/**
 * Monta um PDF simples (uma imagem por página) sem biblioteca externa.
 * O orçamento de bundle não comporta um jsPDF só para isso.
 */
async function montarPdf(imagens) {
    const objetos = [];
    const codificador = new TextEncoder();

    const paginaIds = imagens.map((_, i) => 4 + i * 3);
    const kids = paginaIds.map((id) => `${id} 0 R`).join(' ');

    objetos[1] = `1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n`;
    objetos[2] = `2 0 obj\n<< /Type /Pages /Kids [${kids}] /Count ${imagens.length} >>\nendobj\n`;
    objetos[3] = `3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n`;

    const binarios = [];

    for (let i = 0; i < imagens.length; i++) {
        const imagem = imagens[i];
        const idPagina = 4 + i * 3;
        const idConteudo = idPagina + 1;
        const idImagem = idPagina + 2;

        // 72 dpi: a página acompanha o tamanho da imagem, sem esticar.
        const largura = imagem.largura;
        const altura = imagem.altura;

        objetos[idPagina] =
            `${idPagina} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${largura} ${altura}] ` +
            `/Resources << /XObject << /Im0 ${idImagem} 0 R >> >> /Contents ${idConteudo} 0 R >>\nendobj\n`;

        const fluxo = `q ${largura} 0 0 ${altura} 0 0 cm /Im0 Do Q`;
        objetos[idConteudo] = `${idConteudo} 0 obj\n<< /Length ${fluxo.length} >>\nstream\n${fluxo}\nendstream\nendobj\n`;

        const bytes = new Uint8Array(await imagem.blob.arrayBuffer());

        binarios[idImagem] = {
            cabecalho:
                `${idImagem} 0 obj\n<< /Type /XObject /Subtype /Image /Width ${largura} /Height ${altura} ` +
                `/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${bytes.length} >>\nstream\n`,
            bytes,
            rodape: '\nendstream\nendobj\n',
        };
    }

    const partes = [codificador.encode('%PDF-1.4\n')];
    const posicoes = [];
    let deslocamento = partes[0].length;

    const totalObjetos = 3 + imagens.length * 3;

    for (let id = 1; id <= totalObjetos; id++) {
        posicoes[id] = deslocamento;

        if (binarios[id]) {
            const cabecalho = codificador.encode(binarios[id].cabecalho);
            const rodape = codificador.encode(binarios[id].rodape);
            partes.push(cabecalho, binarios[id].bytes, rodape);
            deslocamento += cabecalho.length + binarios[id].bytes.length + rodape.length;
        } else if (objetos[id]) {
            const bloco = codificador.encode(objetos[id]);
            partes.push(bloco);
            deslocamento += bloco.length;
        }
    }

    let xref = `xref\n0 ${totalObjetos + 1}\n0000000000 65535 f \n`;

    for (let id = 1; id <= totalObjetos; id++) {
        xref += `${String(posicoes[id] ?? 0).padStart(10, '0')} 00000 n \n`;
    }

    xref += `trailer\n<< /Size ${totalObjetos + 1} /Root 1 0 R >>\nstartxref\n${deslocamento}\n%%EOF`;
    partes.push(codificador.encode(xref));

    return new Blob(partes, { type: 'application/pdf' });
}

async function enviar() {
    if (!paginas.value.length) return;

    enviando.value = true;
    mensagem.value = null;

    try {
        const imagens = paginas.value.filter((p) => p.largura);
        const outros = paginas.value.filter((p) => !p.largura);

        let blob;
        let nomeArquivo;

        if (imagens.length > 1) {
            blob = await montarPdf(imagens);
            nomeArquivo = `${nome.value || 'documento'}.pdf`;
        } else if (imagens.length === 1) {
            blob = imagens[0].blob;
            nomeArquivo = `${nome.value || 'foto'}.jpg`;
        } else {
            blob = outros[0].blob;
            nomeArquivo = nome.value || outros[0].nomeOriginal;
        }

        const anexo = {
            blob,
            nome: nomeArquivo,
            tipo: tipo.value,
            processo_id: props.processoId,
            cliente_id: props.clienteId,
            checklist_item_id: props.checklistItemId,
        };

        if (navigator.onLine) {
            const formulario = new FormData();
            formulario.append('arquivo', blob, nomeArquivo);
            formulario.append('origem', 'camera');
            formulario.append('tipo', tipo.value);
            if (props.processoId) formulario.append('processo_id', props.processoId);
            if (props.clienteId) formulario.append('cliente_id', props.clienteId);
            if (props.checklistItemId) formulario.append('checklist_item_id', props.checklistItemId);

            router.post('/documentos', formulario, {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    folhaAberta.value = false;
                    paginas.value = [];
                    emit('enviado');
                },
                onError: async () => {
                    await enfileirarAnexo(anexo);
                    mensagem.value = 'Envio falhou; ficou na fila e sobe depois.';
                },
                onFinish: () => (enviando.value = false),
            });

            return;
        }

        await enfileirarAnexo(anexo);
        mensagem.value = 'Sem conexão: guardado na fila. Sobe assim que o sinal voltar.';
        paginas.value = [];
    } catch (erro) {
        mensagem.value = `Não deu para preparar o arquivo: ${erro.message}`;
    } finally {
        enviando.value = false;
    }
}

defineExpose({ abrir });
</script>

<template>
    <div>
        <button type="button" class="btn-primario w-full" @click="abrir">
            <Icone nome="camera" class="h-5 w-5" />
            Fotografar ou anexar
        </button>

        <Folha :aberta="folhaAberta" titulo="Novo documento" @fechar="folhaAberta = false">
            <div class="space-y-4">
                <input
                    ref="entradaCamera"
                    type="file"
                    accept="image/*"
                    capture="environment"
                    multiple
                    class="hidden"
                    @change="adicionar"
                >
                <input
                    ref="entradaArquivo"
                    type="file"
                    accept="image/*,application/pdf"
                    multiple
                    class="hidden"
                    @change="adicionar"
                >

                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="btn-secundario" @click="entradaCamera.click()">
                        <Icone nome="camera" class="h-5 w-5" />
                        Câmera
                    </button>
                    <button type="button" class="btn-secundario" @click="entradaArquivo.click()">
                        <Icone nome="documento" class="h-5 w-5" />
                        Arquivo
                    </button>
                </div>

                <div v-if="paginas.length" class="space-y-2">
                    <p class="text-sm font-medium text-slate-700">
                        {{ paginas.length }} {{ paginas.length === 1 ? 'página' : 'páginas' }}
                        <span v-if="paginas.filter((p) => p.largura).length > 1" class="text-slate-500">
                            — vão virar um PDF único
                        </span>
                    </p>

                    <ul class="grid grid-cols-3 gap-2">
                        <li v-for="(pagina, indice) in paginas" :key="indice" class="relative">
                            <img
                                v-if="pagina.previa"
                                :src="pagina.previa"
                                alt=""
                                class="aspect-[3/4] w-full rounded-xl object-cover ring-1 ring-slate-200"
                            >
                            <div v-else class="flex aspect-[3/4] w-full items-center justify-center rounded-xl bg-slate-100 p-2 text-center text-[10px] text-slate-600 ring-1 ring-slate-200">
                                {{ pagina.nomeOriginal }}
                            </div>

                            <button
                                type="button"
                                class="absolute -right-1.5 -top-1.5 flex h-7 w-7 min-h-0 items-center justify-center rounded-full bg-slate-900 text-white"
                                :aria-label="`Remover página ${indice + 1}`"
                                @click="remover(indice)"
                            >
                                <Icone nome="x" class="h-4 w-4" />
                            </button>
                        </li>
                    </ul>
                </div>

                <div>
                    <label class="rotulo" for="tipo-doc">Tipo do documento</label>
                    <select id="tipo-doc" v-model="tipo" class="campo">
                        <option v-for="(rotulo, chave) in tipos" :key="chave" :value="chave">{{ rotulo }}</option>
                    </select>
                </div>

                <div>
                    <label class="rotulo" for="nome-doc">Nome</label>
                    <input id="nome-doc" v-model="nome" type="text" class="campo" placeholder="CNIS da dona Maria">
                </div>

                <p v-if="mensagem" class="rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-200">
                    {{ mensagem }}
                </p>
            </div>

            <template #rodape>
                <button
                    type="button"
                    class="btn-primario w-full"
                    :disabled="!paginas.length || enviando"
                    @click="enviar"
                >
                    {{ enviando ? 'Enviando…' : 'Salvar documento' }}
                </button>
            </template>
        </Folha>
    </div>
</template>

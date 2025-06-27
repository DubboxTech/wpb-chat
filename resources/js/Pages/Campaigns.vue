<template>
  <AppLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h1 class="text-2xl font-semibold text-gray-900">Campanhas</h1>
        <button @click="openModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium flex items-center">
          <PlusIcon class="h-5 w-5 mr-2" />
          Nova Campanha
        </button>
      </div>
    </template>

    <div class="mb-6 flex flex-wrap gap-4">
      <input type="text" v-model="filterForm.search" placeholder="Buscar campanhas..." class="form-input flex-grow">
      <select v-model="filterForm.status" class="form-input w-48">
        <option value="">Todos os status</option>
        <option value="draft">Rascunho</option>
        <option value="scheduled">Agendada</option>
        <option value="running">Executando</option>
        <option value="paused">Pausada</option>
        <option value="completed">Concluída</option>
        <option value="cancelled">Cancelada</option>
      </select>
    </div>

    <div v-if="campaigns.data.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div v-for="campaign in campaigns.data" :key="campaign.id" class="bg-white shadow rounded-lg overflow-hidden hover:shadow-md transition-shadow flex flex-col">
        <div class="p-6 flex-grow">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900 truncate">{{ campaign.name }}</h3>
            <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', getStatusClass(campaign.status)]">
              {{ campaign.status }}
            </span>
          </div>
          <p class="text-sm text-gray-600 mb-4 h-10">{{ campaign.description }}</p>
          <div class="space-y-3">
            <div class="flex justify-between text-sm">
              <span class="text-gray-500">Contatos:</span>
              <span class="font-medium">{{ campaign.total_contacts }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5">
              <div class="bg-green-600 h-2.5 rounded-full" :style="{ width: getProgress(campaign) + '%' }"></div>
            </div>
          </div>
        </div>
        <div class="px-6 py-3 bg-gray-50 border-t flex justify-end space-x-3">
          <button @click="performAction('pause', campaign)" v-if="campaign.status === 'running'" class="text-sm font-medium text-yellow-600 hover:text-yellow-700">Pausar</button>
          <button @click="performAction('resume', campaign)" v-if="campaign.status === 'paused'" class="text-sm font-medium text-green-600 hover:text-green-700">Retomar</button>
          <button @click="performAction('start', campaign)" v-if="['draft', 'scheduled'].includes(campaign.status)" class="text-sm font-medium text-green-600 hover:text-green-700">Iniciar Agora</button>
          <button @click="openModal(campaign)" class="text-sm font-medium text-blue-600 hover:text-blue-700">Editar</button>
          <button @click="performAction('delete', campaign)" v-if="['draft', 'completed', 'cancelled'].includes(campaign.status)" class="text-sm font-medium text-red-600 hover:text-red-700">Excluir</button>
        </div>
      </div>
    </div>
     <div v-else class="text-center py-12 bg-white shadow rounded-lg">
      <MegaphoneIcon class="h-12 w-12 text-gray-400 mx-auto mb-4" />
      <h3 class="text-lg font-medium text-gray-900 mb-2">Nenhuma campanha encontrada</h3>
      <p class="text-gray-500 mb-4">Crie sua primeira campanha para começar.</p>
    </div>

    <div v-if="campaigns.links && campaigns.links.length > 3" class="mt-6 flex justify-center items-center space-x-1">
        <Link
            v-for="(link, index) in campaigns.links"
            :key="index"
            :href="link.url"
            v-html="link.label"
            class="px-4 py-2 text-sm rounded-md"
            :class="{
                'bg-green-600 text-white': link.active,
                'text-gray-700 bg-white hover:bg-gray-50': !link.active && link.url,
                'text-gray-400 cursor-not-allowed': !link.url
            }"
        />
    </div>
    
    <div v-if="showModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
      <div class="relative mx-auto p-6 border w-11/12 md:w-3/4 lg:max-w-3xl shadow-lg rounded-md bg-white">
        <form @submit.prevent="saveCampaign" class="space-y-6">
          <h3 class="text-lg font-medium text-gray-900">{{ form.id ? 'Editar' : 'Nova' }} Campanha</h3>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="form-label">Nome da Campanha</label>
              <input v-model="form.name" type="text" required class="form-input"/>
            </div>
            <div>
                <label class="form-label">Tipo de Envio</label>
                <select v-model="form.type" required class="form-input">
                    <option value="immediate">Imediato</option>
                    <option value="scheduled">Agendado</option>
                </select>
            </div>
            <div v-if="form.type === 'scheduled'">
                <label class="form-label">Data e Hora do Envio</label>
                <input v-model="form.scheduled_at" type="datetime-local" class="form-input" :required="form.type === 'scheduled'" />
            </div>
            <div class="md:col-span-2">
                <label class="form-label">Conta do WhatsApp</label>
                <select v-model="form.whatsapp_account_id" @change="fetchTemplates" required class="form-input">
                    <option :value="null" disabled>Selecione uma conta</option>
                    <option v-for="account in availableAccounts" :key="account.id" :value="account.id">{{ account.name }} ({{ account.display_phone_number }})</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="form-label">Template da Mensagem</label>
                <select v-model="form.template_name" @change="onTemplateSelect" :disabled="!form.whatsapp_account_id || templatesLoading" required class="form-input">
                    <option v-if="templatesLoading" value="">Carregando...</option>
                    <option v-else-if="!availableTemplates.length && form.whatsapp_account_id" :value="null" disabled>Nenhum template aprovado</option>
                    <option v-else :value="null" disabled>Selecione um template</option>
                    <option v-for="template in availableTemplates" :key="template.name" :value="template.name">{{ template.name }} ({{ template.category }})</option>
                </select>
            </div>
          </div>

          <div v-if="selectedTemplateHasMedia" class="space-y-3 p-4 border rounded-md bg-yellow-50">
            <h4 class="font-medium text-gray-800">Mídia do Cabeçalho ({{ selectedTemplateHeaderFormat }})</h4>
            <div v-if="existing_header_media_url">
                <img v-if="selectedTemplateHeaderFormat === 'IMAGE'" :src="existing_header_media_url" class="max-w-xs max-h-48 rounded shadow mb-2" alt="Preview da Mídia">
                <video v-if="selectedTemplateHeaderFormat === 'VIDEO'" :src="existing_header_media_url" controls class="max-w-xs max-h-48 rounded shadow mb-2"></video>
                <a v-if="selectedTemplateHeaderFormat === 'DOCUMENT'" :href="existing_header_media_url" target="_blank" class="text-blue-600 hover:underline">Ver Documento</a>
                <button @click="existing_header_media_url = null" type="button" class="mt-2 text-sm font-medium text-red-600 hover:text-red-700">Trocar Mídia</button>
            </div>
            <div v-else>
                <p class="text-sm text-yellow-800">Este template requer o envio de uma mídia no cabeçalho.</p>
                <input type="file" @change="form.header_media = $event.target.files[0]" class="form-input" :accept="mediaAcceptTypes" />
                <div v-if="form.errors.header_media" class="text-red-500 text-xs mt-1">{{ form.errors.header_media }}</div>
            </div>
          </div>

          <div v-if="form.template_parameters.body.length > 0" class="space-y-3 p-4 border rounded-md bg-gray-50">
            <h4 class="font-medium text-gray-800">Variáveis do Corpo da Mensagem</h4>
            <div v-for="variable in form.template_parameters.body" :key="variable.key" class="grid grid-cols-1 md:grid-cols-3 gap-2 items-center">
              <label class="form-label md:col-span-1">Variável <code class="text-sm bg-gray-200 px-1 py-0.5 rounded" v-text="`{{${variable.key}}}`"></code></label>
              <div class="md:col-span-2 flex items-center space-x-2">
                  <select v-model="variable.type" class="form-input text-sm w-1/3">
                      <option value="manual">Manual</option>
                      <option value="field">Campo do Contato</option>
                  </select>
                  <input v-if="variable.type === 'manual'" v-model="variable.value" type="text" class="form-input text-sm flex-grow" placeholder="Digite o valor fixo"/>
                  <select v-else v-model="variable.value" class="form-input text-sm flex-grow">
                      <option value="" disabled>Selecione um campo</option>
                      <option value="name">Nome</option>
                      <option value="phone_number">Telefone</option>
                      <option value="custom.cep">CEP (Personalizado)</option>
                  </select>
              </div>
            </div>
          </div>
          
          <div class="space-y-3 p-4 border rounded-md">
              <h4 class="font-medium text-gray-800">Segmentação de Contatos</h4>
              <p class="text-sm text-gray-500">A campanha será enviada para contatos que satisfaçam TODAS as regras abaixo.</p>
              <div v-for="(filter, index) in form.segment_filters" :key="index" class="flex items-center space-x-2 bg-gray-50 p-2 rounded">
                <select v-model="filter.field" @change="filter.value = ''" class="form-input text-sm">
                  <option value="tags">Tag</option>
                  <option value="last_seen_at">Visto por último</option>
                </select>
                <select v-model="filter.operator" class="form-input text-sm">
                  <option v-if="filter.field === 'tags'" value="contains">Contém</option>
                  <option v-if="filter.field === 'last_seen_at'" value="after">Depois de</option>
                  <option v-if="filter.field === 'last_seen_at'" value="before">Antes de</option>
                </select>
                <select v-if="filter.field === 'tags'" v-model="filter.value" class="form-input text-sm flex-grow">
                    <option value="" disabled>Selecione uma tag</option>
                    <option v-for="segment in segments" :key="segment" :value="segment">
                        {{ segment }}
                    </option>
                </select>
                <input v-else-if="filter.field === 'last_seen_at'" v-model="filter.value" type="date" class="form-input text-sm flex-grow">
                <input v-else v-model="filter.value" type="text" class="form-input text-sm flex-grow">
                <button type="button" @click="removeFilter(index)" class="text-red-500 hover:text-red-700 p-2">
                  <XMarkIcon class="h-5 w-5" />
                </button>
              </div>
              <button type="button" @click="addFilter" class="text-sm font-medium text-green-600 hover:text-green-700">+ Adicionar Filtro</button>
          </div>

          <div class="flex justify-end space-x-3 pt-4">
            <button type="button" @click="closeModal" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-md">Cancelar</button>
            <button type="submit" :disabled="form.processing" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md disabled:bg-gray-400">
                {{ form.processing ? 'Salvando...' : 'Salvar Campanha' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { debounce } from 'lodash';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PlusIcon, MegaphoneIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';

const props = defineProps({
  campaigns: Object,
  filters: Object,
  segments: Array, 
  availableAccounts: Array,
});

const showModal = ref(false);
const availableTemplates = ref([]);
const templatesLoading = ref(false);
const existing_header_media_url = ref(null);

const filterForm = ref({
    search: props.filters.search || '',
    status: props.filters.status || '',
});

const form = useForm({
    id: null,
    name: '',
    description: '',
    whatsapp_account_id: null,
    template_name: null,
    template_parameters: { body: [] },
    segment_filters: [],
    type: 'immediate',
    scheduled_at: null,
    header_media: null,
    _method: 'POST', 
});

const saveCampaign = () => {
    const url = form.id ? route('campaigns.update', form.id) : route('campaigns.store');
    form._method = form.id ? 'PUT' : 'POST';

    form.post(url, {
        forceFormData: true, 
        onSuccess: () => closeModal(),
        onError: (errors) => {
             console.error("Erros de validação:", errors);
             alert("Houve um erro. Verifique os campos do formulário.");
        },
    });
};

const openModal = async (campaign = null) => {
    form.reset();
    form.clearErrors();
    existing_header_media_url.value = null;

    if (campaign) { // MODO EDIÇÃO
        form.id = campaign.id;
        form.name = campaign.name;
        form.description = campaign.description || '';
        form.whatsapp_account_id = campaign.whatsapp_account_id;
        form.template_name = campaign.template_name;
        form.segment_filters = campaign.segment_filters || [];
        form.type = campaign.scheduled_at ? 'scheduled' : 'immediate';
        form.scheduled_at = campaign.scheduled_at ? campaign.scheduled_at.substring(0, 16) : null;
        existing_header_media_url.value = campaign.template_parameters?.header_url || null;
        
        await fetchTemplates(); 
        
        if (selectedTemplate.value) {
            const savedBodyParams = campaign.template_parameters?.body || [];
            form.template_parameters.body = templateBodyVariables.value.map(tplVar => {
                const savedParam = savedBodyParams.find(p => p.key === tplVar.key);
                return savedParam ? { ...savedParam } : { ...tplVar };
            });
        }
    }
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
};

const fetchTemplates = async () => {
    if (!form.whatsapp_account_id) return;
    templatesLoading.value = true;
    try {
        const response = await axios.get(route('api.campaigns.templates', { whatsapp_account_id: form.whatsapp_account_id }));
        availableTemplates.value = response.data.data;
    } catch (error) {
        console.error("Erro ao buscar templates:", error);
    } finally {
        templatesLoading.value = false;
    }
};

const onTemplateSelect = () => {
    form.header_media = null; 
    existing_header_media_url.value = null;
    if (selectedTemplate.value) {
        form.template_parameters.body = templateBodyVariables.value;
    } else {
        form.template_parameters.body = [];
    }
};

const performAction = (action, campaign) => {
    if(action === 'delete' && !confirm('Tem certeza que deseja excluir esta campanha?')) return;
    router.post(route(`api.campaigns.${action}`, campaign.id), {}, { preserveScroll: true });
};

const addFilter = () => {
    form.segment_filters.push({ field: 'tags', operator: 'contains', value: '' });
};

const removeFilter = (index) => {
    form.segment_filters.splice(index, 1);
};

const getStatusClass = (status) => ({
    'draft': 'bg-gray-100 text-gray-800', 'scheduled': 'bg-blue-100 text-blue-800',
    'running': 'bg-green-100 text-green-800', 'paused': 'bg-yellow-100 text-yellow-800',
    'completed': 'bg-purple-100 text-purple-800', 'cancelled': 'bg-red-100 text-red-800',
}[status] || 'bg-gray-100 text-gray-800');

const getProgress = (campaign) => {
    if (!campaign.total_contacts || campaign.total_contacts === 0) return 0;
    const processed = (campaign.sent_count || 0) + (campaign.failed_count || 0);
    return (processed / campaign.total_contacts) * 100;
};

const selectedTemplate = computed(() => {
    if (!form.template_name || !availableTemplates.value.length) return null;
    return availableTemplates.value.find(t => t.name === form.template_name);
});

const selectedTemplateHeader = computed(() => selectedTemplate.value?.components.find(c => c.type === 'HEADER'));
const selectedTemplateHasMedia = computed(() => {
    const header = selectedTemplateHeader.value;
    return header && ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(header.format);
});
const selectedTemplateHeaderFormat = computed(() => selectedTemplateHeader.value?.format || '');

const mediaAcceptTypes = computed(() => {
    const format = selectedTemplateHeaderFormat.value;
    if (format === 'IMAGE') return 'image/jpeg,image/png';
    if (format === 'VIDEO') return 'video/mp4';
    if (format === 'DOCUMENT') return 'application/pdf';
    return '';
});

const templateBodyVariables = computed(() => {
    if (!selectedTemplate.value) return [];
    const bodyComponent = selectedTemplate.value.components.find(c => c.type === 'BODY');
    if (!bodyComponent || !bodyComponent.text) return [];

    const matches = bodyComponent.text.match(/\{\{(\d+)\}\}/g) || [];
    return matches.map(match => {
        const key = match.replace(/\{|\}/g, '');
        return { key, type: 'manual', value: '' };
    });
});
</script>
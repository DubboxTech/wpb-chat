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
      <input type="text" v-model="filterForm.search" placeholder="Buscar campanhas..." @input="searchCampaigns" class="form-input flex-grow">
      <select v-model="filterForm.status" @change="searchCampaigns" class="form-input w-48">
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
               {{ getStatusText(campaign.status) }}
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
          <Link
              v-if="campaign.status === 'completed'"
              :href="route('campaigns.report', campaign.id)"
              class="text-sm font-medium text-purple-600 hover:text-purple-700"
            >
              Ver Relatório
            </Link>
          <button @click="performAction('pause', campaign)" v-if="campaign.status === 'running'" class="text-sm font-medium text-yellow-600 hover:text-yellow-700">Pausar</button>
          <button @click="performAction('resume', campaign)" v-if="campaign.status === 'paused'" class="text-sm font-medium text-green-600 hover:text-green-700">Retomar</button>
          <button @click="performAction('start', campaign)" v-if="['draft', 'scheduled'].includes(campaign.status)" class="text-sm font-medium text-green-600 hover:text-green-700">Iniciar Agora</button>
          <button @click="openModal(campaign)" class="text-sm font-medium text-blue-600 hover:text-blue-700" v-if="campaign.status !== 'completed'">Editar</button>
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
        <form @submit.prevent="saveCampaign" class="space-y-6 max-h-[90vh] overflow-y-auto pr-4">
          <h3 class="text-lg font-medium text-gray-900">{{ form.id ? 'Editar' : 'Nova' }} Campanha</h3>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="form-label">Nome da Campanha</label><input v-model="form.name" type="text" required class="form-input"/></div>
            <div><label class="form-label">Tipo de Envio</label><select v-model="form.type" required class="form-input"><option value="immediate">Imediato</option><option value="scheduled">Agendado</option></select></div>
            <div v-if="form.type === 'scheduled'"><label class="form-label">Data e Hora do Envio</label><input v-model="form.scheduled_at" type="datetime-local" class="form-input" :required="form.type === 'scheduled'" /></div>
            <div class="md:col-span-2"><label class="form-label">Conta do WhatsApp</label><select v-model="form.whatsapp_account_id" @change="onAccountChange" required class="form-input"><option :value="null" disabled>Selecione uma conta</option><option v-for="account in availableAccounts" :key="account.id" :value="account.id">{{ account.name }} ({{ account.display_phone_number }})</option></select></div>
            <div class="md:col-span-2"><label class="form-label">Template da Mensagem</label><select v-model="form.template_name" @change="onTemplateSelect" :disabled="!form.whatsapp_account_id || templatesLoading" required class="form-input"><option v-if="templatesLoading" value="">Carregando...</option><option v-else-if="!availableTemplates.length && form.whatsapp_account_id" :value="null" disabled>Nenhum template aprovado</option><option v-else :value="null" disabled>Selecione um template</option><option v-for="template in availableTemplates" :key="template.name" :value="template.name">{{ template.name }} ({{ template.category }})</option></select></div>
          </div>
          <div v-if="templateVariables.length > 0" class="space-y-4 p-4 border rounded-md bg-gray-50"><h4 class="font-medium text-gray-800">Variáveis do Template</h4><div v-for="variable in templateVariables" :key="variable" class="grid grid-cols-1 md:grid-cols-2 gap-2 items-center"><label class="form-label md:col-span-1">Variável <code class="text-sm bg-gray-200 px-1 py-0.5 rounded">{{variable}}</code></label><div class="md:col-span-1"><select v-model="form.template_parameters[variable]" class="form-input text-sm"><option value="name">Nome do Contato</option><option value="phone_number">Telefone</option><option value="custom.cep">CEP (custom)</option><option value="custom.cidade">Cidade (custom)</option></select></div></div></div>
          
          <div v-if="templateHasMediaHeader" class="space-y-3 p-4 border rounded-md bg-gray-50">
            <h4 class="font-medium text-gray-800">Mídia da Campanha (Header)</h4>
            <div v-if="form.media.length > 0">
              <div v-for="(mediaItem, index) in form.media" :key="index" class="flex items-center space-x-2">
                <img :src="mediaItem.url" class="h-16 w-16 object-cover rounded" />
                <p class="text-sm text-gray-700">{{ mediaItem.file_name }}</p>
                <button type="button" @click="removeMedia(index)" class="text-red-500 hover:text-red-700 p-2"><XMarkIcon class="h-5 w-5" /></button>
              </div>
            </div>
            <input type="file" @change="handleMediaUpload" class="form-input" />
          </div>

          <div class="space-y-3 p-4 border rounded-md"><h4 class="font-medium text-gray-800">Segmentação de Contatos</h4><p class="text-sm text-gray-500">A campanha será enviada para contatos que satisfaçam TODAS as regras abaixo.</p><div v-for="(filter, index) in form.segment_filters" :key="index" class="flex items-center space-x-2 bg-gray-50 p-2 rounded"><select v-model="filter.field" @change="filter.value = ''" class="form-input text-sm"><option value="tags">Tag</option><option value="last_seen_at">Visto por último</option></select><select v-model="filter.operator" class="form-input text-sm"><option v-if="filter.field === 'tags'" value="contains">Contém</option><option v-if="filter.field === 'last_seen_at'" value="after">Depois de</option><option v-if="filter.field === 'last_seen_at'" value="before">Antes de</option></select><select v-if="filter.field === 'tags'" v-model="filter.value" class="form-input text-sm flex-grow"><option value="" disabled>Selecione uma tag</option><option v-for="segment in segments" :key="segment" :value="segment">{{ segment }}</option></select><input v-else-if="filter.field === 'last_seen_at'" v-model="filter.value" type="date" class="form-input text-sm flex-grow"><button type="button" @click="removeFilter(index)" class="text-red-500 hover:text-red-700 p-2"><XMarkIcon class="h-5 w-5" /></button></div><button type="button" @click="addFilter" class="text-sm font-medium text-green-600 hover:text-green-700">+ Adicionar Filtro</button></div>

          <div class="flex justify-end space-x-3 pt-4 border-t mt-6">
            <button v-if="form.id" type="button" @click="fetchContactsPreview" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Ver Contatos</button>
            <button type="button" @click="previewMessage" :disabled="!form.template_name" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md text-sm disabled:bg-gray-300">Pré-visualizar</button>
            <button type="button" @click="showModal = false" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-md">Cancelar</button>
            <button type="submit" :disabled="isSaving" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md disabled:bg-gray-400">{{ isSaving ? 'Salvando...' : 'Salvar Campanha' }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showPreviewModal" class="fixed inset-0 bg-black bg-opacity-50 z-[60] flex items-center justify-center" @click="showPreviewModal = false">
      <div class="bg-transparent p-4 w-full max-w-sm" @click.stop><div class="bg-[#dcf8c6] text-black rounded-lg p-3 relative shadow-lg" style="width: fit-content; max-width: 100%;"><div class="absolute -top-2 -right-2 w-0 h-0 border-l-[10px] border-l-transparent border-t-[10px] border-t-[#dcf8c6]"></div><div v-if="previewContent.mediaUrl" class="mb-2"><img :src="previewContent.mediaUrl" class="rounded-md w-full object-cover" alt="Preview da Mídia"/></div><p class="text-sm whitespace-pre-wrap">{{ previewContent.text }}</p><div class="text-right text-xs text-gray-500 mt-1"><span>11:45</span></div></div></div>
    </div>

    <div v-if="showContactsPreviewModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 z-[60] flex items-center justify-center" @click="showContactsPreviewModal = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6" @click.stop>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Contatos da Campanha</h3>
        <div class="max-h-96 overflow-y-auto">
          <div v-if="contactsPreviewLoading" class="text-center py-4">Carregando...</div>
          <div v-else>
            <ul v-if="previewContacts.length > 0" class="divide-y divide-gray-200">
              <li v-for="contact in previewContacts" :key="contact.phone_number" class="py-3 flex justify-between">
                <span class="text-gray-800">{{ contact.name }}</span>
                <span class="text-gray-500">{{ contact.phone_number }}</span>
              </li>
            </ul>
            <p v-else class="text-gray-500 text-center py-4">Nenhum contato associado a esta campanha.</p>
            <p class="text-xs text-gray-400 mt-2 text-center" v-if="previewContacts.length > 0">Mostrando os contatos da campanha salva.</p>
          </div>
        </div>
        <div class="text-right mt-6">
          <button @click="showContactsPreviewModal = false" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-md">Fechar</button>
        </div>
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
});

const showModal = ref(false);
const availableAccounts = ref([]);
const availableTemplates = ref([]);
const templatesLoading = ref(false);
const isSaving = ref(false);
const showPreviewModal = ref(false);
const previewContent = ref({ text: '', mediaUrl: null });
const showContactsPreviewModal = ref(false);
const contactsPreviewLoading = ref(false);
const previewContacts = ref([]);

const filterForm = ref({ search: props.filters.search || '', status: props.filters.status || '' });
const form = useForm({ id: null, name: '', description: '', whatsapp_account_id: null, template_name: null, template_parameters: {}, segment_filters: [], type: 'immediate', scheduled_at: null, media: [] });

watch(() => form.type, (newType) => { if (newType === 'immediate') form.scheduled_at = null; });
watch(filterForm, debounce(() => router.get(route('campaigns.index'), filterForm.value, { preserveState: true, replace: true }), 300), { deep: true });

const openModal = async (campaign = null) => {
    form.reset();
    form.clearErrors();
    isSaving.value = true;
    showModal.value = false;
    await fetchAccounts();
    if (campaign) {
        try {
            const response = await axios.get(route("api.campaigns.show", campaign.id));
            const fullCampaign = response.data.campaign;
            const templateNameToSelect = fullCampaign.template_name;
            form.id = fullCampaign.id;
            form.name = fullCampaign.name;
            form.description = fullCampaign.description;
            form.whatsapp_account_id = fullCampaign.whatsapp_account_id;
            if (fullCampaign.template_parameters?.header?.type === 'media' && fullCampaign.template_parameters?.header?.url) {
                form.media.push({ url: fullCampaign.template_parameters.header.url, file_name: 'Mídia da Campanha' });
            }
            form.segment_filters = fullCampaign.segment_filters || [];
            form.type = fullCampaign.scheduled_at ? 'scheduled' : 'immediate';
            form.scheduled_at = fullCampaign.scheduled_at ? fullCampaign.scheduled_at.substring(0, 16) : null;
            if (form.whatsapp_account_id) {
                await fetchTemplates();
                form.template_name = templateNameToSelect;
                onTemplateSelect();
                form.template_parameters = fullCampaign.template_parameters || {};
            }
        } catch (error) {
            console.error("Erro ao carregar dados da campanha:", error);
            isSaving.value = false;
            return;
        }
    }
    isSaving.value = false;
    showModal.value = true;
};

const fetchAccounts = async () => { try { const response = await axios.get(route('api.whatsapp-accounts.index')); availableAccounts.value = response.data.accounts; } catch (error) { console.error("Erro ao buscar contas:", error); } };
const fetchTemplates = async () => { if (!form.whatsapp_account_id) return; templatesLoading.value = true; availableTemplates.value = []; try { const response = await axios.get(route('api.campaigns.templates', { whatsapp_account_id: form.whatsapp_account_id })); if (response.data.success) { availableTemplates.value = response.data.data; } else { throw new Error(response.data.message); } } catch (error) { console.error("Erro ao buscar templates:", error); } finally { templatesLoading.value = false; } };
const onAccountChange = () => { form.template_name = null; form.template_parameters = {}; fetchTemplates(); };
const onTemplateSelect = () => {
    form.template_parameters = {};
    const template = availableTemplates.value.find(t => t.name === form.template_name);
    if (template) {
        templateVariables.value.forEach(variable => { form.template_parameters[variable] = 'name'; });
        const hasMedia = template.components.some(c => c.type === 'HEADER' && ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(c.format));
        if (!hasMedia) { form.media = []; }
    }
};

const previewMessage = () => {
    const template = availableTemplates.value.find(t => t.name === form.template_name);
    if (!template) { alert("Selecione um template primeiro."); return; }
    let bodyText = '';
    const bodyComponent = template.components.find(c => c.type === 'BODY');
    if (bodyComponent) bodyText = bodyComponent.text;
    const fieldNameMap = { name: '[Nome do Contato]', phone_number: '[Telefone]', 'custom.cep': '[CEP]', 'custom.cidade': '[Cidade]' };
    templateVariables.value.forEach(variable => {
        const fieldKey = form.template_parameters[variable] || 'name';
        const friendlyName = fieldNameMap[fieldKey] || `[${fieldKey}]`;
        bodyText = bodyText.replace(`{{${variable}}}`, friendlyName);
    });
    let mediaUrl = null;
    if (templateHasMediaHeader.value && form.media.length > 0) { mediaUrl = form.media[0].url; }
    previewContent.value = { text: bodyText, mediaUrl };
    showPreviewModal.value = true;
};

const fetchContactsPreview = async () => {
  console.log("Fetching contacts preview for campaign ID:", form.id);
    if (!form.id) return;
    showContactsPreviewModal.value = true;
    contactsPreviewLoading.value = true;
    previewContacts.value = [];
    try {
        const response = await axios.get(route('api.campaigns.contacts', form.id));
        if (response.data.success) {
            previewContacts.value = response.data.contacts.data;
        } else {
            alert("Erro ao buscar contatos: " + response.data.message);
        }
    } catch (error) {
        console.error("Erro ao buscar contatos para preview:", error);
        alert("Ocorreu um erro de rede ao buscar os contatos.");
    } finally {
        contactsPreviewLoading.value = false;
    }
};

const saveCampaign = async () => {
    isSaving.value = true;
    const url = form.id ? route("api.campaigns.update", form.id) : route("api.campaigns.store");
    const method = form.id ? "post" : "post";
    const formData = new FormData();
    for (const key in form.data()) {
        if (key === "media") {
            form.data().media.forEach((mediaItem) => { if (mediaItem.file instanceof File) { formData.append('header_media', mediaItem.file); } });
        } else if (key === "template_parameters" || key === "segment_filters") {
            formData.append(key, JSON.stringify(form.data()[key]));
        } else if (form.data()[key] !== null) {
            formData.append(key, form.data()[key]);
        }
    }
    if (form.id) { formData.append("_method", "PUT"); }
    try {
        await axios[method](url, formData, { headers: { "Content-Type": "multipart/form-data" } });
        showModal.value = false;
        router.reload({ only: ["campaigns"] });
    } catch (error) {
        if (error.response && error.response.status === 422) {
            form.setError(error.response.data.errors);
            alert("Por favor, verifique os erros no formulário.");
        } else {
            alert(`Ocorreu um erro: ${error.response?.data?.message || "Verifique os dados."}`);
        }
    } finally {
        isSaving.value = false;
    }
};

const performAction = async (action, campaign) => {
    if(action === 'delete' && !confirm('Tem certeza que deseja excluir esta campanha?')) return;
    try {
        await axios.post(route(`api.campaigns.${action}`, campaign.id));
        router.reload({ only: ['campaigns'], preserveScroll: true });
    } catch (error) {
        alert(`Não foi possível executar a ação: ${error.response?.data?.message || 'Erro desconhecido'}`);
    }
};

const addFilter = () => { form.segment_filters.push({ field: 'tags', operator: 'contains', value: '' }); };
const removeFilter = (index) => { form.segment_filters.splice(index, 1); };
const handleMediaUpload = (event) => { const file = event.target.files[0]; if (file) { form.media = [{ file, url: URL.createObjectURL(file), file_name: file.name }]; } };
const removeMedia = (index) => { form.media.splice(index, 1); };
const templateHasMediaHeader = computed(() => { const template = availableTemplates.value.find(t => t.name === form.template_name); if (!template) return false; return template.components.some(c => c.type === 'HEADER' && ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(c.format)); });
const templateVariables = computed(() => { const template = availableTemplates.value.find(t => t.name === form.template_name); if (!template) return []; const variables = new Set(); template.components?.forEach(component => { if (component.text) { const matches = component.text.match(/\{\{([0-9]+)\}\}/g); if (matches) { matches.forEach(match => variables.add(match.replace(/\{|\}/g, ''))); } } }); return Array.from(variables).sort((a, b) => a - b); });
const getProgress = (campaign) => { if (!campaign.total_contacts) return 0; const processed = campaign.sent_count + campaign.failed_count; return (processed / campaign.total_contacts) * 100; };
const getStatusText = (status) => ({ 'draft': 'Rascunho', 'scheduled': 'Agendada', 'running': 'Executando', 'paused': 'Pausada', 'completed': 'Concluída', 'cancelled': 'Cancelada' }[status] || status);
const getStatusClass = (status) => ({ 'draft': 'bg-gray-100 text-gray-800', 'scheduled': 'bg-blue-100 text-blue-800', 'running': 'bg-green-100 text-green-800', 'paused': 'bg-yellow-100 text-yellow-800', 'completed': 'bg-purple-100 text-purple-800', 'cancelled': 'bg-red-100 text-red-800' }[status] || 'bg-gray-100 text-gray-800');
</script>
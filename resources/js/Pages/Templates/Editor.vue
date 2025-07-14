<template>
    <AppLayout>
      <template #header>
        <h1 class="text-2xl font-semibold text-gray-900">Modelos de Mensagem</h1>
      </template>
  
      <div class="space-y-6">
        <div class="bg-white shadow rounded-lg p-6">
          <div class="flex flex-col md:flex-row md:justify-between md:items-center space-y-4 md:space-y-0">
            <div class="flex-grow">
              <label for="account" class="block text-sm font-medium text-gray-700">Conta do WhatsApp</label>
              <select id="account" v-model="selectedAccountId" @change="fetchTemplates" class="mt-1 block w-full md:w-96 pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                <option :value="null" disabled>Selecione uma conta</option>
                <option v-for="account in accounts" :key="account.id" :value="account.id">
                  {{ account.name }} ({{ account.display_phone_number }})
                </option>
              </select>
            </div>
            <button @click="openModal()" :disabled="!selectedAccountId" class="btn-primary w-full md:w-auto" title="Selecione uma conta para criar um modelo">
              Novo Modelo
            </button>
          </div>
        </div>
  
        <div v-if="selectedAccountId">
          <div v-if="isLoading" class="text-center p-10">
              <p class="text-gray-600">Carregando modelos...</p>
          </div>
          <div v-else-if="templates.length === 0" class="text-center bg-white shadow rounded-lg p-10">
              <h3 class="text-lg font-medium text-gray-900">Nenhum modelo encontrado</h3>
              <p class="text-sm text-gray-500 mt-1">Esta conta ainda não possui modelos de mensagem.</p>
          </div>
          <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              <div v-for="template in templates" :key="template.id" class="bg-white shadow rounded-lg overflow-hidden flex flex-col">
                  <div class="p-5 flex-grow">
                      <div class="flex justify-between items-start">
                          <p class="text-xs text-gray-500 uppercase">{{ template.category }}</p>
                          <span :class="statusBadgeClass(template.status)" class="px-2 py-1 text-xs font-semibold rounded-full">
                              {{ formatStatus(template.status) }}
                          </span>
                      </div>
                      <h4 class="text-lg font-bold text-gray-800 mt-2">{{ template.name }}</h4>
                      <p class="text-sm text-gray-600 mt-1">Idioma: {{ template.language }}</p>
                      <div class="mt-4 pt-4 border-t">
                          <p class="text-sm font-medium text-gray-700">Corpo:</p>
                          <p class="text-sm text-gray-500 mt-1 line-clamp-3">
                              {{ getBodyText(template.components) }}
                          </p>
                      </div>
                  </div>
                  <div class="bg-gray-50 p-4 flex justify-end">
                      <button @click="deleteTemplate(template.name)" class="text-sm text-red-600 hover:text-red-800 disabled:opacity-50" :disabled="template.status === 'PENDING'">
                          Excluir
                      </button>
                  </div>
              </div>
          </div>
        </div>
      </div>
  
      <div v-if="isModalOpen" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-50 flex justify-center items-center" @click="closeModal">
          <div class="bg-white rounded-lg shadow-xl transform transition-all sm:max-w-2xl sm:w-full p-6" @click.stop>
              <h3 class="text-lg leading-6 font-medium text-gray-900">Criar Novo Modelo</h3>
              <form @submit.prevent="createTemplate" class="mt-4 space-y-4 max-h-[80vh] overflow-y-auto pr-4">
                  
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                          <label class="form-label">Nome</label>
                          <input v-model="form.name" type="text" class="form-input" placeholder="ex: welcome_message" required/>
                          <p class="text-xs text-gray-500 mt-1">Apenas letras minúsculas, números e _. Ex: `order_confirmation`.</p>
                      </div>
                      <div>
                          <label class="form-label">Idioma</label>
                          <input v-model="form.language" type="text" class="form-input" placeholder="ex: pt_BR" required/>
                      </div>
                  </div>
  
                  <div>
                      <label class="form-label">Categoria</label>
                      <select v-model="form.category" class="form-input">
                          <option value="AUTHENTICATION">Autenticação</option>
                          <option value="MARKETING">Marketing</option>
                          <option value="UTILITY">Utilidade</option>
                      </select>
                  </div>
                  
                  <div class="border-t pt-4 space-y-4">
                      <h4 class="font-medium">Componentes</h4>
                      
                      <div>
                          <label class="form-label">Corpo (Body)</label>
                          <textarea v-model="bodyComponent.text" rows="4" class="form-input" placeholder="Olá {{1}}, seu pedido {{2}} foi confirmado." required></textarea>
                          <p class="text-xs text-gray-500 mt-1">Use `{{1}}`, `{{2}}` para variáveis.</p>
                      </div>
  
                      <div>
                          <label class="form-label">Botões (Opcional)</label>
                          <div v-for="(button, index) in buttonsComponent.buttons" :key="index" class="flex items-center space-x-2 p-2 bg-gray-50 rounded-md mb-2">
                               <select v-model="button.type" class="form-input text-sm w-1/3">
                                  <option value="QUICK_REPLY">Resposta Rápida</option>
                                  <option value="URL">Link</option>
                              </select>
                              <input v-model="button.text" type="text" placeholder="Texto do botão" class="form-input text-sm flex-grow"/>
                              <input v-if="button.type === 'URL'" v-model="button.url" type="text" placeholder="https://..." class="form-input text-sm flex-grow"/>
                              <button type="button" @click="removeButton(index)" class="text-red-500 p-1">&times;</button>
                          </div>
                          <button v-if="buttonsComponent.buttons.length < 3" type="button" @click="addButton" class="text-sm text-blue-600">+ Adicionar Botão</button>
                      </div>
                  </div>
  
                  <div class="flex justify-end space-x-2 pt-4">
                      <button type="button" @click="closeModal" class="bg-gray-200 text-gray-800 px-4 py-2 rounded-md text-sm font-medium hover:bg-gray-300">Cancelar</button>
                      <button type="submit" :disabled="isSubmitting" class="btn-primary">
                          {{ isSubmitting ? 'Criando...' : 'Criar Modelo' }}
                      </button>
                  </div>
              </form>
          </div>
      </div>
    </AppLayout>
  </template>
  
  <script setup>
  import { ref, onMounted } from 'vue';
  import AppLayout from '@/Layouts/AppLayout.vue';
  import axios from 'axios';
  
  const accounts = ref([]);
  const selectedAccountId = ref(null);
  const templates = ref([]);
  const isLoading = ref(false);
  const isModalOpen = ref(false);
  const isSubmitting = ref(false);
  
  const form = ref({});
  const bodyComponent = ref({ type: 'BODY', text: '' });
  const buttonsComponent = ref({ type: 'BUTTONS', buttons: [] });
  
  const resetForm = () => {
    form.value = {
      name: '',
      category: 'UTILITY',
      language: 'pt_BR',
      components: []
    };
    bodyComponent.value = { type: 'BODY', text: '' };
    buttonsComponent.value = { type: 'BUTTONS', buttons: [] };
  };
  
  const fetchAccounts = async () => {
      try {
          const response = await axios.get(route('api.whatsapp-accounts.index'));
          accounts.value = response.data.accounts;
          if (accounts.value.length > 0) {
              selectedAccountId.value = accounts.value[0].id;
              await fetchTemplates();
          }
      } catch (error) {
          console.error("Erro ao buscar contas:", error);
      }
  };
  
  const fetchTemplates = async () => {
      if (!selectedAccountId.value) return;
      isLoading.value = true;
      templates.value = [];
      try {
          const response = await axios.get(route('api.templates.index', { whatsapp_account_id: selectedAccountId.value }));
          templates.value = response.data.data;
      } catch (error) {
          console.error("Erro ao buscar modelos:", error);
          templates.value = [];
      } finally {
          isLoading.value = false;
      }
  };
  
  const openModal = () => {
      resetForm();
      isModalOpen.value = true;
  };
  
  const closeModal = () => {
      isModalOpen.value = false;
  };
  
  const addButton = () => {
      if (buttonsComponent.value.buttons.length < 3) {
          buttonsComponent.value.buttons.push({ type: 'QUICK_REPLY', text: '' });
      }
  };
  
  const removeButton = (index) => {
      buttonsComponent.value.buttons.splice(index, 1);
  };
  
  const createTemplate = async () => {
      isSubmitting.value = true;
      const components = [bodyComponent.value];
      if (buttonsComponent.value.buttons.length > 0) {
          components.push(buttonsComponent.value);
      }
      const payload = {
          whatsapp_account_id: selectedAccountId.value,
          name: form.value.name,
          language: form.value.language,
          category: form.value.category,
          components: components,
      };
      try {
          await axios.post(route('api.templates.store'), payload);
          alert('Modelo enviado para aprovação!');
          closeModal();
          fetchTemplates();
      } catch (error) {
          const errorMsg = error.response?.data?.message || 'Erro desconhecido.';
          const errorDetails = error.response?.data?.errors ? JSON.stringify(error.response.data.errors, null, 2) : '';
          alert(`Falha ao criar o modelo: ${errorMsg}\n${errorDetails}`);
      } finally {
          isSubmitting.value = false;
      }
  };
  
  const deleteTemplate = async (templateName) => {
      if (!confirm(`Tem certeza que deseja excluir o modelo "${templateName}"? Esta ação não pode ser desfeita.`)) {
          return;
      }
      try {
          await axios.delete(route('api.templates.destroy', templateName), {
              data: { whatsapp_account_id: selectedAccountId.value }
          });
          fetchTemplates();
      } catch (error) {
          alert('Falha ao excluir o modelo.');
          console.error(error);
      }
  };
  
  const getBodyText = (components) => {
      const body = components.find(c => c.type === 'BODY');
      return body ? body.text : 'Corpo não definido.';
  };
  
  const formatStatus = (status) => {
      if (!status) return 'Desconhecido';
      return status.replace('_', ' ').toLowerCase().replace(/\b\w/g, l => l.toUpperCase());
  };
  
  const statusBadgeClass = (status) => {
      switch (status) {
          case 'APPROVED':
              return 'bg-green-100 text-green-800';
          case 'PENDING':
          case 'PENDING_SUBMISSION':
              return 'bg-yellow-100 text-yellow-800';
          case 'REJECTED':
              return 'bg-red-100 text-red-800';
          default:
              return 'bg-gray-100 text-gray-800';
      }
  };
  
  onMounted(() => {
      fetchAccounts();
  });
  </script>
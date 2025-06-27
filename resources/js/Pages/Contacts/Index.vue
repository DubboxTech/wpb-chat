<template>
    <AppLayout>
      <template #header>
        <div class="flex justify-between items-center">
          <h1 class="text-2xl font-semibold text-gray-900">Gerenciar Contatos</h1>
          <button @click="openModal()" class="btn-primary">
            <PlusIcon class="h-5 w-5 mr-2" />
            Novo Contato
          </button>
        </div>
      </template>
  
      <div class="bg-white shadow-sm rounded-lg p-6">
        <div class="mb-4 grid grid-cols-1 md:grid-cols-3 gap-4">
          <input type="text" v-model="filterForm.search" placeholder="Buscar por nome ou telefone..." class="form-input">
          <select v-model="filterForm.tag" class="form-input">
            <option value="">Todos os Segmentos</option>
            <option v-for="segment in segments" :key="segment" :value="segment">{{ segment }}</option>
          </select>
        </div>
  
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Telefone</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Segmentos (Tags)</th>
                <th class="relative px-6 py-3"><span class="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="contact in contacts.data" :key="contact.id">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ contact.name }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ contact.phone_number }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  <span v-for="tag in contact.tags" :key="tag" class="mr-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                    {{ tag }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <button @click="openModal(contact)" class="text-indigo-600 hover:text-indigo-900 mr-4">Editar</button>
                  <button @click="deleteContact(contact)" class="text-red-600 hover:text-red-900">Excluir</button>
                </td>
              </tr>
              <tr v-if="contacts.data.length === 0">
                  <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">Nenhum contato encontrado.</td>
              </tr>
            </tbody>
          </table>
        </div>
        </div>
  
      <div v-if="isModalOpen" class="fixed inset-0 bg-gray-600 bg-opacity-75 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
        <div class="relative mx-auto p-6 border w-full max-w-lg shadow-lg rounded-md bg-white">
          <h3 class="text-lg font-medium text-gray-900 mb-4">{{ form.id ? 'Editar' : 'Novo' }} Contato</h3>
          <form @submit.prevent="saveContact">
            <div class="space-y-4">
              <div>
                <label for="name" class="form-label">Nome</label>
                <input id="name" type="text" v-model="form.name" class="form-input" required>
                <div v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</div>
              </div>
              <div>
                <label for="phone_number" class="form-label">Telefone</label>
                <input id="phone_number" type="text" v-model="form.phone_number" class="form-input" required>
                 <div v-if="form.errors.phone_number" class="text-red-500 text-xs mt-1">{{ form.errors.phone_number }}</div>
              </div>
              <div>
                <label for="tags" class="form-label">Segmentos (Tags)</label>
                <input id="tags" type="text" v-model="tagsInput" @keydown.enter.prevent="addTag" class="form-input" placeholder="Digite uma tag e pressione Enter">
                <div class="mt-2 flex flex-wrap gap-2">
                  <span v-for="(tag, index) in form.tags" :key="index" class="flex items-center bg-blue-100 text-blue-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
                    {{ tag }}
                    <button @click="removeTag(index)" type="button" class="ml-1.5 flex-shrink-0 text-blue-500 hover:text-blue-700">
                      <XMarkIcon class="h-3 w-3" />
                    </button>
                  </span>
                </div>
              </div>
  
              <div class="space-y-3 pt-4 border-t">
                   <label class="form-label">Campos Personalizados</label>
                   <div v-for="(field, index) in form.custom_fields" :key="index" class="flex items-center space-x-2">
                       <input type="text" v-model="field.key" placeholder="Chave (ex: cep)" class="form-input text-sm w-1/3">
                       <input type="text" v-model="field.value" placeholder="Valor (ex: 71503-505)" class="form-input text-sm flex-grow">
                       <button @click="removeCustomField(index)" type="button" class="text-red-500 hover:text-red-700 p-2">
                          <XMarkIcon class="h-5 w-5" />
                       </button>
                   </div>
                   <button @click="addCustomField" type="button" class="text-sm font-medium text-green-600 hover:text-green-700">+ Adicionar Campo</button>
              </div>
  
            </div>
            <div class="mt-6 flex justify-end space-x-3">
              <button type="button" @click="isModalOpen = false" class="btn-secondary">Cancelar</button>
              <button type="submit" :disabled="form.processing" class="btn-primary">
                {{ form.processing ? 'Salvando...' : 'Salvar' }}
              </button>
            </div>
          </form>
        </div>
      </div>
  
    </AppLayout>
  </template>
  
  <script setup>
  import { ref, watch } from 'vue';
  import { useForm, router } from '@inertiajs/vue3';
  import { debounce } from 'lodash';
  import AppLayout from '@/Layouts/AppLayout.vue';
  import { PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline';
  
  const props = defineProps({
    contacts: Object,
    segments: Array,
    filters: Object,
  });
  
  const isModalOpen = ref(false);
  const tagsInput = ref('');
  
  const filterForm = ref({
      search: props.filters.search || '',
      tag: props.filters.tag || '',
  });
  
  const form = useForm({
      id: null,
      name: '',
      phone_number: '',
      tags: [],
      custom_fields: [], // Inicializado como array
  });
  
  watch(filterForm, debounce(() => {
      router.get(route('contacts.index'), filterForm.value, { 
          preserveState: true, 
          replace: true 
      });
  }, 300), { deep: true });
  
  
  const openModal = (contact = null) => {
    form.reset();
    form.clearErrors();
    if (contact) {
      form.id = contact.id;
      form.name = contact.name;
      form.phone_number = contact.phone_number;
      form.tags = contact.tags ? [...contact.tags] : [];
      // Transforma o objeto de custom_fields em um array para o formulário
      form.custom_fields = contact.custom_fields ? Object.entries(contact.custom_fields).map(([key, value]) => ({ key, value })) : [];
    } else {
      form.custom_fields = [];
    }
    isModalOpen.value = true;
  };
  
  const addTag = () => {
    if (tagsInput.value.trim() !== '' && !form.tags.includes(tagsInput.value.trim())) {
      form.tags.push(tagsInput.value.trim());
    }
    tagsInput.value = '';
  };
  
  const removeTag = (index) => {
    form.tags.splice(index, 1);
  };
  
  // Funções para campos personalizados
  const addCustomField = () => {
      form.custom_fields.push({ key: '', value: '' });
  };
  
  const removeCustomField = (index) => {
      form.custom_fields.splice(index, 1);
  };
  
  
  const saveContact = () => {
      const options = {
          onSuccess: () => {
              isModalOpen.value = false;
              form.reset();
          },
      };
  
      if (form.id) {
          form.put(route('contacts.update', form.id), options);
      } else {
          form.post(route('contacts.store'), options);
      }
  };
  
  const deleteContact = (contact) => {
    if (confirm(`Tem certeza que deseja excluir o contato ${contact.name}?`)) {
      router.delete(route('contacts.destroy', contact.id), {
          preserveScroll: true,
      });
    }
  };
  
  </script>
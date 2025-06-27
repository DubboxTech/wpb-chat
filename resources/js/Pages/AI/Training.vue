<template>
  <AppLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h1 class="text-2xl font-semibold text-gray-900">IA - Treinamento</h1>
        <div class="flex space-x-3">
          <button 
            @click="showAddModal = true"
            class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md text-sm font-medium"
          >
            Adicionar Treinamento
          </button>
        </div>
      </div>
    </template>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
      <div class="bg-white overflow-hidden shadow rounded-lg">
        <div class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <CpuChipIcon class="h-6 w-6 text-purple-400" />
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Dados de Treinamento</dt>
                <dd class="text-lg font-medium text-gray-900">{{ stats.trainingData }}</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white overflow-hidden shadow rounded-lg">
        <div class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <CheckCircleIcon class="h-6 w-6 text-green-400" />
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Taxa de Sucesso</dt>
                <dd class="text-lg font-medium text-gray-900">{{ stats.successRate }}%</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white overflow-hidden shadow rounded-lg">
        <div class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <ChatBubbleLeftIcon class="h-6 w-6 text-blue-400" />
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Respostas IA Hoje</dt>
                <dd class="text-lg font-medium text-gray-900">{{ stats.aiResponsesToday }}</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white overflow-hidden shadow rounded-lg">
        <div class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <UserIcon class="h-6 w-6 text-orange-400" />
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Fallback Humano</dt>
                <dd class="text-lg font-medium text-gray-900">{{ stats.humanFallback }}</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters and Search -->
    <div class="mb-6 flex flex-wrap gap-4">
      <div class="flex-1 min-w-64">
        <div class="relative">
          <MagnifyingGlassIcon class="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
          <input
            type="text"
            placeholder="Buscar perguntas ou respostas..."
            class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500"
            v-model="searchQuery"
          />
        </div>
      </div>
      
      <select v-model="statusFilter" class="border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500">
        <option value="">Todos os status</option>
        <option value="active">Ativo</option>
        <option value="inactive">Inativo</option>
        <option value="pending">Pendente</option>
      </select>
      
      <select v-model="categoryFilter" class="border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500">
        <option value="">Todas as categorias</option>
        <option value="support">Suporte</option>
        <option value="sales">Vendas</option>
        <option value="general">Geral</option>
        <option value="technical">Técnico</option>
      </select>
    </div>

    <!-- Training Data Table -->
    <div class="bg-white shadow overflow-hidden sm:rounded-md">
      <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
        <h3 class="text-lg leading-6 font-medium text-gray-900">Dados de Treinamento</h3>
        <p class="mt-1 max-w-2xl text-sm text-gray-500">
          Perguntas e respostas para treinar a IA do sistema
        </p>
      </div>
      
      <ul class="divide-y divide-gray-200">
        <li v-for="item in filteredTrainingData" :key="item.id" class="px-4 py-4 hover:bg-gray-50">
          <div class="flex items-start justify-between">
            <div class="flex-1 min-w-0">
              <div class="flex items-center space-x-3 mb-2">
                <span
                  :class="[
                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                    getStatusColor(item.status)
                  ]"
                >
                  {{ getStatusText(item.status) }}
                </span>
                <span
                  :class="[
                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                    getCategoryColor(item.category)
                  ]"
                >
                  {{ getCategoryText(item.category) }}
                </span>
              </div>
              
              <div class="mb-3">
                <h4 class="text-sm font-medium text-gray-900 mb-1">Pergunta:</h4>
                <p class="text-sm text-gray-700">{{ item.question }}</p>
              </div>
              
              <div class="mb-3">
                <h4 class="text-sm font-medium text-gray-900 mb-1">Resposta:</h4>
                <p class="text-sm text-gray-700">{{ item.answer }}</p>
              </div>
              
              <div class="flex items-center text-xs text-gray-500 space-x-4">
                <span>Confiança: {{ item.confidence }}%</span>
                <span>Usado {{ item.usage_count }} vezes</span>
                <span>Criado em {{ item.created_at }}</span>
              </div>
            </div>
            
            <div class="flex items-center space-x-2 ml-4">
              <button
                @click="editTrainingData(item)"
                class="text-blue-600 hover:text-blue-700 text-sm font-medium"
              >
                Editar
              </button>
              <button
                @click="toggleStatus(item)"
                :class="[
                  'text-sm font-medium',
                  item.status === 'active' 
                    ? 'text-yellow-600 hover:text-yellow-700' 
                    : 'text-green-600 hover:text-green-700'
                ]"
              >
                {{ item.status === 'active' ? 'Desativar' : 'Ativar' }}
              </button>
              <button
                @click="deleteTrainingData(item)"
                class="text-red-600 hover:text-red-700 text-sm font-medium"
              >
                Excluir
              </button>
            </div>
          </div>
        </li>
      </ul>
      
      <!-- Empty State -->
      <div v-if="filteredTrainingData.length === 0" class="text-center py-12">
        <CpuChipIcon class="h-12 w-12 text-gray-400 mx-auto mb-4" />
        <h3 class="text-lg font-medium text-gray-900 mb-2">Nenhum dado de treinamento encontrado</h3>
        <p class="text-gray-500 mb-4">Comece adicionando perguntas e respostas para treinar a IA</p>
        <button
          @click="showAddModal = true"
          class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md text-sm font-medium"
        >
          Adicionar Primeiro Treinamento
        </button>
      </div>
    </div>

    <!-- Add/Edit Training Data Modal -->
    <div v-if="showAddModal || editingItem" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">
              {{ editingItem ? 'Editar' : 'Adicionar' }} Dados de Treinamento
            </h3>
            <button @click="closeModal" class="text-gray-400 hover:text-gray-600">
              <XMarkIcon class="h-6 w-6" />
            </button>
          </div>
          
          <form @submit.prevent="saveTrainingData" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Categoria</label>
              <select
                v-model="formData.category"
                required
                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:ring-purple-500 focus:border-purple-500"
              >
                <option value="">Selecione uma categoria</option>
                <option value="support">Suporte</option>
                <option value="sales">Vendas</option>
                <option value="general">Geral</option>
                <option value="technical">Técnico</option>
              </select>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Pergunta</label>
              <textarea
                v-model="formData.question"
                required
                rows="3"
                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:ring-purple-500 focus:border-purple-500"
                placeholder="Digite a pergunta que os clientes podem fazer..."
              ></textarea>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Resposta</label>
              <textarea
                v-model="formData.answer"
                required
                rows="4"
                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:ring-purple-500 focus:border-purple-500"
                placeholder="Digite a resposta que a IA deve dar..."
              ></textarea>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Nível de Confiança ({{ formData.confidence }}%)
              </label>
              <input
                v-model="formData.confidence"
                type="range"
                min="50"
                max="100"
                class="w-full"
              />
              <div class="flex justify-between text-xs text-gray-500 mt-1">
                <span>50% (Baixa)</span>
                <span>100% (Alta)</span>
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Palavras-chave (separadas por vírgula)</label>
              <input
                v-model="formData.keywords"
                type="text"
                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:ring-purple-500 focus:border-purple-500"
                placeholder="horário, funcionamento, preço, desconto..."
              />
            </div>
            
            <div class="flex items-center">
              <input
                v-model="formData.is_active"
                type="checkbox"
                class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded"
              />
              <label class="ml-2 block text-sm text-gray-900">
                Ativar imediatamente
              </label>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
              <button
                type="button"
                @click="closeModal"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-md"
              >
                {{ editingItem ? 'Salvar' : 'Adicionar' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import {
  CpuChipIcon,
  CheckCircleIcon,
  ChatBubbleLeftIcon,
  UserIcon,
  MagnifyingGlassIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

const searchQuery = ref('')
const statusFilter = ref('')
const categoryFilter = ref('')
const showAddModal = ref(false)
const editingItem = ref(null)

const stats = ref({
  trainingData: 45,
  successRate: 87,
  aiResponsesToday: 156,
  humanFallback: 23
})

const formData = ref({
  category: '',
  question: '',
  answer: '',
  confidence: 80,
  keywords: '',
  is_active: true
})

// Mock training data
const trainingData = ref([
  {
    id: 1,
    category: 'support',
    question: 'Qual o horário de funcionamento?',
    answer: 'Nosso horário de funcionamento é de segunda a sexta das 8h às 18h, e sábados das 8h às 12h.',
    confidence: 95,
    status: 'active',
    usage_count: 45,
    created_at: '15/11/2024'
  },
  {
    id: 2,
    category: 'sales',
    question: 'Vocês fazem desconto para compras em quantidade?',
    answer: 'Sim! Oferecemos descontos progressivos a partir de 10 unidades. Entre em contato para uma cotação personalizada.',
    confidence: 90,
    status: 'active',
    usage_count: 32,
    created_at: '14/11/2024'
  },
  {
    id: 3,
    category: 'technical',
    question: 'Como faço para resetar minha senha?',
    answer: 'Para resetar sua senha, clique em "Esqueci minha senha" na tela de login e siga as instruções enviadas por email.',
    confidence: 85,
    status: 'active',
    usage_count: 28,
    created_at: '13/11/2024'
  }
])

const filteredTrainingData = computed(() => {
  let filtered = trainingData.value
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(item => 
      item.question.toLowerCase().includes(query) ||
      item.answer.toLowerCase().includes(query)
    )
  }
  
  if (statusFilter.value) {
    filtered = filtered.filter(item => item.status === statusFilter.value)
  }
  
  if (categoryFilter.value) {
    filtered = filtered.filter(item => item.category === categoryFilter.value)
  }
  
  return filtered
})

const getStatusColor = (status) => {
  const colors = {
    active: 'bg-green-100 text-green-800',
    inactive: 'bg-gray-100 text-gray-800',
    pending: 'bg-yellow-100 text-yellow-800'
  }
  return colors[status] || 'bg-gray-100 text-gray-800'
}

const getStatusText = (status) => {
  const texts = {
    active: 'Ativo',
    inactive: 'Inativo',
    pending: 'Pendente'
  }
  return texts[status] || status
}

const getCategoryColor = (category) => {
  const colors = {
    support: 'bg-blue-100 text-blue-800',
    sales: 'bg-green-100 text-green-800',
    general: 'bg-purple-100 text-purple-800',
    technical: 'bg-orange-100 text-orange-800'
  }
  return colors[category] || 'bg-gray-100 text-gray-800'
}

const getCategoryText = (category) => {
  const texts = {
    support: 'Suporte',
    sales: 'Vendas',
    general: 'Geral',
    technical: 'Técnico'
  }
  return texts[category] || category
}

const editTrainingData = (item) => {
  editingItem.value = item
  formData.value = {
    category: item.category,
    question: item.question,
    answer: item.answer,
    confidence: item.confidence,
    keywords: '', // Would come from API
    is_active: item.status === 'active'
  }
}

const closeModal = () => {
  showAddModal.value = false
  editingItem.value = null
  formData.value = {
    category: '',
    question: '',
    answer: '',
    confidence: 80,
    keywords: '',
    is_active: true
  }
}

const saveTrainingData = () => {
  if (editingItem.value) {
    // Update existing item
    const index = trainingData.value.findIndex(item => item.id === editingItem.value.id)
    if (index > -1) {
      trainingData.value[index] = {
        ...trainingData.value[index],
        category: formData.value.category,
        question: formData.value.question,
        answer: formData.value.answer,
        confidence: formData.value.confidence,
        status: formData.value.is_active ? 'active' : 'inactive'
      }
    }
  } else {
    // Add new item
    const newItem = {
      id: Date.now(),
      category: formData.value.category,
      question: formData.value.question,
      answer: formData.value.answer,
      confidence: formData.value.confidence,
      status: formData.value.is_active ? 'active' : 'inactive',
      usage_count: 0,
      created_at: new Date().toLocaleDateString('pt-BR')
    }
    trainingData.value.unshift(newItem)
  }
  
  closeModal()
}

const toggleStatus = (item) => {
  item.status = item.status === 'active' ? 'inactive' : 'active'
}

const deleteTrainingData = (item) => {
  if (confirm('Tem certeza que deseja excluir este dado de treinamento?')) {
    const index = trainingData.value.findIndex(i => i.id === item.id)
    if (index > -1) {
      trainingData.value.splice(index, 1)
    }
  }
}
</script>


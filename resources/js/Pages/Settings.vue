<template>
  <AppLayout>
    <template #header>
      <h1 class="text-2xl font-semibold text-gray-900">Configurações</h1>
    </template>

    <div class="max-w-4xl mx-auto space-y-8">
      <!-- WhatsApp Business API -->
      <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">WhatsApp Business API</h3>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number ID</label>
            <input v-model="whatsappConfig.phoneNumberId" type="text" class="w-full border border-gray-300 rounded-md px-3 py-2" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Access Token</label>
            <input v-model="whatsappConfig.accessToken" type="password" class="w-full border border-gray-300 rounded-md px-3 py-2" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Webhook Verify Token</label>
            <input v-model="whatsappConfig.webhookToken" type="text" class="w-full border border-gray-300 rounded-md px-3 py-2" />
          </div>
          <button @click="saveWhatsAppConfig" class="bg-green-600 text-white px-4 py-2 rounded-md">Salvar</button>
        </div>
      </div>

      <!-- Gemini AI -->
      <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Gemini AI</h3>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
            <input v-model="geminiConfig.apiKey" type="password" class="w-full border border-gray-300 rounded-md px-3 py-2" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Modelo</label>
            <select v-model="geminiConfig.model" class="w-full border border-gray-300 rounded-md px-3 py-2">
              <option value="gemini-pro">Gemini Pro</option>
              <option value="gemini-pro-vision">Gemini Pro Vision</option>
            </select>
          </div>
          <button @click="saveGeminiConfig" class="bg-purple-600 text-white px-4 py-2 rounded-md">Salvar</button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const whatsappConfig = ref({
  phoneNumberId: '',
  accessToken: '',
  webhookToken: ''
})

const geminiConfig = ref({
  apiKey: '',
  model: 'gemini-pro'
})

const saveWhatsAppConfig = () => {
  // API call to save WhatsApp config
  alert('Configurações do WhatsApp salvas!')
}

const saveGeminiConfig = () => {
  // API call to save Gemini config
  alert('Configurações do Gemini salvas!')
}
</script>


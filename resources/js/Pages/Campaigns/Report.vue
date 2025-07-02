<script setup>
import { ref, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement, BarElement, CategoryScale, LinearScale, PointElement, LineElement } from 'chart.js';
import { Doughnut, Bar } from 'vue-chartjs';
import axios from 'axios';
import {
  PaperAirplaneIcon,
  CheckCircleIcon,
  EyeIcon,
  ExclamationTriangleIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline';

ChartJS.register(Title, Tooltip, Legend, ArcElement, BarElement, CategoryScale, LinearScale, PointElement, LineElement);

const props = defineProps({
  campaign: Object,
});

const reportData = ref(null);
const campaignContacts = ref({ data: [], links: [] });
const isLoading = ref(true);

const doughnutChartData = ref({ labels: [], datasets: [] });
const timelineChartData = ref({ labels: [], datasets: [] });

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
};

// Função para buscar dados do relatório, incluindo a página de contatos
const fetchReportData = async (url = route('api.campaigns.report', props.campaign.id)) => {
  isLoading.value = true;
  try {
    const response = await axios.get(url);
    if (response.data.success) {
      reportData.value = response.data.report;
      campaignContacts.value = response.data.report.contacts;
      
      doughnutChartData.value = {
        labels: response.data.report.chart_status.labels,
        datasets: [{ backgroundColor: ['#34D399', '#8B5CF6', '#F87171', '#9CA3AF'], data: response.data.report.chart_status.data }],
      };

      timelineChartData.value = {
        labels: response.data.report.chart_read_timeline.labels.map(date => new Date(date + 'T00:00:00').toLocaleDateString('pt-BR')),
        datasets: [{ label: 'Mensagens Lidas', backgroundColor: '#16A34A', data: response.data.report.chart_read_timeline.data }],
      };
    }
  } catch (error) {
    console.error("Falha ao carregar dados do relatório:", error);
  } finally {
    isLoading.value = false;
  }
};

const getStatusClass = (status) => ({
  'sent': 'bg-blue-100 text-blue-800',
  'delivered': 'bg-green-100 text-green-800',
  'read': 'bg-purple-100 text-purple-800',
  'failed': 'bg-red-100 text-red-800',
  'pending': 'bg-gray-100 text-gray-800'
}[status] || 'bg-gray-100');

const getStatusText = (status) => ({
  'sent': 'Enviado',
  'delivered': 'Entregue',
  'read': 'Lido',
  'failed': 'Falhou',
  'pending': 'Pendente'
}[status] || 'Desconhecido');

onMounted(() => {
  fetchReportData();
});
</script>

<template>
  <AppLayout>
    <template #header>
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div>
              <h1 class="text-2xl font-semibold text-gray-900">Relatório da Campanha</h1>
              <p class="mt-1 text-sm text-gray-600">Análise de desempenho para: <span class="font-medium text-gray-800">{{ campaign.name }}</span></p>
            </div>
            <Link :href="route('campaigns')" class="mt-4 md:mt-0 text-sm text-blue-600 hover:text-blue-800">&larr; Voltar para Campanhas</Link>
        </div>
    </template>

    <div v-if="isLoading" class="text-center py-20"><p>A carregar relatório...</p></div>

    <div v-else-if="reportData" class="space-y-8">
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6">
        <div class="bg-white overflow-hidden shadow rounded-lg p-5"><dt class="text-sm font-medium text-gray-500 truncate flex items-center"><UserGroupIcon class="h-5 w-5 mr-2 text-gray-400"/>Total</dt><dd class="text-3xl font-bold text-gray-900 mt-1">{{ reportData.total_contacts }}</dd></div>
        <div class="bg-white overflow-hidden shadow rounded-lg p-5"><dt class="text-sm font-medium text-gray-500 truncate flex items-center"><PaperAirplaneIcon class="h-5 w-5 mr-2 text-blue-400"/>Enviadas</dt><dd class="text-3xl font-bold text-gray-900 mt-1">{{ reportData.sent_count }}</dd></div>
        <div class="bg-white overflow-hidden shadow rounded-lg p-5"><dt class="text-sm font-medium text-gray-500 truncate flex items-center"><CheckCircleIcon class="h-5 w-5 mr-2 text-green-500"/>Entregues</dt><dd class="text-3xl font-bold text-gray-900 mt-1">{{ reportData.delivered_count }}</dd></div>
        <div class="bg-white overflow-hidden shadow rounded-lg p-5"><dt class="text-sm font-medium text-gray-500 truncate flex items-center"><EyeIcon class="h-5 w-5 mr-2 text-purple-500"/>Lidas</dt><dd class="text-3xl font-bold text-gray-900 mt-1">{{ reportData.read_count }}</dd></div>
        <div class="bg-white overflow-hidden shadow rounded-lg p-5"><dt class="text-sm font-medium text-gray-500 truncate flex items-center"><ExclamationTriangleIcon class="h-5 w-5 mr-2 text-red-500"/>Falhas</dt><dd class="text-3xl font-bold text-gray-900 mt-1">{{ reportData.failed_count }}</dd></div>
      </div>
      
      <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">
        <div class="lg:col-span-2 bg-white shadow rounded-lg p-6 h-96 flex items-center justify-center">
          <Doughnut v-if="reportData.chart_status.data.some(d => d > 0)" :data="doughnutChartData" :options="{ ...chartOptions, plugins: { ...chartOptions.plugins, title: { display: true, text: 'Distribuição de Status' } } }" />
          <p v-else class="text-gray-500">Sem dados para o gráfico de status.</p>
        </div>
        <div class="lg:col-span-3 bg-white shadow rounded-lg p-6 h-96 flex items-center justify-center">
           <Bar v-if="reportData.chart_read_timeline.data.length > 0" :data="timelineChartData" :options="{ ...chartOptions, plugins: { ...chartOptions.plugins, title: { display: true, text: 'Mensagens Lidas por Dia' } } }" />
           <p v-else class="text-gray-500">Nenhuma mensagem lida para exibir na linha do tempo.</p>
        </div>
      </div>

      <div class="bg-white shadow rounded-lg overflow-hidden">
          <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-medium text-gray-900">Detalhes de Envio por Contato</h3>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Telefone</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lido em</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-if="campaignContacts.data.length === 0">
                    <td colspan="4" class="text-center py-10 text-gray-500">Nenhum contato para exibir.</td>
                </tr>
                <tr v-for="item in campaignContacts.data" :key="item.id">
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">{{ item.contact.name }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.contact.phone_number }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', getStatusClass(item.status)]">
                      {{ getStatusText(item.status) }}
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {{ item.read_at ? new Date(item.read_at).toLocaleString('pt-BR') : '---' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="campaignContacts.links && campaignContacts.links.length > 3" class="px-6 py-3 bg-gray-50 border-t flex justify-between items-center text-sm">
             <p class="text-gray-600">A exibir {{ campaignContacts.from }} a {{ campaignContacts.to }} de {{ campaignContacts.total }} resultados</p>
             <div class="flex items-center space-x-1">
                <button v-for="(link, index) in campaignContacts.links" :key="index" @click.prevent="fetchReportData(link.url)" :disabled="!link.url" v-html="link.label" class="px-3 py-1 rounded-md" :class="{'bg-green-600 text-white': link.active, 'hover:bg-gray-200': link.url && !link.active, 'text-gray-400': !link.url}"></button>
             </div>
          </div>
        </div>
    </div>
  </AppLayout>
</template>
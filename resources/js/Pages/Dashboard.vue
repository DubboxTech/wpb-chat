<script setup>
import { ref, onMounted, computed } from 'vue'; // ADICIONADO 'computed' AQUI
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement } from 'chart.js';
import { Bar, Doughnut } from 'vue-chartjs';
import {
  ChatBubbleLeftRightIcon,
  MegaphoneIcon,
  PaperAirplaneIcon,
  CpuChipIcon,
  UserGroupIcon,
  UsersIcon,
} from '@heroicons/vue/24/outline';
import "leaflet/dist/leaflet.css";
import L from "leaflet";

// Registra os componentes do Chart.js
ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement);

// Props recebidas do DashboardController
const props = defineProps({
  stats: Object,
  chartData: Object,
  mapData: Array,
  recentActivity: Object,
});

const mapContainer = ref(null);
let mapInstance = null;

// Configuração para o gráfico de barras
const barChartOptions = ref({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    title: { display: true, text: 'Mensagens nas últimas 24 horas' }
  },
  scales: {
    y: { beginAtZero: true }
  }
});

// Configuração para o gráfico de pizza (Doughnut)
const doughnutChartOptions = ref({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'right' },
    title: { display: true, text: 'Status das Conversas' }
  }
});

const doughnutChartData = computed(() => ({
  labels: props.chartData.conversationStatus.labels,
  datasets: [{
    backgroundColor: ['#22c55e', '#facc15', '#8b5cf6', '#6b7280'],
    data: props.chartData.conversationStatus.data,
  }]
}));

// Monta o mapa Leaflet quando o componente é montado
onMounted(() => {
  if (mapContainer.value && props.mapData.length > 0) {
    // Coordenadas centrais (Brasília)
    const centerLat = -15.7942;
    const centerLng = -47.8825;

    mapInstance = L.map(mapContainer.value).setView([centerLat, centerLng], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(mapInstance);

    // Adiciona os marcadores (pins) para cada contato com CEP
    props.mapData.forEach(contact => {
      L.marker([contact.lat, contact.lng])
        .addTo(mapInstance)
        .bindPopup(`<b>${contact.name}</b><br>${contact.cep}`);
    });
  }
});

</script>

<template>
  <AppLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h1 class="text-2xl font-semibold text-gray-900">Dashboard Geral</h1>
        <div class="flex space-x-3">
          <Link :href="route('campaigns')" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium flex items-center shadow-sm">
            <MegaphoneIcon class="h-5 w-5 mr-2" />
            Nova Campanha
          </Link>
        </div>
      </div>
    </template>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <div class="bg-white overflow-hidden shadow rounded-lg p-5 flex items-center space-x-4">
        <div class="flex-shrink-0 bg-blue-500 p-3 rounded-full">
          <ChatBubbleLeftRightIcon class="h-6 w-6 text-white" />
        </div>
        <div>
          <dt class="text-sm font-medium text-gray-500 truncate">Conversas Ativas</dt>
          <dd class="text-2xl font-bold text-gray-900">{{ stats.activeConversations }}</dd>
        </div>
      </div>
      <div class="bg-white overflow-hidden shadow rounded-lg p-5 flex items-center space-x-4">
        <div class="flex-shrink-0 bg-green-500 p-3 rounded-full">
          <MegaphoneIcon class="h-6 w-6 text-white" />
        </div>
        <div>
          <dt class="text-sm font-medium text-gray-500 truncate">Campanhas Ativas</dt>
          <dd class="text-2xl font-bold text-gray-900">{{ stats.activeCampaigns }}</dd>
        </div>
      </div>
      <div class="bg-white overflow-hidden shadow rounded-lg p-5 flex items-center space-x-4">
        <div class="flex-shrink-0 bg-yellow-500 p-3 rounded-full">
          <PaperAirplaneIcon class="h-6 w-6 text-white" />
        </div>
        <div>
          <dt class="text-sm font-medium text-gray-500 truncate">Mensagens Hoje</dt>
          <dd class="text-2xl font-bold text-gray-900">{{ stats.messagesToday }}</dd>
        </div>
      </div>
      <div class="bg-white overflow-hidden shadow rounded-lg p-5 flex items-center space-x-4">
        <div class="flex-shrink-0 bg-purple-500 p-3 rounded-full">
          <CpuChipIcon class="h-6 w-6 text-white" />
        </div>
        <div>
          <dt class="text-sm font-medium text-gray-500 truncate">Resolução por IA</dt>
          <dd class="text-2xl font-bold text-gray-900">{{ stats.aiResolutionRate }}%</dd>
        </div>
      </div>
    </div>

    <!-- Charts e Mapa -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mb-8">
      <!-- Gráficos -->
      <div class="lg:col-span-3 grid grid-cols-1 gap-6">
        <div class="bg-white shadow rounded-lg p-6 h-80">
          <Bar :data="{ labels: chartData.messagesByHour.labels, datasets: [{ backgroundColor: '#16a34a', data: chartData.messagesByHour.data }] }" :options="barChartOptions" />
        </div>
        <div class="bg-white shadow rounded-lg p-6 h-80">
          <Doughnut :data="doughnutChartData" :options="doughnutChartOptions" />
        </div>
      </div>
      <!-- Mapa -->
      <div class="lg:col-span-2 bg-white shadow rounded-lg p-6 flex flex-col h-[664px]">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Contatos por Localização (CEP)</h3>
        <div ref="mapContainer" class="flex-grow rounded-md">
           <div v-if="!mapData.length" class="flex items-center justify-center h-full bg-gray-50 rounded-md">
               <p class="text-gray-500">Nenhum contato com CEP cadastrado.</p>
           </div>
        </div>
      </div>
    </div>

    <!-- Atividades Recentes -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
       <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
          <h3 class="text-lg font-medium text-gray-900">Conversas Recentes</h3>
          <Link :href="route('conversations')" class="text-sm font-medium text-green-600 hover:text-green-700">Ver todas</Link>
        </div>
        <ul role="list" class="divide-y divide-gray-200">
            <li v-for="conversation in recentActivity.conversations" :key="conversation.id" class="px-6 py-4 hover:bg-gray-50 flex items-center space-x-4">
                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center"><UsersIcon class="h-6 w-6 text-gray-500"/></div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">{{ conversation.contact.name }}</p>
                    <p class="text-sm text-gray-500 truncate">{{ conversation.lastMessage }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-gray-500">{{ conversation.time }}</p>
                    <span v-if="conversation.unread" class="mt-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-red-500 text-white">{{ conversation.unread }}</span>
                </div>
            </li>
        </ul>
      </div>
      <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
          <h3 class="text-lg font-medium text-gray-900">Campanhas Recentes</h3>
           <Link :href="route('api.campaigns.index')" class="text-sm font-medium text-green-600 hover:text-green-700">Ver todas</Link>
        </div>
         <ul role="list" class="divide-y divide-gray-200">
            <li v-for="campaign in recentActivity.campaigns" :key="campaign.id" class="px-6 py-4 hover:bg-gray-50">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-sm font-medium text-gray-900">{{ campaign.name }}</p>
                  <p class="text-sm text-gray-500">{{ campaign.contacts }} contatos</p>
                </div>
                <div class="text-right">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">{{ campaign.status }}</span>
                  <p class="text-xs text-gray-500 mt-1">{{ campaign.progress }}% concluído</p>
                </div>
              </div>
            </li>
        </ul>
      </div>
    </div>
  </AppLayout>
</template>

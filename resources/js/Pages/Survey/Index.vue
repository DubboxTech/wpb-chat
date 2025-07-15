<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement, BarElement, CategoryScale, LinearScale } from 'chart.js';
import { Doughnut, Bar } from 'vue-chartjs';
import { debounce } from 'lodash';
import "leaflet/dist/leaflet.css";
import L from "leaflet";
import 'leaflet.heat';

// Ícones
import { PresentationChartBarIcon, StarIcon, UsersIcon, MapIcon, CakeIcon, AcademicCapIcon, ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline';

ChartJS.register(Title, Tooltip, Legend, ArcElement, BarElement, CategoryScale, LinearScale);

const props = defineProps({
  surveys: Object,
  stats: Object,
  filters: Object,
  allRestaurants: Array,
  heatmapData: Array,
  demographicStats: Object,
});

const filterForm = ref({
  restaurant: props.filters.restaurant || '',
  search: props.filters.search || '',
});

watch(filterForm, debounce(() => {
  router.get(route('surveys.index'), filterForm.value, {
    preserveState: true,
    replace: true,
  });
}, 300), { deep: true });

// Gráficos de Satisfação
const ratingChartData = computed(() => ({
  labels: props.stats.ratingsDistribution.labels,
  datasets: [{ backgroundColor: ['#10b981', '#3b82f6', '#8b5cf6', '#f97316', '#ef4444'], data: props.stats.ratingsDistribution.data }],
}));

const volumeChartData = computed(() => ({
  labels: props.stats.surveysByDay.labels.map(date => new Date(date + 'T00:00:00').toLocaleDateString('pt-BR')),
  datasets: [{ label: 'Pesquisas por Dia', backgroundColor: '#16a34a', data: props.stats.surveysByDay.data }],
}));

// Gráficos Demográficos
const ageChartData = computed(() => ({
  labels: props.demographicStats.ageDistribution.labels,
  datasets: [{ backgroundColor: ['#0ea5e9', '#14b8a6', '#84cc16', '#f97316', '#ef4444'], data: props.demographicStats.ageDistribution.data }],
}));

const benefitsChartData = computed(() => props.demographicStats.benefitsByRestaurant);

// Gráfico CadÚnico
const cadunicoChartData = computed(() => ({
  labels: props.demographicStats.cadunicoDistribution.labels,
  datasets: [{ backgroundColor: ['#14b8a6', '#94a3b8'], data: props.demographicStats.cadunicoDistribution.data }],
}));

// Opções dos gráficos
const doughnutOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } };
const barOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };
const groupedBarOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { x: { stacked: false }, y: { stacked: false } } };

// Lógica do Mapa de Calor e WebSocket
const mapContainer = ref(null);
let mapInstance = null;

onMounted(() => {
  nextTick(() => {
    if (mapContainer.value && !mapInstance) {
      mapInstance = L.map(mapContainer.value).setView([-15.7942, -47.8825], 10);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(mapInstance);
      
      if (props.heatmapData.length > 0) {
        L.heatLayer(props.heatmapData, { radius: 25, blur: 15, maxZoom: 12 }).addTo(mapInstance);
      }
    }
  });

  if (window.Echo) {
    window.Echo.private('surveys.index')
      .listen('.survey.submitted', () => {
        router.reload({ preserveScroll: true });
      });
  }
});

onUnmounted(() => {
  if (window.Echo) {
    window.Echo.leave('surveys.index');
  }
});
</script>

<template>
  <AppLayout>
    <template #header>
      <h1 class="text-2xl font-semibold text-gray-900">Análise de Pesquisas de Satisfação</h1>
    </template>

    <div class="space-y-8">
      <div class="bg-white p-4 rounded-lg shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <input type="text" v-model="filterForm.search" placeholder="Buscar por nome do usuário..." class="form-input w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
          <select v-model="filterForm.restaurant" class="form-select w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
            <option value="">Todos os Restaurantes</option>
            <option v-for="restaurant in allRestaurants" :key="restaurant" :value="restaurant">{{ restaurant }}</option>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <div class="bg-white p-5 rounded-lg shadow flex items-center space-x-4"><div class="bg-blue-100 p-3 rounded-full"><UsersIcon class="h-6 w-6 text-blue-600" /></div><div><dt class="text-sm font-medium text-gray-500 truncate">Total de Respostas</dt><dd class="text-2xl font-bold text-gray-900">{{ stats.totalSurveys }}</dd></div></div>
          <div class="bg-white p-5 rounded-lg shadow flex items-center space-x-4"><div class="bg-yellow-100 p-3 rounded-full"><StarIcon class="h-6 w-6 text-yellow-600" /></div><div><dt class="text-sm font-medium text-gray-500 truncate">Avaliação Média</dt><dd class="text-2xl font-bold text-gray-900">{{ stats.averageRating }}</dd></div></div>
          <div class="bg-white p-5 rounded-lg shadow flex items-center space-x-4"><div class="bg-teal-100 p-3 rounded-full"><CakeIcon class="h-6 w-6 text-teal-600" /></div><div><dt class="text-sm font-medium text-gray-500 truncate">Idade Média </dt><dd class="text-2xl font-bold text-gray-900">43</dd></div></div>
          <div class="bg-white p-5 rounded-lg shadow flex items-center space-x-4"><div class="bg-fuchsia-100 p-3 rounded-full"><ClipboardDocumentCheckIcon class="h-6 w-6 text-fuchsia-600" /></div><div><dt class="text-sm font-medium text-gray-500 truncate">Beneficiários CadÚnico</dt><dd class="text-2xl font-bold text-gray-900">{{ demographicStats.cadunicoDistribution.data[0] }}%</dd></div></div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 bg-white p-6 rounded-lg shadow h-96 flex flex-col"><h3 class="text-lg font-medium text-gray-800 mb-4">Faixa Etária </h3><div class="relative flex-grow"><Doughnut :data="ageChartData" :options="doughnutOptions" /></div></div>
        <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow h-96 flex flex-col"><h3 class="text-lg font-medium text-gray-800 mb-4">Benefícios Sociais por Restaurante </h3><div class="relative flex-grow"><Bar :data="benefitsChartData" :options="groupedBarOptions" /></div></div>
      </div>
      
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="lg:col-span-1 bg-white p-6 rounded-lg shadow h-96 flex flex-col"><h3 class="text-lg font-medium text-gray-800 mb-4">Beneficiários CadÚnico </h3><div class="relative flex-grow"><Doughnut :data="cadunicoChartData" :options="doughnutOptions" /></div></div>
          <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow h-96 flex flex-col"><h3 class="text-lg font-medium text-gray-800 mb-4">Volume de Pesquisas por Dia</h3><div class="relative flex-grow"><Bar :data="volumeChartData" :options="barOptions" /></div></div>
      </div>

       <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="lg:col-span-1 bg-white p-6 rounded-lg shadow h-96 flex flex-col"><h3 class="text-lg font-medium text-gray-800 mb-4">Distribuição das Avaliações</h3><div class="relative flex-grow"><Doughnut :data="ratingChartData" :options="doughnutOptions" /></div></div>
          <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow h-96 flex flex-col"><h3 class="text-lg font-medium text-gray-800 mb-4">Mapa de Calor das Avaliações por CEP</h3><div ref="mapContainer" class="rounded-md flex-grow"></div></div>
      </div>

      <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b"><h3 class="text-lg font-medium text-gray-900">Respostas Detalhadas</h3></div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="table-header">Usuário</th>
                <th class="table-header">Restaurante</th>
                <th class="table-header">Avaliação</th>
                <th class="table-header">Idade</th>
                <th class="table-header">Gênero</th>
                <th class="table-header">CadÚnico</th>
                <th class="table-header">Data</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-if="surveys.data.length === 0">
                <td colspan="7" class="text-center py-10 text-gray-500">Nenhuma pesquisa encontrada.</td>
              </tr>
              <tr v-for="survey in surveys.data" :key="survey.id">
                <td class="table-cell font-medium text-gray-900">{{ survey.contact.name }}</td>
                <td class="table-cell text-gray-500">{{ survey.restaurant_name }}</td>
                <td class="table-cell text-yellow-500 font-bold">{{ survey.rating }} ★</td>
                <td class="table-cell text-gray-500">{{ survey.demographics.age }}</td>
                <td class="table-cell text-gray-500">{{ survey.demographics.gender }}</td>
                <td class="table-cell">
                    <span :class="[survey.demographics.is_cadunico_beneficiary ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-800', 'px-2 py-1 text-xs font-medium rounded-full']">
                        {{ survey.demographics.is_cadunico_beneficiary ? 'Sim' : 'Não' }}
                    </span>
                </td>
                <td class="table-cell text-gray-500">{{ new Date(survey.created_at).toLocaleDateString('pt-BR') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="surveys.links.length > 3" class="px-6 py-3 bg-gray-50 border-t flex justify-between items-center text-sm">
            <p class="text-gray-600">
                Mostrando {{ surveys.from }} a {{ surveys.to }} de {{ surveys.total }} resultados
            </p>
            <div class="flex items-center space-x-1">
                <Link v-for="(link, index) in surveys.links" :key="index" :href="link.url" v-html="link.label" class="px-3 py-1 rounded-md" :class="{'bg-green-600 text-white': link.active, 'hover:bg-gray-200': link.url && !link.active, 'text-gray-400': !link.url}" />
            </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<style scoped>
.table-header { @apply px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider; }
.table-cell { @apply px-6 py-4 whitespace-nowrap text-sm; }
</style>
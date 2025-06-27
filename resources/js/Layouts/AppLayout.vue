<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex">
            <!-- Logo -->
            <div class="flex-shrink-0 flex items-center">
              <Link href="/dashboard" class="text-xl font-bold text-green-600">
                sim.social
              </Link>
            </div>
            
            <!-- Navigation Links -->
            <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
              <Link 
                :href="route('dashboard')" 
                :class="[
                  'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium',
                  route().current('dashboard') 
                    ? 'border-green-500 text-gray-900' 
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                ]"
              >
                <ChartBarIcon class="w-4 h-4 mr-2" />
                Dashboard
              </Link>
              
              <Link 
                :href="route('conversations')" 
                :class="[
                  'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium',
                  route().current('conversations*') 
                    ? 'border-green-500 text-gray-900' 
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                ]"
              >
                <ChatBubbleLeftRightIcon class="w-4 h-4 mr-2" />
                Conversas
                <!-- O contador agora é reativo -->
                <span v-if="unreadCount > 0" class="ml-2 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                  {{ unreadCount }}
                </span>
              </Link>

              <Link 
                :href="route('contacts.index')" 
                :class="[
                  'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium',
                  route().current('contacts*') 
                    ? 'border-green-500 text-gray-900' 
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                ]"
              >
                <UserGroupIcon class="w-4 h-4 mr-2" />
                Contatos
              </Link>
              
              <Link 
                :href="route('campaigns')" 
                :class="[
                  'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium',
                  route().current('campaigns*') 
                    ? 'border-green-500 text-gray-900' 
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                ]"
              >
                <MegaphoneIcon class="w-4 h-4 mr-2" />
                Campanhas
              </Link>
              
              <Link 
                :href="route('ai.training')" 
                :class="[
                  'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium',
                  route().current('ai*') 
                    ? 'border-green-500 text-gray-900' 
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                ]"
              >
                <CpuChipIcon class="w-4 h-4 mr-2" />
                IA
              </Link>
            </div>
          </div>
          
          <!-- User Menu -->
          <div class="hidden sm:ml-6 sm:flex sm:items-center">
            <button class="bg-white p-1 rounded-full text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
              <BellIcon class="h-6 w-6" />
            </button>
            
            <div class="ml-3 relative">
              <Menu as="div" class="relative">
                <MenuButton class="bg-white flex text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                  <img class="h-8 w-8 rounded-full" :src="$page.props.auth.user.avatar || 'https://i.pravatar.cc/32'" :alt="$page.props.auth.user.name" />
                </MenuButton>
                <transition
                  enter-active-class="transition ease-out duration-200"
                  enter-from-class="transform opacity-0 scale-95"
                  enter-to-class="transform opacity-100 scale-100"
                  leave-active-class="transition ease-in duration-75"
                  leave-from-class="transform opacity-100 scale-100"
                  leave-to-class="transform opacity-0 scale-95"
                >
                  <MenuItems class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5 focus:outline-none">
                    <MenuItem v-slot="{ active }">
                      <Link :href="route('profile')" :class="[active ? 'bg-gray-100' : '', 'block px-4 py-2 text-sm text-gray-700']">
                        Perfil
                      </Link>
                    </MenuItem>
                    <MenuItem v-slot="{ active }">
                      <Link :href="route('settings')" :class="[active ? 'bg-gray-100' : '', 'block px-4 py-2 text-sm text-gray-700']">
                        Configurações
                      </Link>
                    </MenuItem>
                    <MenuItem v-slot="{ active }">
                      <Link :href="route('logout')" method="post" as="button" class="w-full text-left" :class="[active ? 'bg-gray-100' : '', 'block px-4 py-2 text-sm text-gray-700']">
                        Sair
                      </Link>
                    </MenuItem>
                  </MenuItems>
                </transition>
              </Menu>
            </div>
          </div>
          
          <!-- Mobile menu button -->
          <div class="-mr-2 flex items-center sm:hidden">
            <button @click="showingNavigationDropdown = !showingNavigationDropdown" class="bg-white inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-500">
              <Bars3Icon v-if="!showingNavigationDropdown" class="block h-6 w-6" />
              <XMarkIcon v-else class="block h-6 w-6" />
            </button>
          </div>
        </div>
      </div>
      
      <!-- Mobile menu -->
      <div v-show="showingNavigationDropdown" class="sm:hidden">
        <!-- ... mobile menu links ... -->
      </div>
    </nav>
    
    <!-- Page Content -->
    <div>
      <header class="bg-white shadow" v-if="$slots.header">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
          <slot name="header" />
        </div>
      </header>

      <main class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import {
  Bars3Icon,
  XMarkIcon,
  BellIcon,
  ChartBarIcon,
  ChatBubbleLeftRightIcon,
  MegaphoneIcon,
  CpuChipIcon,
  UserGroupIcon
} from '@heroicons/vue/24/outline';

const page = usePage();
const showingNavigationDropdown = ref(false);

// Estado reativo para contagem de mensagens não lidas, inicializado pelo backend
const unreadCount = ref(page.props.auth.unreadCount || 0);

// Cria um objeto de áudio reutilizável para a notificação
const notificationSound = new Audio('data:audio/mpeg;base64,SUQzBAAAAAABEVRYWFgAAAAtAAADY29tbWVudABCaWdTb3VuZEJhbmsuY29tIC8gUmVjb3JkZWQgQnkgVmVsc2hpVlpAAAAQUVNUQAAAAAZAAAAaW5mbwAAAAwAAABBAAAAQABNLQ==');

const playNotificationSound = () => {
    notificationSound.play().catch(e => console.warn("A reprodução do som falhou. O navegador pode exigir interação do usuário primeiro.", e));
};

onMounted(() => {
    if (window.Echo) {
        console.log("Conectando ao Laravel Echo...");
        // Ouve no canal geral do dashboard para atualizações
        window.Echo.private('whatsapp.dashboard')
            .listen('.message.received', (e) => {
                console.log('Evento de nova mensagem recebido:', e);
                // Incrementa o contador de não lidos
                unreadCount.value++;
                // Toca o som de notificação
                playNotificationSound();
            });
    }
});

onUnmounted(() => {
    if (window.Echo) {
        // Para de ouvir o canal ao sair do componente para evitar vazamentos de memória
        window.Echo.leave('whatsapp.dashboard');
    }
});
</script>

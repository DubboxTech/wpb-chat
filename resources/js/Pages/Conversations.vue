<template>
  <AppLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h1 class="text-2xl font-semibold text-gray-900">Conversas</h1>
        <div class="flex space-x-3">
          <select v-model="statusFilter" class="border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500">
            <option value="">Todas</option>
            <option value="open">Abertas</option>
            <option value="pending">Pendentes</option>
            <option value="resolved">Resolvidas</option>
            <option value="closed">Fechadas</option>
          </select>
        </div>
      </div>
    </template>

    <div class="bg-white shadow rounded-lg overflow-hidden">
      <div class="grid grid-cols-1 lg:grid-cols-3 h-[calc(100vh-200px)]">
        <div class="lg:col-span-1 border-r border-gray-200 flex flex-col overflow-hidden">
          <div class="p-4 border-b border-gray-200 flex-shrink-0">
            <div class="relative">
              <MagnifyingGlassIcon class="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
              <input
                type="text"
                placeholder="Buscar conversas..."
                class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                v-model="searchQuery"
              />
            </div>
          </div>
          
          <div class="flex-1 overflow-y-auto divide-y divide-gray-200">
            <div
              v-for="conversation in localConversations"
              :key="conversation.id"
              @click="selectConversation(conversation)"
              :class="['p-4 cursor-pointer hover:bg-gray-50 transition-colors', selectedConversation?.id === conversation.id ? 'bg-green-50 border-r-2 border-green-500' : '']"
            >
              <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                  <img v-if="conversation.contact.avatar" :src="conversation.contact.avatar" alt="Avatar" class="h-10 w-10 rounded-full bg-gray-300 object-cover">
                  <div v-else class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                    <UserIcon class="h-6 w-6 text-gray-600" />
                  </div>
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-900 truncate">{{ conversation.contact.name }}</p>
                    <p class="text-xs text-gray-500">{{ conversation.lastMessageTime }}</p>
                  </div>
                  <p class="text-sm text-gray-500 truncate" v-html="formatMessage(conversation.lastMessage || '')"></p>
                  <div class="flex items-center justify-between mt-1">
                    <div class="flex items-center space-x-2">
                       <span :class="['inline-flex items-center px-2 py-0.5 rounded text-xs font-medium', conversation.status === 'open' ? 'bg-green-100 text-green-800' : conversation.status === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800']">{{ conversation.status }}</span>
                       <span v-if="conversation.isAiHandled" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800"><CpuChipIcon class="h-3 w-3 mr-1" />IA</span>
                    </div>
                    <span v-if="conversation.unreadCount > 0" class="inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full">{{ conversation.unreadCount }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="lg:col-span-2 flex flex-col min-h-0">
          <div v-if="!selectedConversation" class="flex-1 flex items-center justify-center bg-gray-50">
            <div class="text-center">
              <ChatBubbleLeftRightIcon class="h-12 w-12 text-gray-400 mx-auto mb-4" />
              <h3 class="text-lg font-medium text-gray-900 mb-2">Selecione uma conversa</h3>
              <p class="text-gray-500">Escolha uma conversa da lista para começar a responder</p>
            </div>
          </div>
          
          <div v-else-if="isLoading" class="flex-1 flex items-center justify-center bg-gray-50"><p>A carregar mensagens...</p></div>

          <template v-else>
            <div class="p-4 border-b border-gray-200 bg-white flex-shrink-0">
              <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                   <img v-if="selectedConversation.contact.avatar" :src="selectedConversation.contact.avatar" alt="Avatar" class="h-10 w-10 rounded-full bg-gray-300 object-cover">
                   <div v-else class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center"><UserIcon class="h-6 w-6 text-gray-600" /></div>
                  <div>
                    <h3 class="text-lg font-medium text-gray-900">{{ selectedConversation.contact.name }}</h3>
                    <p class="text-sm text-gray-500">{{ selectedConversation.contact.phone }}</p>
                  </div>
                </div>
                <div class="flex items-center space-x-2">
                  <button @click="toggleAI" :class="['inline-flex items-center px-3 py-1 rounded-full text-sm font-medium', selectedConversation.isAiHandled ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800']"><CpuChipIcon class="h-4 w-4 mr-1" />{{ selectedConversation.isAiHandled ? 'IA Ativa' : 'IA Inativa' }}</button>
                  <button class="p-2 text-gray-400 hover:text-gray-600"><EllipsisVerticalIcon class="h-5 w-5" /></button>
                </div>
              </div>
            </div>

            <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-4 bg-gray-50">
              <div v-for="message in messages" :key="message.id" :class="[ 'flex', message.direction === 'outbound' ? 'justify-end' : 'justify-start' ]">
                <div :class="['max-w-xs lg:max-w-md px-3 py-2 rounded-lg', message.direction === 'outbound' ? 'bg-green-500 text-white' : 'bg-white text-gray-900 shadow']">
                  
                  <div v-if="message.type === 'image' && message.media" class="w-64 h-48 bg-gray-200 rounded-lg flex items-center justify-center">
                    <img v-if="message.media.url" @click="openMedia(message.media.url)" :src="message.media.url" alt="Imagem enviada" class="cursor-pointer rounded-lg w-full h-full object-cover" />
                    <svg v-else class="animate-spin h-6 w-6 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                  </div>
                  
                  <div v-else-if="message.type === 'video' && message.media" class="w-64 h-48 bg-gray-200 rounded-lg flex items-center justify-center">
                     <video v-if="message.media.url" controls class="rounded-lg w-full h-full object-cover"><source :src="message.media.url" :type="message.media.mime_type"></video>
                     <svg v-else class="animate-spin h-6 w-6 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                  </div>
                  
                  <div v-else-if="message.type === 'audio'" class="w-64">
                     <audio v-if="message.media && message.media.url" controls class="w-full"><source :src="message.media.url" :type="message.media.mime_type"></audio>
                     <div v-else class="h-12 flex items-center justify-center bg-gray-200 rounded-lg">
                       <svg class="animate-spin h-5 w-5 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                     </div>
                     <p v-if="message.content" class="text-sm italic opacity-80 mt-2">"{{ message.content }}"</p>
                  </div>
                   <a v-else-if="message.type === 'document' && message.media && message.media.url" :href="message.media.url" target="_blank" class="flex items-center space-x-2 p-2 rounded-lg hover:bg-gray-100"><DocumentIcon class="h-6 w-6 text-gray-500"/><span class="text-sm font-medium">{{ message.media.filename || 'Documento' }}</span></a>
                  <div v-else-if="message.type === 'sticker' && message.media && message.media.url"><img :src="message.media.url" alt="Sticker" class="w-32 h-32"/></div>
                  <a v-else-if="message.type === 'location' && message.content" :href="`https://maps.google.com/?q=${message.content}`" target="_blank" class="flex items-center space-x-2 p-2 rounded-lg hover:bg-gray-100"><MapPinIcon class="h-6 w-6 text-blue-500"/><span class="text-sm font-medium underline">Ver localização</span></a>
                  <div v-else-if="message.type === 'template'"><div v-if="message.media && message.media.url" class="mb-2"><img :src="message.media.url" alt="Mídia do template" class="rounded-lg max-w-full h-auto cursor-pointer" @click="openMedia(message.media.url)" /></div><p class="text-sm" v-html="formatMessage(message.content || '')"></p></div>
                  
                  <p v-else-if="message.content" class="text-sm" v-html="formatMessage(message.content)"></p>
                  
                  <p v-if="message.media && message.content" class="text-sm mt-2" v-html="formatMessage(message.content)"></p>

                  <div class="flex items-center justify-end mt-1">
                    <p :class="['text-xs opacity-75 mr-2', message.direction === 'outbound' ? 'text-green-100' : 'text-gray-500']">{{ message.time }}</p>
                    <div v-if="message.direction === 'outbound'" class="flex items-center space-x-1">
                      <CheckIcon v-if="message.status === 'sent'" class="h-4 w-4 opacity-75" />
                      <div v-else-if="message.status === 'delivered' || message.status === 'read'" class="flex"><CheckIcon :class="['h-4 w-4', message.status === 'read' ? 'text-blue-300' : 'opacity-75']" /><CheckIcon :class="['h-4 w-4 -ml-2', message.status === 'read' ? 'text-blue-300' : 'opacity-75']" /></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="p-4 border-t border-gray-200 bg-white flex-shrink-0">
              <div v-if="filePreviewUrl" class="mb-2 p-2 border rounded-lg relative w-40">
                <img :src="filePreviewUrl" class="rounded-md max-w-full h-auto" />
                <button @click="removeAttachment" class="absolute -top-2 -right-2 bg-gray-700 text-white rounded-full h-6 w-6 flex items-center justify-center hover:bg-red-600"><XMarkIcon class="h-4 w-4" /></button>
              </div>
              <div class="bg-gray-50 border border-gray-300 rounded-lg">
                <div class="flex items-center space-x-1 p-2 border-b">
                  <button @click="applyFormat('*')" class="px-2 py-1 rounded hover:bg-gray-200 font-bold" title="Negrito">B</button>
                  <button @click="applyFormat('_')" class="px-2 py-1 rounded hover:bg-gray-200 italic" title="Itálico">I</button>
                  <button @click="applyFormat('~')" class="px-2 py-1 rounded hover:bg-gray-200 line-through" title="Riscado">S</button>
                  <button @click="applyFormat('```')" class="px-2 py-1 rounded hover:bg-gray-200 font-mono text-sm" title="Monoespaçado">{;}</button>
                </div>
                <div class="flex items-end space-x-2 p-2">
                  <button @click="triggerFileInput" class="p-2 text-gray-400 hover:text-green-600"><PaperClipIcon class="h-6 w-6" /></button>
                  <input type="file" ref="fileInput" @change="handleFileSelect" class="hidden" accept="image/*,video/*,application/pdf,audio/*" />
                  <div class="flex-1">
                    <textarea
                      ref="messageInput"
                      v-model="newMessage"
                      @keydown.enter.prevent="sendMessage"
                      :placeholder="attachedFile ? 'Adicione uma legenda...' : 'Digite a sua mensagem...'"
                      rows="1"
                      class="w-full border-none bg-transparent rounded-lg px-2 py-2 focus:ring-0 resize-none"
                      style="min-height: 42px;"
                    ></textarea>
                  </div>
                  <button @click="sendMessage" :disabled="isSending || (!newMessage.trim() && !attachedFile)" class="bg-green-600 hover:bg-green-700 disabled:bg-gray-300 text-white p-2 rounded-lg transition-colors flex items-center justify-center" style="height: 42px; width: 42px;">
                    <PaperAirplaneIcon v-if="!isSending" class="h-5 w-5" />
                    <svg v-else class="animate-spin h-5 w-5 text-white" xmlns="[http://www.w3.org/2000/svg](http://www.w3.org/2000/svg)" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                  </button>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { debounce } from 'lodash';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
  ChatBubbleLeftRightIcon, UserIcon, MagnifyingGlassIcon, CpuChipIcon,
  EllipsisVerticalIcon, PaperAirplaneIcon, CheckIcon, DocumentIcon,
  MapPinIcon, PaperClipIcon, XMarkIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
  conversations: Object,
  filters: Object,
});

const localConversations = ref([...props.conversations.data]);
const searchQuery = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const selectedConversation = ref(null);
const messages = ref([]);
const newMessage = ref('');
const isLoading = ref(false);
const isSending = ref(false);
const messagesContainer = ref(null);
const fileInput = ref(null);
const messageInput = ref(null);
const attachedFile = ref(null);
const filePreviewUrl = ref(null);

const scrollToBottom = () => {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
};

watch(messages, () => {
    scrollToBottom();
}, { deep: true });

watch([searchQuery, statusFilter], debounce(() => {
  router.get(route('conversations'), {
    search: searchQuery.value,
    status: statusFilter.value,
  }, { 
      preserveState: true, 
      replace: true, 
      onSuccess: page => {
        localConversations.value = page.props.conversations.data;
      }
  });
}, 300));

const selectConversation = async (conversation) => {
  if (isLoading.value || (selectedConversation.value && selectedConversation.value.id === conversation.id)) return;
  if (selectedConversation.value && window.Echo) {
    window.Echo.leave(`whatsapp.conversations.${selectedConversation.value.id}`);
  }
  isLoading.value = true;
  selectedConversation.value = conversation;
  try {
    const response = await axios.get(route('conversations.show', conversation.id));
    messages.value = response.data.conversation.messages;
    const localConv = localConversations.value.find(c => c.id === conversation.id);
    if(localConv) localConv.unreadCount = 0;
    listenToConversationChannel(conversation.id);
  } catch (error) {
    console.error("Erro ao procurar mensagens:", error);
    alert('Não foi possível carregar as mensagens.');
  } finally {
    isLoading.value = false;
  }
};

const triggerFileInput = () => fileInput.value.click();

const handleFileSelect = (event) => {
  const file = event.target.files[0];
  if (!file) return;
  attachedFile.value = file;
  if (file.type.startsWith('image/')) {
    filePreviewUrl.value = URL.createObjectURL(file);
  } else {
    filePreviewUrl.value = null;
    newMessage.value = `Anexado: ${file.name}`;
  }
};

const removeAttachment = () => {
  attachedFile.value = null;
  if(filePreviewUrl.value) {
    URL.revokeObjectURL(filePreviewUrl.value);
    filePreviewUrl.value = null;
  }
  fileInput.value.value = '';
  if(newMessage.value.startsWith('Anexado:')) {
      newMessage.value = '';
  }
};

const sendMessage = async () => {
  if (isSending.value || (!newMessage.value.trim() && !attachedFile.value)) return;
  isSending.value = true;
  try {
    let payload = {};
    if (attachedFile.value) {
      const formData = new FormData();
      formData.append('file', attachedFile.value);
      formData.append('conversation_id', selectedConversation.value.id);
      const uploadResponse = await axios.post(route('media.upload'), formData);
      if (!uploadResponse.data.success) throw new Error('Falha no upload do ficheiro.');
      let mediaType = 'document';
      if(attachedFile.value.type.startsWith('image/')) mediaType = 'image';
      if(attachedFile.value.type.startsWith('video/')) mediaType = 'video';
      if(attachedFile.value.type.startsWith('audio/')) mediaType = 'audio';
      payload = { type: mediaType, media_url: uploadResponse.data.url, caption: newMessage.value.trim(), filename: uploadResponse.data.filename };
    } else {
      payload = { type: 'text', content: newMessage.value.trim() };
    }
    const messageResponse = await axios.post(route('conversations.sendMessage', selectedConversation.value.id), payload);
    messages.value.push(messageResponse.data.message);
    newMessage.value = '';
    removeAttachment();
  } catch (error) {
    console.error("Erro ao enviar mensagem:", error.response?.data || error);
    alert('Não foi possível enviar a mensagem.');
  } finally {
    isSending.value = false;
  }
};

const toggleAI = async () => {
  if (!selectedConversation.value) return;
  try {
    const response = await axios.post(route('conversations.toggleAI', selectedConversation.value.id));
    selectedConversation.value.isAiHandled = response.data.isAiHandled;
  } catch (error) {
    console.error("Erro ao alternar IA:", error);
    alert('Não foi possível alterar o estado da IA.');
  }
};

const openMedia = (url) => window.open(url, '_blank');

// ** NOVA LÓGICA DE FORMATAÇÃO **
const formatMessage = (text) => {
  if (!text) return '';
  let formattedText = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
  
  // Negrito: *texto*
  formattedText = formattedText.replace(/\*(.*?)\*/g, '<strong>$1</strong>');
  // Itálico: _texto_
  formattedText = formattedText.replace(/_(.*?)_/g, '<em>$1</em>');
  // Riscado: ~texto~
  formattedText = formattedText.replace(/~(.*?)~/g, '<s>$1</s>');
  // Monoespaçado: ```texto```
  formattedText = formattedText.replace(/```(.*?)```/g, '<code>$1</code>');
  
  return formattedText;
};

const applyFormat = (char) => {
    const textarea = messageInput.value;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selectedText = newMessage.value.substring(start, end);
    const textBefore = newMessage.value.substring(0, start);
    const textAfter = newMessage.value.substring(end);

    if (start === end) {
        // Se nada for selecionado, apenas insere os caracteres
        newMessage.value = textBefore + char + char + textAfter;
        // Coloca o cursor entre os caracteres
        nextTick(() => {
            textarea.selectionStart = textarea.selectionEnd = start + char.length;
            textarea.focus();
        });
    } else {
        // Se o texto for selecionado, envolve-o com os caracteres
        newMessage.value = textBefore + char + selectedText + char + textAfter;
        nextTick(() => {
            textarea.focus();
        });
    }
};

// --- LÓGICA DO LARAVEL ECHO ---
const handleNewMessage = (event) => {
    const message = event.message;
    if (selectedConversation.value && selectedConversation.value.id === message.conversation_id) {
        messages.value.push(message);
    }
    let conversationInList = localConversations.value.find(c => c.id === message.conversation_id);
    if (conversationInList) {
        conversationInList.lastMessage = message.content || `Nova ${message.type}`;
        conversationInList.lastMessageTime = 'agora';
        if (selectedConversation.value?.id !== message.conversation_id && message.direction === 'inbound') {
             conversationInList.unreadCount++;
        }
        localConversations.value = localConversations.value.filter(c => c.id !== message.conversation_id);
        localConversations.value.unshift(conversationInList);
    } else if (message.direction === 'inbound') {
        router.reload({ only: ['conversations'], preserveScroll: true });
    }
};

const handleStatusUpdate = (event) => {
    if (selectedConversation.value && selectedConversation.value.id === event.conversation_id) {
        const messageInChat = messages.value.find(m => m.whatsapp_message_id === event.whatsapp_message_id);
        if (messageInChat) {
            messageInChat.status = event.status;
        }
    }
};

const handleMediaUpdate = (event) => {
    if (selectedConversation.value && selectedConversation.value.id === event.conversation_id) {
        const messageInChat = messages.value.find(m => m.id === event.message_id);
        if (messageInChat) {
            messageInChat.media = event.media;
        }
    }
};

const listenToConversationChannel = (conversationId) => {
    if (window.Echo) {
        window.Echo.private(`whatsapp.conversations.${conversationId}`)
            .listen('.message.status.updated', handleStatusUpdate)
            .listen('.media.updated', handleMediaUpdate); 
    }
};

onMounted(() => {
    if (window.Echo) {
        window.Echo.private(`whatsapp.dashboard`)
            .listen('.message.received', handleNewMessage)
            .listen('.chat.message.sent', handleNewMessage);
    }
});

onUnmounted(() => {
    if (window.Echo) {
        window.Echo.leave(`whatsapp.dashboard`);
        if (selectedConversation.value) {
            window.Echo.leave(`whatsapp.conversations.${selectedConversation.value.id}`);
        }
    }
});
</script>
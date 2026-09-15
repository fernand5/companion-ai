import api from '@/services/api'
import type { Conversation, Message } from '@/types'

export function fetchConversations() {
  return api.get<Conversation[]>('/conversations').then((r) => r.data)
}

export function createConversation(title?: string) {
  return api.post<Conversation>('/conversations', { title }).then((r) => r.data)
}

export function fetchMessages(conversationId: number) {
  return api.get<Message[]>(`/conversations/${conversationId}/messages`).then((r) => r.data)
}

export function sendMessage(conversationId: number, content: string) {
  return api
    .post<Message>(`/conversations/${conversationId}/messages`, { content })
    .then((r) => r.data)
}

<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { ICON_SIZE, icons } from '@/constants/icons'
import type { ProgressionActionState } from '@/stores/progression'
import type { ProgressionDecision, ProgressionItem } from '@/types'
import { formatDate, formatTarget } from '@/utils/format'

const props = defineProps<{
  item: ProgressionItem
  state: ProgressionActionState
  /** Show only the exercise, decision and current target until opened. For recommendations with nothing to act on. */
  collapsible?: boolean
}>()

const emit = defineEmits<{
  accept: []
  keep: []
  refresh: []
}>()

const { t } = useI18n()

/** Only the colours live here: the words come from the catalog, keyed by the server's decision, when drawn. */
const DECISION_CLASSES: Record<ProgressionDecision, string> = {
  INCREASE: 'bg-brand-100 text-brand-700',
  DECREASE: 'bg-amber-100 text-amber-700',
  MAINTAIN: 'bg-slate-100 text-slate-600',
  INSUFFICIENT_DATA: 'bg-slate-100 text-slate-600',
  MANUAL_REVIEW: 'bg-amber-100 text-amber-700',
}

const decision = computed(() => ({ label: t(`progression.decision.${props.item.decision}`), classes: DECISION_CLASSES[props.item.decision] }))
const proposed = computed(() => props.item.proposed_target)
const proposesChange = computed(() => props.item.decision === 'INCREASE' || props.item.decision === 'DECREASE')
const working = computed(() => props.state.status === 'working')

/** An approval is only ever offered when the server issued a proposal for it. */
const canAct = computed(
  () => props.item.actionable && props.item.proposal !== null && (props.state.status === 'idle' || props.state.status === 'failed' || working.value),
)

const unavailableReason = computed(() => {
  if (!proposesChange.value || props.item.actionable) return null

  const reason = props.item.not_actionable_reason

  return reason === 'no_upcoming_session' || reason === 'workout_already_started' || reason === 'no_load_in_plan'
    ? t(`progression.notActionable.${reason}`)
    : t('progression.cannotApply')
})

const finished = computed(() => props.state.status === 'applied' || props.state.status === 'kept')

/** The user declined this exact recommendation: now (just clicked) or earlier (the server remembers). */
const kept = computed(() => props.item.dismissed !== null || props.state.status === 'kept')

const keptMessage = computed(() =>
  props.state.status === 'kept' && props.state.message
    ? props.state.message
    : t('progression.keptNote'),
)
</script>

<template>
  <!-- Declined: shown as such, with no approval to click again. -->
  <article v-if="kept" class="space-y-2 rounded-xl border border-slate-200 bg-white p-4" :data-decision="item.decision" data-testid="kept-card">
    <header class="flex flex-wrap items-start justify-between gap-2">
      <h4 class="min-w-0 break-words font-semibold text-slate-900">{{ item.exercise.name }}</h4>
      <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600" data-testid="decision">
        {{ t('progression.keptTitle') }}
      </span>
    </header>
    <p class="text-sm text-slate-900">
      <span class="text-xs font-medium tracking-wide text-slate-400 uppercase">{{ t('progression.currentTarget') }}</span>
      <span class="ml-2" data-testid="current">{{ formatTarget(item.current_target) }}</span>
    </p>
    <p class="text-sm text-slate-600" role="status" data-testid="status-note">{{ keptMessage }}</p>
  </article>

  <article v-else class="rounded-xl border border-slate-200 bg-white" :data-decision="item.decision" :data-collapsible="collapsible ? 'true' : undefined">
    <component :is="collapsible ? 'details' : 'div'" class="group space-y-3 p-4" :class="collapsible ? 'open:pb-4' : ''">
    <component
      :is="collapsible ? 'summary' : 'header'"
      class="flex flex-wrap items-start justify-between gap-2"
      :class="collapsible ? 'min-h-11 cursor-pointer list-none select-none [&::-webkit-details-marker]:hidden' : ''"
    >
      <span class="min-w-0">
        <h4 class="break-words font-semibold text-slate-900">{{ item.exercise.name }}</h4>
        <span v-if="collapsible" class="block text-xs font-normal text-slate-500" data-testid="collapsed-target">
          {{ t('progression.currentTargetLine', { target: formatTarget(item.current_target) }) }}
        </span>
      </span>
      <span class="flex shrink-0 items-center gap-2">
        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="decision.classes" data-testid="decision">
          {{ decision.label }}
        </span>
        <FontAwesomeIcon v-if="collapsible" :icon="icons.chevronDown" :class="[ICON_SIZE.xs, 'text-slate-400 transition-transform group-open:rotate-180']" />
      </span>
    </component>

    <dl class="grid grid-cols-2 gap-3 text-sm">
      <div>
        <dt class="text-xs font-medium tracking-wide text-slate-400 uppercase">{{ t('progression.currentTarget') }}</dt>
        <dd class="text-slate-900" data-testid="current">{{ formatTarget(item.current_target) }}</dd>
      </div>
      <div v-if="proposed">
        <dt class="text-xs font-medium tracking-wide text-slate-400 uppercase">{{ t('progression.proposed') }}</dt>
        <dd class="font-semibold text-slate-900" data-testid="proposed">
          {{ formatTarget(proposed) }}
          <span class="text-xs font-normal text-slate-500">
            ({{ proposed.change_percent > 0 ? '+' : '' }}{{ proposed.change_percent }}%)
          </span>
        </dd>
      </div>
    </dl>

    <p v-if="item.applies_to && proposesChange" class="text-xs text-slate-500" data-testid="applies-to">
      {{ t('progression.appliesTo', { date: formatDate(item.applies_to.date) }) }}
      <template v-if="item.later_sessions.length > 0">
        <span data-testid="later-sessions">
          {{ t('progression.laterSessions', { dates: item.later_sessions.map((s) => formatDate(s.date)).join(', ') }) }}
        </span>
      </template>
    </p>

    <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600" data-testid="reasons">
      <li v-for="reason in item.reasons" :key="reason">{{ reason }}</li>
    </ul>

    <div v-if="item.supporting_sessions.length > 0" class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-slate-400 uppercase">{{ t('progression.recentSessions') }}</p>
      <ul class="space-y-1 text-sm text-slate-600" data-testid="sessions">
        <li v-for="session in item.supporting_sessions" :key="`${session.date}-${session.performed}`" class="flex flex-wrap gap-x-2">
          <span class="text-slate-400">{{ session.date ? formatDate(session.date) : t('progression.undated') }}</span>
          <span class="break-words">{{ session.performed || t('progression.notRecorded') }}</span>
          <span v-if="session.target_reached === true" class="text-brand-700">{{ t('progression.targetReached') }}</span>
          <span v-else-if="session.target_reached === false" class="text-amber-700">
            {{ t('progression.targetMissed') }}<template v-if="session.sets_reaching_target !== null && session.planned_sets !== null">
              {{ t('progression.setsOf', { n: session.sets_reaching_target, total: session.planned_sets }) }}
            </template>
          </span>
        </li>
      </ul>
    </div>

    <div class="space-y-1 text-xs text-slate-500">
      <p data-testid="evidence">{{ t(`progression.evidence.${item.evidence_quality}`) }}</p>
      <ul v-if="item.safety_caveats.length > 0" class="list-disc space-y-0.5 pl-5" data-testid="caveats">
        <li v-for="caveat in item.safety_caveats" :key="caveat">{{ caveat }}</li>
      </ul>
    </div>

    <p v-if="unavailableReason" class="flex items-start gap-1.5 text-sm text-slate-500" data-testid="unavailable">
      <FontAwesomeIcon :icon="icons.info" :class="[ICON_SIZE.sm, 'mt-0.5 shrink-0']" />
      {{ unavailableReason }}
    </p>

    <p
      v-if="item.decision === 'MANUAL_REVIEW' || item.decision === 'INSUFFICIENT_DATA'"
      class="text-sm text-slate-500"
      data-testid="no-change"
    >
      {{ t('progression.noChange') }}
    </p>

    <!-- Feedback for what just happened to this recommendation. -->
    <p
      v-if="state.status === 'applied'"
      class="flex items-start gap-1.5 rounded-lg bg-brand-50 p-3 text-sm text-brand-700"
      role="status"
      data-testid="status-note"
    >
      <FontAwesomeIcon :icon="icons.planStatus.completed" :class="[ICON_SIZE.sm, 'mt-0.5 shrink-0']" />
      {{ state.message }}
    </p>
    <div v-else-if="state.status === 'stale'" class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800" role="alert" data-testid="status-note">
      <p class="flex items-start gap-1.5">
        <FontAwesomeIcon :icon="icons.warning" :class="[ICON_SIZE.sm, 'mt-0.5 shrink-0']" />
        {{ state.message }}
      </p>
      <button
        type="button"
        class="min-h-11 w-full rounded-xl border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100 sm:w-auto"
        data-testid="refresh"
        @click="emit('refresh')"
      >
        {{ t('progression.refreshCard') }}
      </button>
    </div>
    <p v-else-if="state.status === 'failed'" class="flex items-start gap-1.5 text-sm text-red-600" role="alert" data-testid="status-note">
      <FontAwesomeIcon :icon="icons.warning" :class="[ICON_SIZE.sm, 'mt-0.5 shrink-0']" />
      {{ state.message }}
    </p>

    <!-- Only a recommendation the server can apply has actions; everything else is information. -->
    <div v-if="canAct && !finished" class="flex flex-col gap-2 sm:flex-row" data-testid="actions">
      <button
        type="button"
        :disabled="working"
        class="flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50 sm:w-auto"
        data-testid="accept"
        @click="emit('accept')"
      >
        <FontAwesomeIcon v-if="working" :icon="icons.loading" :class="ICON_SIZE.sm" spin />
        {{ t('progression.accept') }}
      </button>
      <button
        type="button"
        :disabled="working"
        class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 sm:w-auto"
        data-testid="keep"
        @click="emit('keep')"
      >
        {{ t('progression.keep') }}
      </button>
    </div>
    <p v-if="canAct && !finished" class="text-xs text-slate-400">{{ t('progression.nothingChanges') }}</p>
    </component>
  </article>
</template>

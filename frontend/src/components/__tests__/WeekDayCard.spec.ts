import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import WeekDayCard from '@/components/WeekDayCard.vue'
import type { WeeklyPlanDay } from '@/types'
import { DAY_STATE } from '@/utils/weekDay'

import { WEEK, makeActivity, makeDay, makeExercise, makePlan, makeSteps } from './weekFixtures'

function card(day: WeeklyPlanDay, props: { expanded?: boolean; suggestions?: number; collapsible?: boolean } = {}) {
  return mount(WeekDayCard, { props: { day, expanded: props.expanded ?? true, suggestions: props.suggestions ?? 0, collapsible: props.collapsible ?? true } })
}

const PAST = WEEK[0] < new Date().toISOString().slice(0, 10) ? WEEK[0] : '2000-01-03'
const pastDay = (plan: ReturnType<typeof makePlan> | null, activities = [] as ReturnType<typeof makeActivity>[]) =>
  makeDay(PAST, plan && { ...plan, planned_date: PAST }, activities, { is_past: true, is_today: false })
const futureDay = (plan: ReturnType<typeof makePlan> | null) => makeDay('2999-01-04', plan && { ...plan, planned_date: '2999-01-04' }, [], { is_past: false, is_today: false })

describe('WeekDayCard: what the day is', () => {
  it('shows the workout title, its type and its status', () => {
    const wrapper = card(futureDay(makePlan({ title: 'Upper Body', activity_type: 'strength', duration_minutes: 45 })))

    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('Upper Body')
    expect(wrapper.get('[data-testid="day-type"]').text()).toContain('Strength')
    expect(wrapper.get('[data-testid="day-type"]').text()).toContain('45 min')
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Planned')
  })

  it.each([
    ['completed', 'Completed'],
    ['partial', 'Partly done'],
    ['in_progress', 'In progress'],
    ['skipped', 'Skipped'],
  ] as const)('a %s plan says %s', (status, label) => {
    expect(card(futureDay(makePlan({ status }))).get('[data-testid="day-state"]').text()).toBe(label)
  })

  it('a past plan nobody marked done is "Not marked done", and is never called missed', () => {
    const wrapper = card(pastDay(makePlan({ status: 'planned' })))

    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Not marked done')
    expect(wrapper.get('[data-testid="day-explanation"]').text()).toContain("doesn't mean it was missed")
    expect(wrapper.text().toLowerCase()).not.toContain('missed workout')
    expect(Object.values(DAY_STATE).map((s) => s.label.toLowerCase()).join(' ')).not.toContain('missed')
  })

  it('a day with no plan and nothing logged is not a rest day', () => {
    const past = card(pastDay(null))
    const upcoming = card(futureDay(null))

    expect(past.get('[data-testid="day-heading"]').text()).toBe('No plan or activity recorded')
    expect(upcoming.get('[data-testid="day-heading"]').text()).toBe('Nothing planned')
    for (const wrapper of [past, upcoming]) {
      expect(wrapper.text().toLowerCase()).not.toContain('rest')
      expect(wrapper.find('[data-testid="day-state"]').exists()).toBe(false)
    }
  })

  it('a day the plan cleared for rest is a rest day', () => {
    const wrapper = card(futureDay(makePlan({ title: 'Rest', activity_type: 'recovery', status: 'skipped' })), {})
    const day = makeDay('2999-01-04', makePlan({ title: 'Rest', activity_type: 'recovery', status: 'skipped' }), [], { state: 'rest', is_past: false, is_today: false })

    expect(card(day).get('[data-testid="day-state"]').text()).toBe('Rest day')
    expect(card(day).get('[data-testid="day-explanation"]').text()).toBe('A planned rest day.')
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Skipped')
  })

  it('marks today', () => {
    const today = makeDay(WEEK[3], makePlan(), [], { is_today: true })

    expect(card(today).find('[data-testid="today-chip"]').exists()).toBe(true)
    expect(card(futureDay(makePlan())).find('[data-testid="today-chip"]').exists()).toBe(false)
  })
})

describe('WeekDayCard: planned versus recorded', () => {
  it('shows the plan target and the recorded sets side by side, as separate things', () => {
    const day = pastDay(
      makePlan({
        status: 'completed',
        exercises: [
          makeExercise({
            status: 'partial',
            performance: {
              recorded_as: 'entered',
              sets: [
                { set_number: 1, reps: 10, weight_kg: 40, duration_seconds: null, completed: true },
                { set_number: 2, reps: 10, weight_kg: 40, duration_seconds: null, completed: true },
                { set_number: 3, reps: 6, weight_kg: 40, duration_seconds: null, completed: false },
              ],
            },
          }),
        ],
      }),
    )

    const wrapper = card(day)

    expect(wrapper.get('[data-testid="exercise-planned"]').text()).toBe('3×10 @ 40 kg')
    expect(wrapper.get('[data-testid="exercise-recorded"]').text()).toBe('10×40, 10×40, 6×40 (not completed) kg')
  })

  it('a one-tap "done" is not shown as measured performance', () => {
    const wrapper = card(pastDay(makePlan({ status: 'completed', exercises: [makeExercise({ status: 'completed', performance: { recorded_as: 'as_planned', sets: [{ set_number: 1, reps: 10, weight_kg: 40, duration_seconds: null, completed: true }] } })] })))

    expect(wrapper.get('[data-testid="exercise-recorded"]').text()).toBe('Marked done, amounts not recorded')
    expect(wrapper.get('[data-testid="exercise-recorded"]').text()).not.toContain('10×40')
  })

  it('an exercise nobody touched is "Not recorded", not done and not skipped', () => {
    const wrapper = card(pastDay(makePlan({ status: 'planned', exercises: [makeExercise({ status: 'pending' })] })))

    expect(wrapper.get('[data-testid="exercise-recorded"]').text()).toBe('Not recorded')
  })

  it('earlier logs are labelled as such', () => {
    const wrapper = card(pastDay(makePlan({ status: 'completed', exercises: [makeExercise({ status: 'completed', performance: { recorded_as: 'migrated', sets: [{ set_number: 1, reps: 8, weight_kg: 30, duration_seconds: null, completed: true }] } })] })))

    expect(wrapper.get('[data-testid="exercise-recorded"]').text()).toContain('(from an earlier log)')
  })

  it('an explicitly skipped exercise says so', () => {
    expect(card(pastDay(makePlan({ status: 'partial', exercises: [makeExercise({ status: 'skipped' })] }))).get('[data-testid="exercise-recorded"]').text()).toBe('Skipped')
  })

  it('a timed exercise shows its target as a duration', () => {
    const wrapper = card(futureDay(makePlan({ exercises: [makeExercise({ exercise_name: 'Plank', planned_sets: 3, planned_reps: null, planned_weight_kg: null, planned_duration_seconds: 30 })] })))

    expect(wrapper.get('[data-testid="exercise-planned"]').text()).toBe('3×30s')
  })

  it('a target with no load says no load, never a number the plan does not have', () => {
    const wrapper = card(futureDay(makePlan({ exercises: [makeExercise({ planned_weight_kg: null })] })))

    expect(wrapper.get('[data-testid="exercise-planned"]').text()).toBe('3×10')
    expect(wrapper.get('[data-testid="exercise-planned"]').text()).not.toContain('kg')
  })
})

describe('WeekDayCard: activity the user logged', () => {
  it('shows soccer played without a plan on its own day', () => {
    const wrapper = card(pastDay(null, [makeActivity({ type: 'sport', duration_minutes: 90, metadata: { sport: 'Soccer' } })]))

    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('Soccer')
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Unplanned activity')
    expect(wrapper.get('[data-testid="activities-heading"]').text()).toBe('Logged activity')
    expect(wrapper.get('[data-testid="activity"]').text()).toContain('Soccer')
    expect(wrapper.get('[data-testid="activity"]').text()).toContain('90 min')
    expect(wrapper.get('[data-testid="day-explanation"]').text()).toContain('without a plan')
  })

  it('shows unplanned cardio with its distance and time', () => {
    const wrapper = card(pastDay(null, [makeActivity({ type: 'treadmill', metadata: null, duration_minutes: 30, distance_km: 4.5, speed_kmh: 9 })]))

    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('Treadmill')
    expect(wrapper.get('[data-testid="activity"]').text()).toContain('4.5 km')
    expect(wrapper.get('[data-testid="activity"]').text()).toContain('30 min')
  })

  it('lists activity beside a plan as "also logged", without changing the plan state', () => {
    const wrapper = card(pastDay(makePlan({ status: 'planned', title: 'Upper Body' }), [makeActivity()]))

    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('Upper Body')
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Not marked done')
    expect(wrapper.get('[data-testid="activities-heading"]').text()).toBe('Also logged this day')
  })

  it('shows the exercises of a workout the user logged set by set', () => {
    const session = { id: 5, logged_date: PAST, duration_minutes: 40, notes: null, exercises: [{ id: 1, exercise_name: 'Squat', exercise_slug: 'squat', recorded_as: 'entered' as const, planned: null, performed_sets: [{ set_number: 1, reps: 8, weight_kg: 60, duration_seconds: null, completed: true }], sets: 1, reps: 8, weight_kg: 60, duration_seconds: null, notes: null }] }
    const wrapper = card(pastDay(null, [makeActivity({ type: 'strength', metadata: null, duration_minutes: 40, workout_session_id: 5, session })]))

    expect(wrapper.get('[data-testid="session-summary"]').text()).toContain('Squat')
    expect(wrapper.get('[data-testid="session-summary"]').text()).toContain('8×60')
  })

  it('shows the user\'s notes as text, never as markup', () => {
    const wrapper = card(pastDay(null, [makeActivity({ notes: '<img src=x onerror=alert(1)> felt great' })]))

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('[data-testid="activity"]').text()).toContain('<img src=x onerror=alert(1)> felt great')
  })
})

describe('WeekDayCard: opening, suggestions and layout', () => {
  it('is a button that says whether its details are open, and asks to toggle', async () => {
    const closed = card(futureDay(makePlan()), { expanded: false })
    const toggle = closed.get('[data-testid="day-toggle"]')

    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(closed.get('[data-testid="day-body"]').attributes('style')).toContain('display: none')

    await toggle.trigger('click')
    expect(closed.emitted('toggle')).toHaveLength(1)

    const open = card(futureDay(makePlan()), { expanded: true })
    expect(open.get('[data-testid="day-toggle"]').attributes('aria-expanded')).toBe('true')
    expect(open.get('[data-testid="day-body"]').attributes('style') ?? '').not.toContain('display: none')
  })

  it('points to suggested adjustments on that day, singular and plural, and offers to jump to them', async () => {
    expect(card(futureDay(makePlan()), { suggestions: 1 }).get('[data-testid="day-suggestions"]').text()).toBe('1 suggested adjustment to review below')
    const many = card(futureDay(makePlan()), { suggestions: 3 })
    expect(many.get('[data-testid="day-suggestions"]').text()).toBe('3 suggested adjustments to review below')

    await many.get('[data-testid="show-suggestions"]').trigger('click')
    expect(many.emitted('show-suggestions')).toHaveLength(1)
    expect(card(futureDay(makePlan())).find('[data-testid="day-suggestions"]').exists()).toBe(false)
  })

  it('is built to fit a phone: a full-width tap target, wrapping text and no fixed widths', () => {
    const wrapper = card(futureDay(makePlan({ title: 'A very long workout title '.repeat(8), exercises: [makeExercise({ exercise_name: 'Dumbbell Romanian Deadlift with a long name '.repeat(3) })] })))

    expect(wrapper.get('[data-testid="day-toggle"]').classes()).toEqual(expect.arrayContaining(['w-full', 'min-h-14']))
    expect(wrapper.get('[data-testid="day-heading"]').element.parentElement?.classList.contains('break-words')).toBe(true)
    expect(wrapper.get('[data-testid="exercise"] p').classes()).toContain('break-words')
    expect(wrapper.html()).not.toMatch(/\bw-\[\d+px\]|\bmin-w-\[\d+px\]/)
  })
})

describe('WeekDayCard: steps', () => {
  const withSteps = (steps: ReturnType<typeof makeSteps>[], plan: ReturnType<typeof makePlan> | null = null, activities = [] as ReturnType<typeof makeActivity>[]) =>
    makeDay(PAST, plan && { ...plan, planned_date: PAST }, activities, { is_past: true, is_today: false, steps })

  it('shows the count and the date it was logged for, apart from workouts', () => {
    const wrapper = card(withSteps([makeSteps({ steps: 2000, logged_date: PAST })]))

    expect(wrapper.get('[data-testid="steps-count"]').text()).toBe('2,000 steps')
    expect(wrapper.get('[data-testid="steps-date"]').text()).toMatch(/^Logged for /)
    expect(wrapper.find('[data-testid="activity"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('No workout planned or logged')
    expect(wrapper.get('[data-testid="day-explanation"]').text()).toContain('Your steps are below')
    expect(wrapper.find('[data-testid="day-state"]').exists()).toBe(false) // a day with only steps is still "nothing planned"
  })

  it('does not change a planned workout\'s status or heading', () => {
    const wrapper = card(withSteps([makeSteps({ steps: 9000 })], makePlan({ status: 'planned', title: 'Upper Body' })))

    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('Upper Body')
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Not marked done')
    expect(wrapper.get('[data-testid="steps"]').text()).toContain("don't count as a workout or complete a planned session")
  })

  it('lists each entry as logged, says they are not added, and offers no total', () => {
    const wrapper = card(withSteps([makeSteps({ id: 1, steps: 4000 }), makeSteps({ id: 2, steps: 2500 }), makeSteps({ id: 3, steps: 6100 })]))

    expect(wrapper.findAll('[data-testid="steps-count"]').map((e) => e.text())).toEqual(['4,000 steps', '2,500 steps', '6,100 steps'])
    expect(wrapper.get('[data-testid="steps-note"]').text()).toBe('3 separate entries, shown as logged and not added together.')
    expect(wrapper.text()).not.toContain('12,600')
    expect(wrapper.get('[data-testid="day-steps-summary"]').text()).toBe('3 step entries')
  })

  it('a single entry is summarised by its own count', () => {
    expect(card(withSteps([makeSteps({ steps: 6000 })]), { expanded: false }).get('[data-testid="day-steps-summary"]').text()).toBe('6,000 steps')
  })

  it('an entry with no usable count says so, and zero stays zero', () => {
    expect(card(withSteps([makeSteps({ steps: null })])).get('[data-testid="steps-count"]').text()).toBe('Steps logged (count not recorded)')
    expect(card(withSteps([makeSteps({ steps: 0 })])).get('[data-testid="steps-count"]').text()).toBe('0 steps')
  })

  it('sits beside a logged workout without being one', () => {
    const wrapper = card(withSteps([makeSteps({ steps: 7000 })], null, [makeActivity({ logged_date: PAST })]))

    expect(wrapper.findAll('[data-testid="activity"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="steps-entry"]')).toHaveLength(1)
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Unplanned activity')
  })

  it('leaves everything out when there are no steps, and still says nothing was recorded', () => {
    const wrapper = card(pastDay(null))

    expect(wrapper.find('[data-testid="steps"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('No plan or activity recorded')
  })
})

describe('WeekDayCard: as the one day on show', () => {
  it('has no button to open or close it, and its details are always visible', () => {
    const wrapper = card(futureDay(makePlan({ exercises: [makeExercise()] })), { collapsible: false, expanded: false })

    expect(wrapper.find('[data-testid="day-toggle"]').exists()).toBe(false)
    expect(wrapper.find('[aria-expanded]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="day-body"]').isVisible()).toBe(true)
    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('Upper Body')
    expect(wrapper.find('[data-testid="exercise"]').exists()).toBe(true)
  })

  it('is a toggle button by default', () => {
    const wrapper = card(futureDay(makePlan()), { expanded: false })

    expect(wrapper.get('[data-testid="day-toggle"]').attributes('aria-expanded')).toBe('false')
    expect(wrapper.get('[data-testid="day-body"]').isVisible()).toBe(false)
  })
})

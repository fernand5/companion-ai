/**
 * Single source of truth for the app's icon system: every Font Awesome icon
 * used anywhere in the UI is imported once here and referenced by meaning
 * (`icons.nav.dashboard`, `icons.action.delete`, ...) rather than importing
 * `faWhatever` ad hoc in each component. Keeps the icon vocabulary auditable
 * and guarantees the same concept always renders the same icon.
 *
 * All icons use the "solid" style for a single, consistent visual weight —
 * states are told apart by color/opacity (see components), not by mixing
 * outline and filled styles.
 */
import {
  faArrowRight,
  faBan,
  faBandAid,
  faBolt,
  faBullseye,
  faCalendarDay,
  faCalendarDays,
  faChartLine,
  faChevronDown,
  faCircleCheck,
  faCircleHalfStroke,
  faCircleInfo,
  faClipboardList,
  faClockRotateLeft,
  faComments,
  faDumbbell,
  faFire,
  faFloppyDisk,
  faFutbol,
  faGaugeHigh,
  faHeartPulse,
  faPaperPlane,
  faPen,
  faPersonRunning,
  faPlus,
  faRobot,
  faRotateLeft,
  faShoePrints,
  faSpinner,
  faSquare,
  faTrash,
  faTriangleExclamation,
  faUser,
  faWeightScale,
} from '@fortawesome/free-solid-svg-icons'

export const icons = {
  nav: {
    dashboard: faGaugeHigh,
    coach: faComments,
    activity: faClipboardList,
    progress: faChartLine,
    profile: faUser,
  },
  brand: faDumbbell,

  /** Activity log types — reused everywhere an ActivityType is shown. */
  activityType: {
    steps: faShoePrints,
    treadmill: faPersonRunning,
    strength: faDumbbell,
    sport: faFutbol,
    recovery: faHeartPulse,
    weight: faWeightScale,
  },
  activityFallback: faClipboardList,

  /** Workout-plan exercise status — also reused on the buttons that set it. */
  planStatus: {
    completed: faCircleCheck,
    partial: faCircleHalfStroke,
    skipped: faBan,
    pending: faSquare,
  },

  recoveryMetric: {
    energy: faBolt,
    soreness: faBandAid,
    motivation: faFire,
  },

  action: {
    add: faPlus,
    edit: faPen,
    delete: faTrash,
    save: faFloppyDisk,
    reset: faRotateLeft,
    send: faPaperPlane,
    next: faArrowRight,
  },

  chevronDown: faChevronDown,
  info: faCircleInfo,
  warning: faTriangleExclamation,
  loading: faSpinner,
  calendar: faCalendarDays,
  calendarDay: faCalendarDay,
  history: faClockRotateLeft,
  adherence: faBullseye,
  ai: faRobot,
} as const

/**
 * Tailwind size classes, applied directly on <FontAwesomeIcon class="...">.
 * Pick by context, not by feel, so icon weight stays consistent app-wide:
 * - xs  — inline inside a pill/badge next to text-xs content
 * - sm  — default for buttons, list rows, form labels
 * - md  — card/section headers
 * - lg  — nav icons, the app brand mark
 */
export const ICON_SIZE = {
  xs: 'h-3 w-3',
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
  lg: 'h-6 w-6',
} as const

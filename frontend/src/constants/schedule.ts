/**
 * The activity the schedule form suggests. This exact value is what is stored when the person accepts the suggestion
 * unchanged, in every language: the server identifies a schedule entry by its activity name, so the stored value must
 * not depend on the language on screen. Only the words used to SHOW it are translated (profile.schedule.defaultActivity).
 */
export const DEFAULT_SCHEDULE_ACTIVITY = 'Football'

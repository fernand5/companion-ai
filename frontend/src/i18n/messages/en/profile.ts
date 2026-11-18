export const profile = {
  title: 'Profile',
  loading: 'Loading profile…',
  height: 'Height (cm)',
  weight: 'Weight (kg)',
  age: 'Age',
  fitnessLevel: 'Fitness level',
  levels: {
    beginner: 'Beginner',
    intermediate: 'Intermediate',
    advanced: 'Advanced',
  },
  primaryGoal: 'Primary goal',
  primaryGoalPlaceholder: 'e.g. Lose body fat while maintaining muscle',
  secondaryGoal: 'Secondary goal',
  equipment: 'Equipment (comma separated)',
  equipmentPlaceholder: 'dumbbells, exercise mat, treadmill',
  sessionLength: 'Preferred session length (minutes)',
  save: 'Save profile',
  saved: 'Saved.',
  schedule: {
    title: 'Recurring schedule',
    empty: 'No recurring activities yet.',
    activityPlaceholder: 'Activity (e.g. Football)',
    defaultActivity: 'Football',
    intensity: {
      low: 'Low',
      moderate: 'Moderate',
      high: 'High',
    },
    add: 'Add',
  },
}

export type Profile = typeof profile

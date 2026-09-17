/**
 * Axios error responses carry the backend's validation/error message at
 * response.data.message — this is the one place that reads it, so every
 * store falls back to the same shape instead of re-deriving it inline.
 */
export function extractErrorMessage(error: unknown, fallback: string): string {
  const apiMessage = (error as { response?: { data?: { message?: string } } })?.response?.data
    ?.message

  return apiMessage ?? fallback
}

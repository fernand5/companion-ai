import { describe, expect, it } from "vitest";

import { extractErrorMessage } from "@/utils/errors";

/**
 * The backend answers AI-provider failures with { message, code } (the code is additive, Phase 7 / M3c) and words
 * `message` in the user's language. The client shows `message` as given and never branches on the code or the wording.
 */
describe("extractErrorMessage", () => {
  const fallback = "Something went wrong.";

  it("reads message from an error body that now also carries a stable code", () => {
    const error = {
      response: {
        status: 503,
        data: {
          message:
            "The AI coach is temporarily unavailable — please try again in a moment.",
          code: "ai_unavailable",
        },
      },
    };

    expect(extractErrorMessage(error, fallback)).toBe(
      "The AI coach is temporarily unavailable — please try again in a moment.",
    );
  });

  it.each([
    "ai_not_configured",
    "ai_rate_limited",
    "ai_unavailable",
    "progression_unavailable",
  ])("shows a Spanish message unchanged for %s", (code) => {
    const message =
      "El coach de IA no está disponible por el momento; inténtalo de nuevo en un momento.";

    expect(
      extractErrorMessage(
        { response: { status: 503, data: { code, message } } },
        fallback,
      ),
    ).toBe(message);
  });

  it("reads the summary of a validation error whose field messages are in Spanish", () => {
    const data = {
      message: "El campo peso (kg) debe ser un número. (y 1 error más)",
      errors: { weight_kg: ["El campo peso (kg) debe ser un número."] },
    };

    expect(
      extractErrorMessage({ response: { status: 422, data } }, fallback),
    ).toBe(data.message);
  });

  it("falls back when there is no message (an empty 403 body, a network failure)", () => {
    expect(
      extractErrorMessage({ response: { status: 403, data: {} } }, fallback),
    ).toBe(fallback);
    expect(extractErrorMessage(new Error("Network Error"), fallback)).toBe(
      fallback,
    );
    expect(extractErrorMessage(undefined, fallback)).toBe(fallback);
  });
});

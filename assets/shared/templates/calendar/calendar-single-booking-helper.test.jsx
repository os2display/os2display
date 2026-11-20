import { beforeEach, describe, expect, it } from "vitest";
import {
  getBookingRequestId,
  clearBookingRequestId,
} from "./calendar-single-booking-helper.jsx";

describe("instant booking request identifiers", () => {
  const interval = { resource: "room@example.com", durationMinutes: 30 };

  beforeEach(() => localStorage.clear());

  it("reuses the identifier across retries, even with refreshed option timestamps", () => {
    const id = getBookingRequestId("slide-a", interval);
    expect(id).toMatch(/^[0-9A-Z]{26}$/);
    expect(getBookingRequestId("slide-a", { ...interval, from: "later" })).toBe(
      id,
    );
  });

  it("uses different identifiers for different slides, rooms and durations", () => {
    const id = getBookingRequestId("slide-a", interval);
    expect(getBookingRequestId("slide-b", interval)).not.toBe(id);
    expect(
      getBookingRequestId("slide-a", { ...interval, durationMinutes: 60 }),
    ).not.toBe(id);
    expect(
      getBookingRequestId("slide-a", {
        ...interval,
        resource: "other@example.com",
      }),
    ).not.toBe(id);
  });

  it("creates a fresh identifier after success or a definitive rejection", () => {
    const id = getBookingRequestId("slide-a", interval);
    clearBookingRequestId("slide-a");
    expect(getBookingRequestId("slide-a", interval)).not.toBe(id);
  });
});

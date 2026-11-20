import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { IntlProvider } from "react-intl";
import CalendarSingleBooking from "./calendar-single-booking.jsx";

const slide = {
  "@id": "/v2/slides/slide-a",
  feed: { configuration: { resources: ["room@example.com"] } },
};

const optionsResponse = () => ({
  ok: true,
  json: async () => ({
    resource: "room@example.com",
    from: new Date().toISOString(),
    options: [
      { durationMinutes: 30, to: new Date(Date.now() + 1800000).toISOString() },
    ],
  }),
});

const renderBooking = () =>
  render(
    <IntlProvider locale="da" defaultLocale="da">
      <CalendarSingleBooking
        slide={slide}
        content={{ instantBookingEnabled: true }}
        calendarEvents={[]}
        getTitle={(title) => title}
        run={1}
      />
    </IntlProvider>,
  );

describe("calendar instant booking", () => {
  beforeEach(() => {
    localStorage.clear();
    localStorage.setItem("apiToken", "test-token");
    localStorage.setItem("tenantKey", "test-tenant");
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it("preserves the existing action payload and displays a successful booking", async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(optionsResponse())
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          status: 201,
          interval: { to: new Date(Date.now() + 1800000).toISOString() },
        }),
      });
    vi.stubGlobal("fetch", fetch);
    renderBooking();
    fireEvent.click(await screen.findByRole("button", { name: "30 min" }));
    await screen.findByText(/Lokalet er straksbooket indtil/);

    const request = JSON.parse(fetch.mock.calls[1][1].body);
    expect(request.implementationClass).toBe(
      "App\\InteractiveSlide\\InstantBook",
    );
    expect(request.action).toBe("ACTION_QUICK_BOOK");
    expect(request.data.interval.resource).toBe("room@example.com");
    expect(request.data.interval.durationMinutes).toBe(30);
    expect(request.data.transactionId).toMatch(/^[0-9A-Z]{26}$/);
    expect(
      localStorage.getItem("pendingInstantBooking:/v2/slides/slide-a"),
    ).toBeNull();
  });

  it("reuses the request identifier after an uncertain network failure and remount", async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(optionsResponse())
      .mockRejectedValueOnce(new TypeError("Network error"))
      .mockResolvedValueOnce(optionsResponse())
      .mockResolvedValueOnce({ ok: false, status: 409 });
    vi.stubGlobal("fetch", fetch);
    const first = renderBooking();
    fireEvent.click(await screen.findByRole("button", { name: "30 min" }));
    await screen.findByText(/Straksbooking fejlede. Prøv igen/);
    const id = JSON.parse(fetch.mock.calls[1][1].body).data.transactionId;
    first.unmount();
    renderBooking();
    fireEvent.click(await screen.findByRole("button", { name: "30 min" }));
    await screen.findByText(/Straksbooking fejlede. Intervallet er optaget/);
    expect(JSON.parse(fetch.mock.calls[3][1].body).data.transactionId).toBe(id);
  });

  it("displays failed availability requests without offering booking durations", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({ ok: false, status: 502 }),
    );
    renderBooking();
    await screen.findByText(/Straksbooking fejlede. Prøv igen/);
    await waitFor(() =>
      expect(screen.queryByRole("button")).not.toBeInTheDocument(),
    );
  });
});

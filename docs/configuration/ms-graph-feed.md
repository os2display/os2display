# MS Graph Feed (MsGraphFeedType)

`App\Feed\MsGraphFeedType` shows meetings from Microsoft 365 room (resource) mailboxes on calendar
slides. It outputs the shared **calendar** output model (`FeedOutputModels::CALENDAR_OUTPUT`), so it
renders in the same templates as `CalendarApiFeedType`.

The feed type needs **no environment variables** — everything is per tenant and stored encrypted in
the feed source's secrets.

## What you need in Azure/Entra ID

Create an **app registration** in the Azure portal (Entra ID → App registrations → New registration).
It needs no redirect URI; the feed authenticates with the OAuth2 _client credentials_ flow, i.e. as
the application itself with no signed-in user.

From the app registration you need three values:

| Secret key      | Where in the portal                                    | Note                                                                                                                               |
| --------------- | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| `tenant_id`     | Overview → **Directory (tenant) ID**                   | A GUID. A verified domain name (`contoso.onmicrosoft.com`) works too.                                                              |
| `client_id`     | Overview → **Application (client) ID**                 | This _is_ the "App ID" — the portal uses both names for the same GUID.                                                             |
| `client_secret` | Certificates & secrets → New client secret → **Value** | Copy the _Value_, not the _Secret ID_. It is only shown once and it expires (max 24 months) — the feed stops working when it does. |

### API permissions (application permissions, admin consent required)

| Permission       | Needed for                                                                                    |
| ---------------- | --------------------------------------------------------------------------------------------- |
| `Place.Read.All` | Listing room lists (locations) and rooms, i.e. the dropdowns in the admin and the room names. |
| `Calendars.Read` | Reading the meetings of the room mailboxes.                                                   |

Pick **Application permissions**, not Delegated — there is no user in this flow. Then click
**Grant admin consent**.

> ⚠️ `Calendars.Read` as an application permission grants read access to **every** mailbox in the
> tenant, not just the rooms. Narrow it in Exchange Online to only the room mailboxes used for
> signage:
>
> ```powershell
> New-DistributionGroup -Name "Display Rooms" -Alias displayrooms -Type Security -Members room1@example.com
> New-ApplicationAccessPolicy -AppId <client_id> -PolicyScopeGroupId displayrooms@example.com `
>     -AccessRight RestrictAccess -Description "OS2display signage"
> ```

### Make the room subjects readable

Room mailboxes delete the meeting subject by default, which would leave every event without a title.
The feed falls back to "Optaget" in that case. To show the real subject (and the organizer), run per
room mailbox:

```powershell
Set-CalendarProcessing -Identity room1@example.com -DeleteSubject $false -AddOrganizerToSubject $false
```

Consider whether that is acceptable privacy-wise before putting it on a public screen.

### Room lists

The "locations" dropdown lists **room lists** (`Get-DistributionGroup -RoomList` in Exchange). If the
organisation has not organised its rooms into room lists, leave the selection empty — the feed then
offers all rooms in the tenant.

## Setting up the feed source

1. Admin → "Datakilder" → create a feed source of type **MS Graph kalender**.
2. Fill in Directory (tenant) ID, Application (client) ID and Client Secret, and save.
3. Re-open the feed source — the **Lokationer** dropdown is now populated from Graph. Pick the room
   lists the editors may use, or leave it empty for all rooms.

Secrets stored on the feed source:

```json
{
  "tenant_id": "11111111-2222-3333-4444-555555555555",
  "client_id": "66666666-7777-8888-9999-000000000000",
  "client_secret": "the secret value",
  "locations": ["building-a@example.com"]
}
```

Only `locations` is readable through the API; the three credentials are write-only.

## Per-feed configuration

When an editor creates a calendar slide on this feed source they get:

| Key                    | Input                       | Meaning                                                                               |
| ---------------------- | --------------------------- | ------------------------------------------------------------------------------------- |
| `resources`            | `multiselect-from-endpoint` | The rooms to show. Populated from the rooms of the selected room lists. **Required.** |
| `cache_expire_minutes` | number                      | How long Graph data may be reused. 1–1440 minutes, default 5.                         |

## Data window

`getData()` returns the events from **midnight today through midnight seven days later** — today plus
the following six calendar days — computed in the application time zone and queried from Graph as
UTC. Cancelled events are dropped and recurring meetings are expanded (the Graph `calendarView`
endpoint is used, not `events`).

Output (calendar output model):

```json
[
  {
    "id": "01HZX0P8Y1N5V4S3Q2R1T0W9ZA",
    "title": "Projektmøde",
    "startTime": 1719820800,
    "endTime": 1719824400,
    "resourceTitle": "Mødelokale 1",
    "resourceId": "room1@example.com"
  }
]
```

`startTime`/`endTime` are Unix timestamps; `resourceTitle` is the room's display name (falling back to
its email address if `Place.Read.All` is missing).

## Caching

Microsoft Graph throttles per app and per mailbox, and a wall of screens polls the same feed, so
everything is cached in the dedicated `ms.graph.cache` pool:

| Cached                 | TTL                                                    |
| ---------------------- | ------------------------------------------------------ |
| Access token           | `expires_in` minus 5 minutes (≈55 minutes)             |
| Room lists and rooms   | 1 hour                                                 |
| Calendar view per room | `cache_expire_minutes` of the feed (default 5 minutes) |

Cache keys include a fingerprint of the credentials, so rotating the client secret immediately
invalidates the cached token and data.

On top of this, `FeedService` caches the assembled feed response per feed (default 5 minutes, or the
feed's `cache_expire` in seconds if set) and caches an empty result for 30 seconds when the feed
fails — so a Graph outage does not hammer the API.

## Straksbooking (write access)

The **Enkelt lokale med booking** layout can create a **Straksbooking** event directly in the
selected room calendar, starting now for 15, 30 or 60 minutes. This is optional; read-only feed
sources continue working without calendar write permission.

### Microsoft permissions

The application needs **Calendars.ReadWrite** access to the bookable room mailboxes. This includes
calendar reading; a separate `Calendars.Read` grant is not needed for those same mailboxes.
`Place.Read.All` remains necessary for room discovery and validation.

Prefer [Exchange Online RBAC for Applications](https://learn.microsoft.com/exchange/permissions-exo/application-rbac)
to assign **Application Calendars.ReadWrite** with a resource scope containing only the permitted
room mailboxes. Unscoped Entra calendar permissions are additive: remove overlapping unscoped
grants when relying on RBAC for mailbox restrictions. Alternatively, existing deployments can
restrict Entra application grants with an Application Access Policy.

Room lists only filter the rooms offered by OS2display; they do not restrict the Graph app's actual
mailbox permissions.

After adding an Entra application permission, the cached access token can retain its previous
permissions until it expires. An administrator can request a fresh token by clearing the MS Graph
cache pool with `task compose -- exec phpfpm bin/console cache:pool:clear ms.graph.cache`.
This also clears cached Graph data for all sources; it does not change stored credentials.

### Enable in OS2display

1. Keep the existing `tenant_id`, `client_id` and `client_secret` on the MS Graph feed source.
   No username, password, organizer mailbox, KeyVault entries or new environment variables are needed.
2. Run `task compose -- exec phpfpm bin/console app:tenant:configure`, select the tenant and configure
   `App\InteractiveSlide\InstantBook`. For a tenant using only MS Graph feed-source credentials,
   answer yes to **Enable MS Graph feed-source booking without configuring Calendar API credentials**. This creates the tenant-level
   interactive feature configuration without requesting Calendar API credentials.
3. If this tenant also uses Calendar API-backed booking, **keep its existing InstantBook configuration**.
   The MS Graph activation option also preserves existing configuration. MS Graph slides automatically use their own feed
   source credentials, while Calendar API slides continue using their existing KeyVault credentials.
4. Configure the slide with exactly one room, select **Enkelt lokale med booking** and enable
   **Aktivér straksbooking**.
5. Test on an authenticated screen client. Booking is deliberately unavailable in the admin preview.

The server validates the tenant, slide settings, selected room and source room-list restrictions
before offering durations or writing events. Availability is read live using
`POST /users/{roomEmail}/calendar/getSchedule`, independently of the display feed cache and
`INSTANT_BOOK_BUSY_INTERVALS_SOURCE`. Unknown or errored availability prevents booking.

Events are created using `POST /users/{roomEmail}/events`, with `showAs: busy` and no attendees.
This is a direct calendar entry, **not** an invitation awaiting Exchange room acceptance.

### Conflicts, retries and caching

- The backend serializes its MS Graph booking attempts per tenant with a shared database row lock,
  checks live availability immediately before a new booking, and rate-limits new attempts per room.
- Direct calendar writes do **not** offer an atomic "create only if free" guarantee. Someone booking
  through Outlook or another application can still race the availability check. If Exchange-enforced
  accept/decline rules are required, use a different workflow involving an organizer mailbox and
  a resource invitation.
- The screen retains a request identifier across retries, including page reloads. The backend retains
  request times and results for 24 hours and reuses Graph's `transactionId` after an uncertain timeout.
  Retry the same room and duration if a network error leaves the result unknown; do not assume that
  an error means no event was created.
  Multi-host deployments should use a shared cache backend such as Redis or DBAL so all workers
  share these request records and rate limits.
- After successful creation, room calendar cache entries and assembled feed responses using the room
  in the same OS2display tenant are invalidated, including sources using different credentials.
  Tokens, room-directory data and unrelated feeds are retained. The MS Graph booking path does not
  cache booking options.
- The initiating screen shows its booking immediately using the existing local booking confirmation.
  Other screens, and the initiating screen's event list, receive new data on their next feed refresh;
  invalidation does not push updates into already loaded browser state.

The existing Calendar API booking path, credentials, configured availability source and supported
durations remain unchanged.

## Troubleshooting

Room discovery uses explicit pages of 100 and `$skip` when Graph omits `@odata.nextLink`.
An empty room-list selection makes all discovered rooms available, regardless of bookings.

Failures are logged on the `feed` channel. If room lists or rooms cannot be loaded in the admin,
the standard persistent error toast shows a safe connection error and suggested checks.
Raw Microsoft responses and credentials are not exposed. These lookup failures return HTTP 502;
successful lookups still return the usual array of options, including an empty array when no
rooms are available.

Messages distinguish an Entra ID token-request rejection (HTTP 400/401) from a bad Graph request
(HTTP 400), a rejected Graph access token (HTTP 401), missing access (HTTP 403), and throttling
(HTTP 429). Share the message and timestamp with your system administrator; the `feed` log
contains the underlying exception, including the OAuth error code for token-request rejections.

| Symptom                                          | Likely cause                                                                          |
| ------------------------------------------------ | ------------------------------------------------------------------------------------- |
| Dropdowns are empty after saving the feed source | Wrong credentials, missing admin consent, or `Place.Read.All` not granted.            |
| `token request was rejected … invalid_client`    | Wrong client secret, or the secret has expired.                                       |
| Rooms list fine but a feed returns no events     | `Calendars.Read` missing, or an application access policy excludes that room mailbox. |
| All events are titled "Optaget"                  | The room mailbox deletes subjects — see "Make the room subjects readable".            |
| HTTP 429 from Graph                              | Raise `cache_expire_minutes` on the feeds.                                            |

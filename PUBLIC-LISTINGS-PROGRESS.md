# Public listings implementation checkpoint

Updated: 10 November 2026. Status: `0d4f2e2` (website settings, below) was
deployed live on 10 November 2026, including migration
`2026_11_07_015444_add_public_website_settings`. File backup:
`/root/haven-deploy-7FvKQBPR`; central database backup:
`/var/www/haven-suites/storage/app/private/deploy-backups/central-20261110-081059-88616e2e.sqlite`.
The prepare preview showed only `0d4f2e2` files, so the earlier pilot
(`810ab8f`) and media/SEO (`4092dc6`) releases were already live.
`/usr/local/bin/haven-prepare` had lost its execute bit and was restored with
`chmod 755`.

## 10 November: Phase A, readable references and "Track my enquiry"

Agreed with the user: references `HS-2611-042` (prefix, year+month, monthly
number); tracking lookup by reference + last 4 phone digits; logo will be WebP;
locations are city + locality (e.g. Nyali in Mombasa); listing codes are our own
(WordPress codes reused where wanted). Collections/property types: proposed
defaults until the user changes them. Full roadmap: Phases A (this), B brand and
navigation, C filters and listing fields, D city/collection pages and WordPress
redirects, E listing quality and photo speed, F backups, tenant migrations, CI.

- Migration `2026_11_10_131035_add_enquiry_reference_numbering`: `tenants.reference_prefix`
  (2–5 letters, set in Company settings, default = company initials),
  `enquiry_reference_counters` (per company and YYMM, locked increment), and
  `public_enquiry_receipts.reference` unique per company instead of globally.
- `EnquiryTracking::newReference()` issues `HS-YYMM-NNN` (tenant timezone; 4+
  digits after 999). Earlier `ENQ-XXXXXX` references keep working everywhere.
- Confirmation page: large reference with copy button, "sent by SMS to the
  number ending in 678" only when the SMS was queued or sent, what-happens-next
  steps with response time, Track and WhatsApp follow-up (reference prefilled).
- `/stays/{company}/track` (`public-stays.track`): reference + last 4 digits ->
  redirect to the private `/e/{code}` page. Generic failure message; 5 POSTs per
  minute per visitor; a reference locks for an hour after 5 wrong attempts.
  Older website enquiries without a tracking link get one on first lookup.
- Menu target "Track my enquiry" (default menu and editor) and a footer link.
- Reserved listing slugs: track, enquiries, sitemap, in, collections, search, contact.
- Asset versions bumped to v=5.
- Verification: full suite 584/587 (the same three pre-existing failures).

## 10 November (later): booking lock, phone input, enquiry tracking

Deployed live as `ae1c795` on 10 November 2026 (includes `2313e9c`, `f0d05ed`,
`494085c` and the landline fix `ae1c795`); migration
`2026_11_10_121007_create_enquiry_tracking_links_table` ran. File backup:
`/root/haven-deploy-9Q0boplB`; central database backup:
`/var/www/haven-suites/storage/app/private/deploy-backups/central-20261110-095646-a8e5b1ef.sqlite`.
Post-deploy check: listing page 200 with `+254` prefill and CSS v4; unknown
tracking code returns 404; WhatsApp links hidden until the stored invalid
number is corrected.

- `2313e9c` Partner bookings now lock the `partner_apartments` row before the
  overlap check, both when creating (`Haven\Reservations::store`) and amending
  (`BookingAmendments::save`) bookings, matching managed-unit locks.
- `f0d05ed` `App\Support\PhoneNumber` normalises phone numbers (Kenya default):
  `0712…`, `254…`, `+254 0712…`, `00…` become `+254…`; Kenyan numbers need
  exactly nine digits after +254; an untouched `+254` counts as blank. Used for
  enquiry SMS, website/listing WhatsApp numbers, guest contact phone and
  WhatsApp links. Website settings, listing editor and guest enquiry form
  prefill an editable `+254`. An invalid stored WhatsApp number (e.g. the live
  `+25470289894`) now hides WhatsApp links until corrected.
- Enquiry tracking: central table `enquiry_tracking_links` (migration
  `2026_11_10_121007_create_enquiry_tracking_links_table`), service
  `App\Services\EnquiryTracking`, public route `GET /e/{code}` (`enquiry.track`,
  12-character code, 30 requests/minute, noindex, hashed code plus encrypted
  copy for staff display, valid 180 days or 30 days after check-out).
  - Every acknowledgement SMS (website and staff-sent) now reads: "Hi {first
    name}, thanks for contacting {company}! Your enquiry ref is ENQ-XXXXXX.
    Track its progress here: {link}" (one SMS for typical names).
  - References are `ENQ-` plus six characters without look-alikes. New website
    enquiries use the same short reference on the confirmation page; older
    receipts keep their long references.
  - The tracking page maps pipeline stages to guest wording (Received,
    Reviewing, Options, Payment, Confirmed; Lost shows "Enquiry closed") and
    shows apartment, dates, guests and contact options only; no notes, staff
    names, budget or phone numbers.
  - Confirmation page links to tracking; staff enquiry page shows reference and
    tracking link; pipeline search finds `ENQ-` references.
- After deployment: correct the WhatsApp number in Website settings, send one
  test enquiry to a staff phone and open the SMS link.

## 10 November: website settings, WhatsApp and menu

- New staff page `/t/{tenant}/public-listings/settings` (route
  `public-listings.settings`) holds public site settings, search appearance,
  WhatsApp contact and the website menu. The listings index links to it.
- Site-wide and per-listing `seo_title`, `seo_description`, `whatsapp_number`
  (`+` and country code) and `whatsapp_message` (`{listing_title}`,
  `{listing_url}`, `{listing_reference}` placeholders). Listing values override
  site values; blank values fall back to the existing descriptive defaults.
- Website menu: up to three links to Home / Find a stay / Contact, each target
  once, stored as normalised JSON in `public_sites.navigation_links`; invalid
  stored data falls back to the default menu. `App\Services\PublicWebsiteLinks`
  builds canonical, enquiry and WhatsApp links. A WhatsApp chat does not create a
  pipeline enquiry.
- Listing pages gain "Enquire now" / "WhatsApp us" quick actions; enquiry links
  keep selected dates/guests and malformed query values cannot crash the page.
- Listing editor and settings saves support JSON (progress-indicator) responses
  as well as normal redirects. Forms that omit the new fields keep stored values.
- Fixed: the listing page crashed (`Undefined variable $bedroomLabel`) because
  inline `@php(...)` directives preceded a `@php ... @endphp` block in
  `public_stays/show.blade.php`; Blade compiled them as one block. They are now
  one block. Other views only use inline directives after their blocks.
- New central migration: `2026_11_07_015444_add_public_website_settings.php`.
- Verification: website settings, integration and listing admin tests passed
  (**23 tests / 188 assertions**). Full suite: **547 of 550 passed**. The three
  failures (`BusinessWorkflowTest::test_unlinked_staff_account_form_renders`,
  `BusinessWorkflowTest::test_reservation_invoice_payment_creates_receipt_and_updates_displayed_balance`,
  `InvestorPortalTest::test_invites_use_owner_role_and_only_verified_matching_recipient_can_accept`)
  fail identically on `4092dc6` and are unrelated pre-existing failures to fix
  separately. Pint, Blade compilation and JS syntax checks passed.
- Deployed. Next: fill in website settings (search text, WhatsApp number, menu)
  at `/t/haven/public-listings/settings`.

## Objective

Add a public accommodation catalogue backed by existing physical unit records,
shared availability and website enquiries that enter the sales pipeline and
receive acknowledgement SMS. Keep the current WordPress website running until
listings have been mapped and reviewed.

Latest user scope: work only with the **11 existing app apartments** (eight
managed and three partner apartments). The 108-listing WordPress import is set
aside for this pilot. Do not create or map extra inventory from those listings.

## 7 November: listing media, usability and SEO

- User requested a professional review of listing content/UX/SEO, a photo gallery
  and one or two YouTube-hosted video previews per listing.
- Fixed the mobile bug that hid photos 2/3 without a way to open them. All photos
  now have a visible gallery entry point, sequential modal navigation, count,
  captions, thumbnails, keyboard/Home/End/Escape controls, swipe support and focus
  restoration. Native photo links and an all-photo grid remain usable without JS.
- Added two optional YouTube URL/title slots in the staff listing editor.
  Canonical IDs/titles are stored in nullable `public_listings.youtube_videos`;
  untrusted hosts, embed HTML, playlists, malformed URLs and duplicate IDs fail
  validation. Requests omitting the field preserve existing videos; explicit
  empty slots remove them. Audit snapshots include video changes.
- Public video previews use click-to-load privacy-enhanced YouTube players,
  direct YouTube fallback links, lazy thumbnails, minimum 200px player height
  and only one active player. The gallery stops active video playback. No API
  credentials or metadata requests are needed. Real playback depends on a public
  or unlisted video with embedding allowed; no guest videos have been added.
- Added starting prices near the heading, section navigation and conditional
  amenities/video sections, retaining the existing enquiry and calendar flows.
  The floating mobile enquiry action hides while gallery/video controls or the
  form are visible, and is a JS enhancement so it cannot cover no-JS controls.
- Improved clean canonical URLs, filtered catalogue noindex, pagination URLs,
  plain-text descriptions, Open Graph/Twitter share cards, accommodation/image/
  breadcrumb JSON-LD, and published-only image sitemaps with real modification
  dates. Private previews omit public share images/schema. VideoObject markup
  is intentionally omitted because verified upload dates are unavailable; no
  rich-video search eligibility, fabricated ratings or firm offers are claimed.
- New migration: `2026_11_07_011234_add_youtube_videos_to_public_listings.php`.
- Verification: **144 tests / 951 assertions passed** in the coordinated media,
  SEO, publishing, public pages, enquiry, partner, import and availability run.
  After the final heading/contrast adjustments, public rendering/SEO tests passed
  again: **28 tests / 282 assertions**. Pint and JS syntax checks passed.
- Browser review: **29 media checks plus 11 final layout checks passed** at
  desktop 1440px and mobile 390px/320px,
  including gallery input methods, focus restoration, zero iframes before user
  selection, player replacement and no-JS fallbacks. YouTube network playback was
  blocked in the isolated browser fixture. No real records or messages changed.
- Screenshots/report: local TEMP directory
  `haven-media-review-30a491556df5441ca257585cf078c38e`.
- Current live public catalogue returned HTTP 404 during this review. Review used
  actual Blade/CSS/JS with isolated fake inventory, not live guest/company data.
- Next: deploy the new commit with `haven-prepare` followed by
  `bash /home/deploy/haven-repository/deploy-haven.sh EXPECTED_COMMIT`, then add
  actual apartment photos/captions and optional YouTube tours in the editor.
  Public site/listings still need explicit enabling/publication if not done yet.

## Starting point

- Branch: `migration/laravel-13`.
- Starting commit: `25db3c0` (password changes, enquiry SMS and pipeline counts).
- Existing staff application, calendars, pipeline and SMS must keep working.
- Preserve the unrelated `Pending tasks and Ideas.md` and `public/Pass` files.
  Never read, stage or deploy `public/Pass`.
- No live records, DNS or external SMS have been changed for this feature.

## First release

1. Central publishing tables and staff listing/site/photo management.
2. Public URLs under `/stays/{company}`; existing staff login/home remain intact.
3. Responsive catalogue, filters, listing galleries, date selection and safe
   availability calendar.
4. Explicit owner/maintenance closures shared with booking validation and iCal
   export. External imported blocks must not be exported back to their source.
5. Public enquiry capture, assignment, follow-up task, private receipt reference,
   duplicate/spam controls and acknowledgement SMS after commit.
6. Import/mapping preparation and URL migration support, with listings initially
   unpublished until content, unit mapping and permissions are reviewed.
7. Tests, formatting, deployment instructions and GitHub push.

## Implemented

- Staff publishing, site settings, eligible sales assignment, gallery upload,
  image resizing/metadata removal, private preview and audited edits.
- Guest catalogue, destination/date/guest/bedroom/amenity/price filters, pagination,
  listing galleries, month calendar, signed enquiry form, mobile enquiry shortcut,
  public sitemap and search metadata.
- Shared availability from local bookings, imported calendar blocks and explicit
  owner/repair closures. Booking creation/amendments and local iCal exports respect
  closures; exports do not re-export imported blocks.
- Anonymous enquiries create a pipeline entry, assigned follow-up task, private
  confirmation reference and acknowledgement SMS intent in one central transaction.
  Submission has CSRF, a session-bound timed signature, honeypot, rate limits and
  duplicate protection. SMS dispatch follows commit and avoids uncertain retries.
- Staff can see the source listing and carry the requested active unit into the
  booking form. Public activity is attributed to the system, not a fictitious user.
- Draft-only CSV import with validation, authorised actor, dry run and audit trail.
- Admin navigation and production route wiring are complete.

## Contracts agreed so far

- Publishing migration: `2026_11_06_100045_create_public_listing_tables.php`.
- Public listing photos route: `public-stays.photo`, parameters company/slug/photo.
- Staff publishing route prefix: `/t/{tenant}/public-listings`.
- Staff closure routes: `/t/{tenant}/availability/closures`.
- Public enquiries: POST `/stays/{company}/{slug}/enquire`, signed form context,
  CSRF protection, honeypot, per-phone/IP limits and idempotency.
- Public enquiries never create or hold a booking and never expose matching
  customers' details.
- Prices are starting rates, with final pricing confirmed by staff.

## Verification

Verification completed:

- 11-apartment pilot follow-up: **177 tests, 1,422 assertions passed** together
  across public admin/partner publishing, visitor pages, enquiry capture, partner
  availability, imports, managed availability, calendar sync/export, booking
  amendments, sales pipeline, enquiry SMS, guest communications, manual SMS and
  role visibility. Pint passed. Bash deployment and browser JavaScript syntax
  checks passed. No real messages or live inventory changes were made.
- Mapping follow-up: WebsiteListingSourceTest, PlanPublicListingsTest,
  PublicListingUnitMatcherTest and existing ImportPublicListingsTest passed
  together: **34 tests, 251 assertions**. Pint and `git diff --check` passed.
  A real read-only website fetch generated all 108 review rows against the local
  four-unit inventory. No application records, live content or messages changed.
- PublicListingAdminTest: 11 tests, 98 assertions passed.
- UnitAvailabilityTest, CalendarExportTest, ExternalCalendarTest and
  BookingAmendmentsTest: 41 tests, 337 assertions passed.
- PublicEnquiriesTest: 18 tests, 152 assertions passed after resuming.
- Existing EnquiryAcknowledgementsTest: 21 tests, 147 assertions passed.

- Final combined release regression run: **190 tests, 1,515 assertions passed**.
- Pint completed; Blade views compiled; Node syntax check passed for
  `public-stays.js`.
- Positive-price publication guard and fallback-price display tests passed.
- Browser review: desktop 1440px and actual mobile emulation 390px. Catalogue,
  enquiry form, gallery and calendar render without horizontal overflow. White
  fixture photos were used for review; no real guest information or SMS was used.
- Temporary visual capture hooks were removed from the tests.

## Credit interruption and current resume point

The usage limit prevented the large patch adding public catalogue/detail views,
CSS and JavaScript from executing. Those files did not exist when work resumed.
The publishing/admin schema, calendar/closure backend, public enquiry backend,
routes and public layout/receipt had been saved successfully.

The public catalogue/detail/pagination/card views, stylesheet and JavaScript are
now saved. Long-stay search validation, standalone maximum-price filtering and
date horizons have been corrected. Draft CSV import and targeted catalogue/image
lookups are implemented and passed 20 focused admin/import tests (155 assertions).
Public enquiry rule-change checks and requested-unit booking hand-off passed
18 public enquiry tests (152 assertions). Public visitor page tests are running.
This checkpoint is included with the implementation release. Use `git log -1`
and `git status` to identify the exact commit and any later changes when resuming.

## Exact stopping point / next work

The user verified live inventory, then requested that the pilot use only these:

- Managed: 557 F2 / Coral Gables; 558 F5 / Coral Gables; 559 R92 and 560 R61 /
  Third Avenue Beach Apt; 561 A1503 / Winchester Garden Apt; 562 A110 / Pinecrest
  View; 563 Q103 and 564 Q93 / Third Avenue Beach Apt.
- Partners: 2 A1.9 / Amalia Apartments (Syokimau); 3 B09 / Baharini Cottages
  (Diani); 4 PH56 / Nursan Homes Muthaiga (Nanyuki).

Partner support is now implemented across catalogue editing/public pages,
calendar queries, enquiry capture, pipeline identity and booking preselection.
Managed unit IDs and partner IDs remain separate, including when integer IDs
collide. Owner contacts and internal notes are never projected publicly.
Partner calendars show app-recorded booked nights and require owner confirmation;
there is no connected owner iCal feed, so they are never advertised as freshly
verified availability. Their public rate may be "Price on request".

New central migration:
`2026_11_06_142451_extend_public_listings_for_partner_apartments.php`.
It adds partner source references and permits incomplete private capacity/stay
fields. Publication still requires explicit guest details and a photograph.
Rollback refuses to discard partner enquiry identity or incomplete drafts.

`Prepare listings from existing apartments` on `/t/haven/public-listings` creates
only missing active apartment drafts using the logged-in administrator as the
audit actor. It is idempotent and changes no inventory, existing listings, prices,
site enablement or messages. Descriptions, city, capacity, minimum nights and
photos must be supplied/reviewed before publication. CLI equivalent:
`php artisan haven:prepare-public-catalogue haven --actor=USER_ID --dry-run`.
Remove `--dry-run` to prepare missing private drafts.

Deployment helper `deploy-haven.sh` is in the repository root. Run the existing
`haven-prepare`, then run this helper from `/home/deploy/haven-repository/` with
the expected new commit hash. It validates the checkout, takes file/central
SQLite backups, copies code, installs dependencies, migrates and refreshes PHP.
It never prepares or publishes the apartment drafts automatically.

Next: push this follow-up, deploy its central
migration, then use the prepare button and complete/review the 11 private drafts.
The public site and individual listings still need to be explicitly enabled.

Earlier deployment/mapping evidence (historical; the 108-listing import is no
longer the pilot task):

The user confirmed successful live deployment of `67392b3` on 6 November 2026.
Application is online. File backup: `/root/haven-deploy-Xr6tSa9O`.
The live planning command completed with 108 rows: missing_code 2, unmatched 98,
candidate_only 6, duplicate_code 2, and no exact matches. Private server review:
`/var/www/haven-suites/storage/app/private/listing-imports/1/20261106-141037-Cta2ccDI/review.csv`.
No listings were imported/published and no rates/messages were changed by planning.
The subsequent inventory audit led to the 11-apartment scope above.

Code, tests and private-preview workflows are complete. The user confirmed that
old website listing codes such as 064/101 match app unit numbers, but the actual
live inventory had no full-code matches. The app schema has `unit_number` but no separate
website listing-code field; do not substitute numeric database IDs for codes.

The local database contains only four apartments (F5, F6, Q 103 and R61), so it is
not a reliable copy of the current live inventory. A read-only local plan found
108 website listings: 101 unmatched locally, three numeric-suffix candidates,
two entries sharing code 13/013, and two without codes. No exact local matches
were selected. These counts describe the limited local copy, not live inventory.
Do not treat a suffix candidate such as 103/Q 103 as a verified full-unit match.

No current website listings or photos have been copied into live app records.
No site has been enabled, no DNS has changed, and no real SMS has been sent by
the implementation or tests. These are operational activation steps, not missing
public-site code. Future instant payments, booking holds, guest accounts and
email/WhatsApp automation remain separate follow-on features as originally planned.

## Deployment and activation

Central migrations (copy the new code before running migrations):

- `2026_11_06_100045_create_public_listing_tables.php`
- `2026_11_06_100537_add_public_enquiry_capture.php`
- `2026_11_06_142451_extend_public_listings_for_partner_apartments.php` (pilot follow-up)

Deploy with the existing prepare/copy/maintenance workflow and a central database
backup, run `php artisan migrate --force --no-interaction`, compile views, reload
PHP-FPM and bring the application online. PHP GD is needed for image uploads;
the application reports a clear error if image processing is unavailable.

Then sign in with property-management access and open:

`/t/haven/public-listings`

Choose an eligible salesperson and public contact details. Create listings linked
to real units, upload the correct apartment photos, review private previews, and
set approved listings to Published. Enable the public site when ready.

Public pilot address after activation:

`https://app.havensuitesbnb.com/stays/haven`

Public sitemap: `/stays/haven/sitemap.xml`.

Closure management: `/t/haven/availability/closures`.

The live company has not been enabled or populated automatically. The existing
WordPress website, DNS and training pages are unchanged.

## Preparing a mapped import

Generate a review CSV against the intended company's inventory first:

`php artisan haven:plan-public-listings haven --no-interaction`

This reads the public WordPress REST source at havensuitesbnb.com and the chosen
company's apartments. It writes a uniquely named private review CSV under
`storage/app/private/listing-imports/{tenant-id}/`, prints its path and mapping
counts, and changes no application records. It can run from the prepared
repository before the new central migrations have been deployed.

The command currently supports only Haven's website and company. Exact complete
unit numbers are matched without case/whitespace differences; purely numeric
codes ignore leading zeroes. Duplicate website codes, duplicate apartment numbers,
inactive units, missing codes and suffix-only candidates require manual review.
Sale labels conflicting with nightly prices and bedroom discrepancies are flagged.
Descriptions become plain text; only public metadata is requested, and photos
are referenced by media ID without downloading them.

The review CSV includes the import headers below plus source IDs, matching status,
candidates, website rates, photo references and review notes. Guest capacity and
minimum stay remain blank because the website does not provide verified values.
`starting_price` remains blank to retain the app rate; website prices and currency
must be reviewed rather than silently replacing app prices. Fill required values,
confirm photos/content, and resolve/remove unmatched rows before validation.
Nothing is imported or published by the planning command.

Required UTF-8 CSV headers:

`unit_id,slug,title,description,city,max_guests,min_nights`

Optional: `neighbourhood,starting_price,amenities,house_rules,legacy_url`.
Amenities use semicolon-separated keys from PublicListingCatalog::AMENITIES.

Validate before saving (replace paths and ID with real values):

`php artisan haven:import-public-listings haven /path/to/mapped-listings.csv --actor=USER_ID --dry-run`

Remove `--dry-run` to save private drafts. USER_ID is the central login user ID,
not a legacy staff ID. Existing listings are never overwritten, photos are never
downloaded, and the site is never enabled by this command. Upload photographs in
the editor before publication. Legacy URLs produce redirect candidates for the
later domain migration; no public redirects are installed automatically.

## Remaining operational work after code is ready

- Deployment and central migrations completed according to the user's server output.
- Configure the company's public site and an active sales assignee.
- Map actual website listings/photos to actual units; review and publish them.
- Pilot a few units and inspect real calendar freshness without sending test SMS
  to guests.
- Configure custom domain/DNS/SSL and legacy redirects only after the pilot and
  the existing WordPress training/content pages have been accounted for.

## Resume instructions

Read this file first, then inspect `git status` and the new public listing files.
Do not restart completed work or deploy incomplete code. Update this checkpoint
with completed files, exact test results, commit hashes and remaining work before
ending or handing over the session.

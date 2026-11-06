# Public listings implementation checkpoint

Updated: 6 November 2026. Status: first-release code complete and verified; deployment and real content mapping remain.

## Objective

Add a public accommodation catalogue backed by existing physical unit records,
shared availability and website enquiries that enter the sales pipeline and
receive acknowledgement SMS. Keep the current WordPress website running until
listings have been mapped and reviewed.

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

Code, tests and private-preview workflows are complete. The next work is live
deployment, then real content mapping and a small publishing pilot. The user has
been asked whether old website codes such as 064/101 are real apartment numbers
or independent marketing codes. Until verified, do not infer unit mappings from
those numbers and do not publish imported content automatically.

No current website listings or photos have been copied into live app records.
No site has been enabled, no DNS has changed, and no real SMS has been sent by
the implementation or tests. These are operational activation steps, not missing
public-site code. Future instant payments, booking holds, guest accounts and
email/WhatsApp automation remain separate follow-on features as originally planned.

## Deployment and activation

Central migrations (copy the new code before running migrations):

- `2026_11_06_100045_create_public_listing_tables.php`
- `2026_11_06_100537_add_public_enquiry_capture.php`

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

- Deploy prepared files while in maintenance, then run the central migrations.
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

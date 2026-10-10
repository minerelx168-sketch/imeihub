# Article-to-report measurement

Status: implementation prepared; activation requires the site's GA4 Measurement ID,
server deployment and a real GA4 DebugView check. No analytics is loaded when
`GA4_MEASUREMENT_ID` is unset. This is a direct Google tag adapter; do not also
configure GTM to send these same events.

## Activation

1. Use the GA4 web stream for **imeihub.net**. Set the server environment or `.env`
   value `GA4_MEASUREMENT_ID=G-...` to its actual Measurement ID. This is a public ID,
   not an API secret. Never use a different site's property.
2. In that stream, disable Enhanced Measurement (especially form interactions,
   site search and automatic page/history events). This adapter explicitly sends
   sanitized page locations, referrer origins and page titles so IMEIs, auth tokens
   and report data in URLs or forms are not collected. Do not install another
   automatic GA/GTM tag on sensitive pages.
3. Deploy all files in this change together. The adapter loads before `main.js`.
   It asks for optional analytics consent and loads Google only after acceptance.
   Visitors can change their choice through Analytics preferences in the footer.
4. Register event-scoped custom dimensions `article_slug`, `service_slug` and
   `cta_location` in GA4. Use the built-in ecommerce purchase metrics for revenue.
5. Validate in GA4 DebugView / Tag Assistant using a test property and a test
   successful paid report. Confirm one purchase per transaction ID. Do not place
   a chargeable production order solely to test without authorization.
6. Connect this GA4 property to Windsor.ai for reporting and link the matching
   Search Console domain property. Filter acquisition by **Session source / medium
   = google / organic** when assessing SEO.

## Events

- `article_view`: a valid article page, `article_slug`.
- `service_cta_click`: links within an article to a service, service catalog,
  paid-check selector or free lookup. `article_slug`, `service_slug`,
  `cta_location` = article_body / article_footer. A generic free footer CTA
  is labelled `free-imei-check`, not a paid-service purchase.
- `purchase`: only an authenticated API result with `ok=true`, status SUCCESS,
  a positive server-reported cost, a valid service code and the public order ID.
  Includes `transaction_id`, USD `value`, one `items` entry and `article_slug`.
  Service costs already use USD wallet units. This measures fulfilled report
  value, not cash top-ups, accounting profit or net revenue after later refunds.

No top-up callback fires purchase. Free, failed, refunded and processing results
are excluded. Browser code never copies IMEI, account identity, form values,
provider responses or raw URLs into analytics events.

## Attribution and limits

The article dimension is the most recent article in the same browser-tab journey,
with a 30-minute inactivity timeout. It is not a Google search keyword. Query data
remains aggregate in Search Console. No keyword-to-person match is claimed.
A pending order stores its article snapshot for at most 24 hours in sessionStorage;
viewing that order's successful result in Orders can then report completion.
Arbitrary old orders are never treated as new sales. Repeated views are suppressed
in the tab and GA4 also receives the same stable transaction ID for deduplication.

This initial version is browser measurement. Declined consent, ad blockers,
closed tabs, different browsers/tabs and successful background orders never viewed
again can leave purchases unmeasured. A request that times out before returning
an order ID cannot be recovered by this adapter. Do not compare the GA4 purchase
count to the ledger as if it were complete bookkeeping. Server-side order-bound
attribution/outbox and refund reporting would be a separate extension.

Acceptance checks: JS syntax, analytics test suite, paid success exactly once,
free/failure exclusions, pending-to-history recovery, repeat-view suppression,
consent denied/withdrawn, safe payloads and external-link exclusion. PHP runtime,
database integration, live deployment and GA4 receipt must still be verified in
the deployment environment before activation.

Run: `node tests/analytics.test.cjs`

Official references:
- https://developers.google.com/analytics/devguides/collection/ga4/ecommerce
- https://developers.google.com/analytics/devguides/collection/ga4/validate-ecommerce
- https://developers.google.com/tag-platform/security/guides/consent

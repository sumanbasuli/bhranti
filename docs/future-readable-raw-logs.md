# Future TODO: readable raw-log lines with expandable JSON

Status: Deferred; not implemented. Recorded 23 October 2026.

## Goal

Make the Raw logs screen easy to scan while keeping the full structured record available. A row could read:

`[2026-10-23T23:15:00Z] ERROR: Database connection failed (User: 1029)`

Each line would expand to show its complete escaped JSON event. The existing Summary view and NDJSON download should remain available, with the same filters, pagination and record ordering.

This is a future design note, not a description of current behavior or authorization to change audit storage.

## Before implementation

- Derive severity and human-readable text from recorded event type, status and safe description; never invent an error or expose provider payloads, credentials, prompts or full content.
- Use the actual event timestamp in UTC and the recorded requester. Where historical actor differs, retain that fact in the expanded record and make its meaning clear.
- Keep one keyboard-accessible disclosure per event with a visible focus state, readable wrapping and no automatic expansion of large pages.
- Preserve exact event JSON, NDJSON export, existing access controls and private-chat boundaries. Escaping must treat every recorded string as untrusted.
- Verify filtering, pagination, empty states, large logs, mobile layout, screen readers and a genuine production-like activity sequence before replacing the current view.

The 1.2 review release intentionally keeps the current raw-log format to avoid introducing a new presentation path during WordPress.org review.

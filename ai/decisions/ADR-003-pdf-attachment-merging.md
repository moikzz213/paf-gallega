# ADR-003: Merge PRF Attachments Into One PDF, With Links Drawn by FPDF

- **Status:** Accepted
- **Date:** 2026-07-29

## Context

The payment-request PDF previously listed each invoice attachment as a hyperlink, and additionally
attached PDFs as PDF-embedded files (`Cpdf::addEmbeddedFile`) reachable only from a viewer's
attachments panel. Approvers wanted a single self-contained document they could read end to end
without chasing links or opening a side panel.

Attachments are arbitrary uploads: supplier invoices as PDFs, photos of delivery notes, and
spreadsheets or Word documents. Only some of those can be rendered into a PDF at all.

## Decision

Render the dompdf sheet, then post-process it with **FPDI** (`setasign/fpdi` + `setasign/fpdf`) in
`App\Services\PdfMergeService`:

- **PDF attachments** — every page imported and appended, no separator page.
- **Image attachments** (`image/jpeg|png|webp|gif`) — one page each, centred and aspect-scaled.
- **Everything else** (Excel, Word, archives) — listed on a trailing "Additional Documents" page as
  clickable download links, grouped per invoice.

`PdfMergeService::MERGEABLE_MIMES` is the single source of truth for that split;
`PaymentRequestController::downloadPdf()` routes each document to either `$attachments`
(rendered) or `$links` (listed). When a request has no documents at all the merge is skipped and
dompdf's output is returned unchanged.

## The links page must be drawn by FPDF, not Blade

**Do not move the "Additional Documents" list into `resources/views/pdf/payment-request.blade.php`.**
It lived there first and produced links that looked correct but did nothing when clicked.

dompdf *does* emit a proper `/URI` annotation for an `<a href>` with an absolute URL. But FPDI's
`importPage()` copies only a page's **content stream** — annotations (links, form fields,
bookmarks) are not carried across. Measured on a one-link document:

| Stage | `/Annots` | `/URI` |
|---|---|---|
| dompdf output, direct | 1 | 2 |
| after FPDI `importPage()` | **0** | **0** |
| `Cell()` written by FPDF | 1 | 2 |

So any hyperlink has to be written by FPDF, the final writer of the merged file.
`PdfMergeService::addLinksPage()` does this via `Cell()`'s `$link` argument.

**Consequence:** any hyperlink added to that Blade view is silently dead whenever the request has
attachments. Put it on the FPDF page instead.

Filenames pass through `iconv` to windows-1252 (`PdfMergeService::text()`) because FPDF's core
fonts are cp1252-encoded and would otherwise mangle non-ASCII characters.

## Consequences

- Approvers get one self-contained document; `addEmbeddedFile` is no longer used.
- Merged files are larger and generation cost scales with attachment count and size.
- Link URLs come from `url()`, so they resolve only if `APP_URL` is the real host — a localhost
  `APP_URL` yields links that work for nobody but the developer.
- FPDI cannot import encrypted or some compressed PDFs; those raise an error page and merging
  continues rather than failing the download.
- `tests/Feature/PdfMergeTest.php` guards the split and, via
  `test_non_mergeable_documents_produce_real_link_annotations`, the annotation defect above —
  which is invisible to visual inspection.

## Alternatives considered

- **Keep link-only attachments.** Rejected: the whole point was to stop approvers chasing links.
- **Convert Office files to PDF before merging.** Rejected for now: needs LibreOffice or a
  conversion service on the host, which this XAMPP deployment does not have.
- **Merge with pdftk / qpdf / Ghostscript**, which preserve annotations and would have let the
  Blade-rendered list keep its styling. Rejected: an external binary on every deployment host is a
  heavier dependency than drawing one page with FPDF.

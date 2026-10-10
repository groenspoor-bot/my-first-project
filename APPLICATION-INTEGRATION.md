# Pinksterpark application page – integration notes

`application.html` is a self-contained, single-file application form for a cession or rental at
Pinksterpark. It has no build step and no dependencies other than the Plus Jakarta Sans web font,
which matches the live site (WordPress + Houzez theme, primary colour `#2F5E99`).

## What the page covers

Built from Annexure A (Application Criteria) and Forms A1–A6 and A8:

| Step | Source | Notes |
| --- | --- | --- |
| Before you start | Annexure A | Cession vs rental, AFM membership period, citizenship, unit number. Warns when the Annexure A criteria are not met. |
| Applicant / Spouse | Form A1 | Spouse step appears only when a spouse is included. Photo upload for each. |
| Household, Vehicles, Rental history | Form A1 | Repeatable rows (children, vehicles, trailers/caravans, references). |
| Pets | Form A8 | Per-pet details, photo, vaccination record, proof of sterilisation, plus the health/control declarations. |
| Motivation | Annexure A | Written motivation (100+ characters) and/or an uploaded letter. |
| Declarations & consents | Forms A1, A3, A4, A5, A6, Annexures B & G | Tick-box acceptance with the full consent text expandable. Signed copies are still uploaded in the next step. |
| Documents | Annexure A + Form A1 checklist | Required/optional chips adapt to earlier answers (spouse, foreign national, vehicles, disclosures). |
| Review & submit | Form A1 | Summary with Edit links, R350 fee and FNB banking details, typed e-signature, outstanding-document acknowledgement. |

Typed answers are saved in the visitor's browser (`localStorage`) so they can return later. Files
cannot be saved and must be uploaded in the sitting in which the form is submitted.

## Connecting it to the office (the only required change)

At the top of the `<script>` block there is a `CONFIG` object. Set `endpoint` to the URL that should
receive applications and the page leaves preview mode automatically (the yellow banner disappears).

```js
const CONFIG = {
  endpoint: "https://pinksterpark.co.za/wp-json/pinksterpark/v1/application",
  headers: {},          // optional, e.g. { "X-Api-Key": "..." }
  ...
};
```

The page sends one `POST` request as `multipart/form-data` containing:

- `reference` – client-generated reference such as `PP-20261010-AB12` (the server may return its own).
- `payload` – a JSON string with every answer, the repeatable rows (`children`, `vehicles`,
  `secondaryVehicles`, `references`, `pets`) and `outstandingDocuments` (required documents not uploaded).
- `files[<key>][]` – the uploaded files. Keys are the document identifiers in the `DOCS` array
  (`id_applicant`, `proof_of_income`, `signed_a3`, …) plus `photo_applicant`, `photo_spouse`,
  `motivation_letter` and `pet_<n>_photo|vaccination|sterilisation`.

A JSON response of `{ "reference": "...", "message": "..." }` is used on the thank-you screen if present.
Any non-2xx response shows a retry message with the admin email address.

Backend options, in order of how little work they need:

1. **WordPress plugin or snippet** registering a REST route (`register_rest_route`) that saves the
   files with `wp_handle_upload()` and emails `admin@pinksterpark.co.za` with the payload. Keeps all
   data on the Pinksterpark server, which is the simplest position for POPIA.
2. **Gravity Forms / WPForms / Fluent Forms webhook** – not recommended; the multi-step logic and
   conditional document list would have to be rebuilt in the form builder.
3. **Hosted form backend** (Formspree, Basin, Getform) with file uploads enabled – quickest, but the
   documents then live with a third party.

## Putting it on pinksterpark.co.za

Either option keeps the page as one file, so later updates are a single-file replace.

- **Embed (in use):** `application.html` is served by GitHub Pages from the `main` branch of this repo at
  `https://groenspoor-bot.github.io/my-first-project/application.html`. The WordPress page "Application"
  embeds it with a Custom HTML block. Adding `?embed=1` (or simply being inside an iframe) hides the
  page's own header and background so the WordPress page supplies them:

  ```html
  <iframe id="pp-apply" src="https://groenspoor-bot.github.io/my-first-project/application.html?embed=1" style="width:100%;border:0;min-height:900px" title="Pinksterpark application"></iframe>
  <script>
    addEventListener("message", e => {
      if (e.data && e.data.type === "pinksterpark-application:height")
        document.getElementById("pp-apply").style.height = e.data.height + 20 + "px";
    });
  </script>
  ```

  The page posts its height to the parent, so the iframe grows with each step and never shows an inner scrollbar.

- **Native page:** paste the contents of `<style>` and `<body>` into a full-width Houzez page template.
  The CSS is namespaced to the form's own classes and uses the same font as the theme.

Then add "Apply" to the main menu (next to Documents & Resources) and link to it from the
Available Homes and Accommodation pages.

## Links to update once the documents are published

`CONFIG.links` holds the download URLs for Forms A2–A6, A8 and Annexures B, G and I. They currently
point to the Forms and Annexures listing pages; replace them with the direct PDF links once those are
uploaded to the Documents & Resources section.

## Things to confirm with the Trust

- Whether the police clearance certificate should be required for every applicant (currently required
  only when a criminal matter is disclosed, otherwise optional, per Annexure A's "if relevant").
- Whether the health certificate and medical disclosure (on the Form A1 checklist but not in Annexure A)
  should be required or optional.
- The maximum upload size (`CONFIG.maxFileMB`, currently 10 MB per file) once the backend is chosen.

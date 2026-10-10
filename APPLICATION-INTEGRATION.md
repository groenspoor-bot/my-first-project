# Pinksterpark application page – integration notes

`application.html` is a self-contained, single-file application form for a cession or rental at
Pinksterpark. It has no build step and no dependencies other than the Plus Jakarta Sans web font,
which matches the live site (WordPress + Houzez theme, primary colour `#2F5E99`).

The server side is `wordpress/pinksterpark-application.php`, a WordPress snippet that receives drafts,
file uploads and final submissions and emails them to the office.

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
| Documents | Annexure A + Form A1 checklist | Required/optional chips adapt to earlier answers (spouse, foreign national, vehicles, disclosures). The health certificate was removed on request. |
| Review & submit | Form A1 | Summary with Edit links, R350 fee and FNB banking details, typed e-signature, outstanding-document acknowledgement. |

## How saving and submitting work

Everything goes through `https://pinksterpark.co.za/wp-json/pinksterpark/v1`:

| Call | Purpose |
| --- | --- |
| `POST /draft` | Create or update a draft. Body: `{ token?, email?, payload, notify? }`. Returns `{ token, resumeUrl, files, emailSent }`. With `notify: true` the applicant is emailed the resume link. |
| `GET /draft/{token}` | Read a draft back (answers + list of uploaded files) to continue later. |
| `POST /draft/{token}/file` | Upload **one** file (multipart: `key`, `file`). Files go up one at a time because the host caps requests at 32 MB. |
| `DELETE /draft/{token}/file/{id}` | Remove an uploaded file. |
| `POST /draft/{token}/submit` | Finalise. Moves the draft to `applications/PP-YYYYMMDD-XXXX/`, emails the office and the applicant, returns `{ reference, message }`. |

Behaviour in the page:

- A draft is created on the server after step 1, and updated on every step change. Each file is
  uploaded the moment it is added, so uploads survive a closed tab.
- **Save & continue later** asks for an email address and sends
  `https://pinksterpark.co.za/application/?resume=<token>`. The WordPress page passes `resume` into the
  iframe and the form restores answers and uploaded files. The token is also kept in `localStorage`
  so the same browser resumes automatically. Drafts are deleted after 60 days.
- On submit the office receives an HTML summary with all documents attached (up to 20 MB in total;
  larger sets get a ZIP download link instead), and the applicant receives a confirmation with the
  reference, fee details and any outstanding documents.

Storage: `wp-content/uploads/pinksterpark-applications/{drafts,applications}/…`, protected by `.htaccess`.
Admin menu **Applications** (wp-admin) lists submissions with Summary and ZIP downloads.
The office address is the WordPress option `pp_application_admin_email` (default admin@pinksterpark.co.za).

## Outgoing email

Emails are sent with `wp_mail()`, which WP Mail SMTP controls. On 10 October 2026 the site used the
default PHP mailer with the From address forced to a @gmail.com address, so Gmail silently dropped every
message (including WP Mail SMTP's own test email). The From address was changed to
`admin@pinksterpark.co.za`, which the domain's SPF record (`v=spf1 mx a include:spf.host-h.net ?all`)
authorises for the web server. For better deliverability, configure WP Mail SMTP with a real SMTP
account (the mail.pinksterpark.co.za mailbox or a provider such as SendLayer/Brevo) and add DKIM.

## Installing the server side

1. Install and activate the **Code Snippets** plugin (wordpress.org/plugins/code-snippets).
2. Snippets → Add New. Title "Pinksterpark application endpoint", type PHP, scope "Run snippet everywhere".
3. Paste the contents of `wordpress/pinksterpark-application.php` **without the first `<?php` line**, save and activate.
4. Check `https://pinksterpark.co.za/wp-json/pinksterpark/v1/draft/00000000000000000000000000000000` returns a JSON
   "not found" error (that proves the routes are live), then test the form end to end.

Whenever the PHP file changes in this repo, paste the new version into the snippet.

## Putting it on pinksterpark.co.za

`application.html` is served by GitHub Pages from the `main` branch of this repo at
`https://groenspoor-bot.github.io/my-first-project/application.html`. GitHub Pages caches it for ten minutes.

Live set-up (October 2026): WordPress page id 18835 (`/application/`, template `template/template-homepage.php`,
one Custom HTML block) and Main Menu item id 18836 placed after Documents & Resources. The block is:

```html
<div class="container pp-apply-wrap">
  <div style="max-width:880px;margin:0 auto;padding:36px 0 60px">
    <h1 …>Apply to live at Pinksterpark</h1>
    <p …>intro</p>
    <iframe id="pp-apply" title="Pinksterpark application form" style="width:100%;border:0;min-height:900px;display:block" scrolling="no"></iframe>
  </div>
</div>
<script>
(function(){
  var f=document.getElementById('pp-apply');
  var q=new URLSearchParams(window.location.search), resume=q.get('resume');
  f.src='https://groenspoor-bot.github.io/my-first-project/application.html?embed=1'+(resume&&/^[a-f0-9]{32}$/.test(resume)?'&resume='+resume:'');
  window.addEventListener('message',function(e){
    if(e.origin!=='https://groenspoor-bot.github.io'||!e.data||typeof e.data.type!=='string')return;
    if(e.data.type==='pinksterpark-application:height'){f.style.height=(e.data.height+24)+'px';}
    if(e.data.type==='pinksterpark-application:scroll'){var y=f.getBoundingClientRect().top+window.pageYOffset+(e.data.top||0)-100;window.scrollTo({top:Math.max(0,y),behavior:'smooth'});}
  });
})();
</script>
```

`?embed=1` (or simply being inside an iframe) hides the page's own header and background so the WordPress
page supplies them; the page posts its height and a scroll request to the parent on each step change.

## Links to update once the documents are published

`CONFIG.links` in `application.html` holds the download URLs for Forms A2–A6, A8 and Annexures B, G and I.
They currently point to the Forms and Annexures listing pages; replace them with the direct PDF links once
those are uploaded to the Documents & Resources section.

## Things to confirm with the Trust

- Whether the police clearance certificate should be required for every applicant (currently required
  only when a criminal matter is disclosed, otherwise optional, per Annexure A's "if relevant").
- The attachment limit for office emails (`PPAPP_ATTACH_LIMIT_BYTES`, 20 MB) and per-file limit (10 MB).

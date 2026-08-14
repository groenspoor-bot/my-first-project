# YouTube One-Click EndNote Exporter

A Chrome extension that adds an **Export EndNote** button under (or next to)
every YouTube video. One click downloads a fully-tagged `.enw` file — the
EndNote Tagged Import format — using the **Online Multimedia** reference
type, ready to drag into your EndNote library.

## What it fills in automatically

| Tag | Field | Source |
|---|---|---|
| `%0` | Reference Type | Always `Online Multimedia` |
| `%A` | Created By | Channel name |
| `%D` | Year | Full upload date (`YYYY-MM-DD`) |
| `%T` | Title | Video title, verbatim |
| `%B` | Series Title | Playlist name, if the video was opened from one |
| `%I` | Distributor | Always `YouTube` |
| `%P` | Short URL | `youtu.be/<id>` |
| `%&` | Embed URL | `https://www.youtube.com/embed/<id>` |
| `%7` | Time Stamp | The player's current time **if you pause the video before clicking** — this is how you cite a specific passage |
| `%8`, `%1`, `%2` | Date/Year Accessed & Cited | Today's date |
| `%9` | Type of Work | Always `YouTube video` |
| `%!` | Short Title | Auto-derived from the title |
| `%#` | Format/Length | Running time, parsed from the page |
| `%M` | Accession Number | The 11-character video ID |
| `%F` | Label | Always `YouTube` |
| `%K` | Keywords | Channel name + YouTube's own video tags |
| `%X` | Abstract | Video description |
| `%Z` | Notes | Uploader attribution |
| `%<` | Research Notes | A short note that the record was auto-extracted, with the date, so you have a verification trail |
| `%U` | URL | Canonical `watch?v=` link |
| `%+` | Author Address | Channel URL |
| `%G` | Language | Page/video locale, if exposed |

Fields YouTube's page doesn't expose anywhere (Series Editor, Place
Published, Date Recorded if different from upload, DOI, Contributors,
translated fields, database name/provider, file attachment path) are left
blank rather than guessed — fill them in by hand in EndNote if you know
them.

**Tip for `%7` Time Stamp:** pause the video at the exact moment you want
to cite, *then* click Export — the button reads the player's current
position at click time. If you export from the very start of the video,
the field is left empty.

## Install (unpacked, for personal use)

Chrome extensions from outside the Web Store are loaded as "unpacked":

1. Open `chrome://extensions` in Chrome.
2. Turn on **Developer mode** (top-right toggle).
3. Click **Load unpacked**.
4. Select this folder: `youtube-endnote-exporter/`.
5. Open any YouTube video — you'll see an **Export EndNote** button under
   the title or next to the Like/Share buttons.

No special permissions are requested — the extension only runs on YouTube
watch pages and builds the file entirely in your browser; nothing is sent
anywhere.

## Files

- `manifest.json` — Chrome Manifest V3 extension definition.
- `content.js` — extracts metadata from the YouTube page and builds/downloads the `.enw` file.
- `styles.css` — button styling (matches YouTube's light/dark themes).
- `icons/` — toolbar icons.

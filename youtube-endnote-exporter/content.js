/**
 * YouTube One-Click EndNote Exporter
 *
 * Injects a button next to (or under) the video that, on click, builds an
 * EndNote Tagged Import (.enw) record for the current video — reference
 * type "Online Multimedia" — and downloads it. Only fields that can be
 * read from the live page are filled in; nothing is invented.
 */
(function () {
  "use strict";

  const BUTTON_ID = "enw-export-btn";

  // ---------------------------------------------------------------------
  // Small helpers
  // ---------------------------------------------------------------------

  function meta(selectorAttr, value) {
    const el = document.querySelector(`meta[${selectorAttr}="${value}"]`);
    return el && el.content ? el.content.trim() : "";
  }

  function text(selector) {
    const el = document.querySelector(selector);
    return el && el.textContent ? el.textContent.trim() : "";
  }

  function pad2(n) {
    return String(n).padStart(2, "0");
  }

  function formatClock(totalSeconds) {
    if (totalSeconds == null || isNaN(totalSeconds)) return "";
    totalSeconds = Math.floor(totalSeconds);
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    return h > 0 ? `${h}:${pad2(m)}:${pad2(s)}` : `${m}:${pad2(s)}`;
  }

  function isoDurationToSeconds(iso) {
    if (!iso) return null;
    const m = iso.match(/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/);
    if (!m) return null;
    const h = parseInt(m[1] || "0", 10);
    const min = parseInt(m[2] || "0", 10);
    const s = parseInt(m[3] || "0", 10);
    return h * 3600 + min * 60 + s;
  }

  function readableDate(d) {
    return d.toLocaleDateString("en-US", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  }

  function sanitizeFilename(name) {
    return name.replace(/[\\/:*?"<>|]+/g, "").replace(/\s+/g, " ").trim().slice(0, 120);
  }

  // ---------------------------------------------------------------------
  // Live player data (fixes YouTube SPA-navigation staleness)
  // ---------------------------------------------------------------------

  // YouTube is a single-page app: clicking a related/suggested video updates
  // the URL and DOM without a full reload, but it does NOT reliably update
  // <meta> tags (og:title, description, itemprop=uploadDate/duration) or
  // <link rel="canonical"> on that kind of navigation — confirmed by testing,
  // they can keep showing the PREVIOUS video's data indefinitely afterward.
  // That staleness is what let this extension export a record whose title,
  // date, description, duration, or URL belonged to a different video than
  // the one actually on screen. `<ytd-app>`'s Polymer data model
  // (`.data.playerResponse`) is the same object the page itself renders from,
  // so it stays in sync with what's visible. We verify its videoId matches
  // the current URL before trusting it, so a stale read is never used
  // silently.
  function getFreshPlayerData(expectedVideoId) {
    try {
      const app = document.querySelector("ytd-app");
      const pr = app && app.data && app.data.playerResponse;
      if (!pr || !pr.videoDetails) return null;
      if (expectedVideoId && pr.videoDetails.videoId !== expectedVideoId) return null;
      return pr;
    } catch (e) {
      return null;
    }
  }

  // ---------------------------------------------------------------------
  // Metadata extraction
  // ---------------------------------------------------------------------

  function getVideoId() {
    const params = new URLSearchParams(location.search);
    if (params.get("v")) return params.get("v");
    const shortsMatch = location.pathname.match(/\/shorts\/([\w-]{11})/);
    if (shortsMatch) return shortsMatch[1];
    const canonical = document.querySelector('link[rel="canonical"]');
    if (canonical) {
      const m = canonical.href.match(/[?&]v=([\w-]{11})/);
      if (m) return m[1];
    }
    return "";
  }

  function getTitle(playerData) {
    const liveTitle = playerData?.videoDetails?.title;
    if (liveTitle && liveTitle.trim()) return liveTitle.trim();
    const h1 = document.querySelector(
      "h1.ytd-watch-metadata yt-formatted-string, #title h1 yt-formatted-string, h1.title yt-formatted-string"
    );
    if (h1 && h1.textContent.trim()) return h1.textContent.trim();
    // og:title is not reliably updated on YouTube's client-side navigation
    // and can hold a previous video's title, so it's a last-resort fallback.
    const og = meta("property", "og:title");
    if (og) return og;
    return document.title.replace(/ - YouTube$/, "").trim();
  }

  function getChannelEl() {
    // Scoped to the owner/upload-info block under the player only. The
    // previous selector also matched bare `ytd-channel-name a`, which is not
    // unique to the main video — it can match a channel name from a related
    // video rendered in the sidebar. That unscoped fallback has been removed.
    return document.querySelector(
      "#owner ytd-channel-name a, #upload-info #channel-name a"
    );
  }

  function getChannelName(playerData) {
    const live =
      playerData?.videoDetails?.author ||
      playerData?.microformat?.playerMicroformatRenderer?.ownerChannelName;
    if (live && live.trim()) return live.trim();
    const el = getChannelEl();
    if (el && el.textContent.trim()) return el.textContent.trim();
    return meta("name", "author");
  }

  function getChannelUrl(playerData) {
    const mf = playerData?.microformat?.playerMicroformatRenderer;
    if (mf?.ownerProfileUrl) {
      const url = mf.ownerProfileUrl.startsWith("http")
        ? mf.ownerProfileUrl
        : `https://www.youtube.com${mf.ownerProfileUrl}`;
      return url.replace(/^http:/, "https:");
    }
    if (mf?.externalChannelId) {
      return `https://www.youtube.com/channel/${mf.externalChannelId}`;
    }
    const el = getChannelEl();
    if (el && el.href) return el.href;
    return "";
  }

  function getUploadDate(playerData) {
    // Prefer the live player data — confirmed by testing that the <meta>
    // tags below stay stuck on the PREVIOUS video's date indefinitely after
    // an in-page (SPA) navigation to a new video.
    // YouTube emits these as full ISO timestamps (e.g. "2009-10-24T23:57:33-07:00");
    // EndNote's Year field wants just the date portion.
    const mf = playerData?.microformat?.playerMicroformatRenderer;
    const raw =
      mf?.publishDate || mf?.uploadDate ||
      meta("itemprop", "datePublished") || meta("itemprop", "uploadDate") || "";
    return raw ? raw.split("T")[0] : "";
  }

  function getDescription(playerData) {
    // Live player data is complete and untruncated. The on-page description
    // panel is also live-bound but can be visually truncated ("...more");
    // <meta> tags are the last resort — they're both truncated by YouTube and
    // stale after an in-page video change.
    const live = playerData?.videoDetails?.shortDescription;
    if (live && live.trim()) return live.trim();
    const dom = text("#description-inline-expander, #description");
    if (dom) return dom;
    return meta("name", "description") || meta("property", "og:description") || "";
  }

  function getKeywords(playerData) {
    const live = playerData?.videoDetails?.keywords;
    if (Array.isArray(live) && live.length) return live;
    const raw = meta("name", "keywords");
    if (!raw) return [];
    return raw
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean);
  }

  function getDurationSeconds(playerData) {
    // videoDetails.lengthSeconds is exact and always reflects the actual
    // video (unlike scraping the player's on-screen clock, which would show
    // an ad's length while an ad is playing — this extension never did that,
    // but it also never had a reliable primary source; this is it).
    const live = playerData?.videoDetails?.lengthSeconds;
    if (live) {
      const n = parseInt(live, 10);
      if (!isNaN(n) && n > 0) return n;
    }
    const iso = meta("itemprop", "duration");
    return isoDurationToSeconds(iso);
  }

  function getPlaylistTitle() {
    const params = new URLSearchParams(location.search);
    if (!params.get("list")) return "";
    return text(
      "#playlist-name, .ytd-playlist-panel-renderer #playlist-name, yt-formatted-string.ytd-playlist-panel-renderer"
    );
  }

  function getCurrentTimestamp() {
    const video = document.querySelector("video");
    if (!video || isNaN(video.currentTime) || video.currentTime < 1) return "";
    return formatClock(video.currentTime);
  }

  function getLanguage() {
    return meta("property", "og:locale") || document.documentElement.lang || "";
  }

  function buildShortTitle(title, channel) {
    // Many YouTube titles follow "Channel - Actual Title" or "Channel: Actual
    // Title". Naively taking the text before the first separator would just
    // repeat the creator's name (already covered by %A) instead of shortening
    // the title, so skip past it when it matches the channel.
    const parts = title
      .split(/[:\-–—|]/)
      .map((s) => s.trim())
      .filter(Boolean);
    let candidate = parts[0] || title;
    if (
      channel &&
      candidate.toLowerCase() === channel.toLowerCase() &&
      parts.length > 1
    ) {
      candidate = parts[1];
    }
    if (!candidate || candidate.length >= title.length) {
      const words = title.split(/\s+/);
      candidate =
        words.length > 8 ? words.slice(0, 8).join(" ") + "…" : title;
    }
    return candidate.length > 60 ? candidate.slice(0, 57).trim() + "…" : candidate;
  }

  function collectData() {
    const videoId = getVideoId();
    // Fetch once and reuse for every field below. If it's missing, or its
    // videoId doesn't match the URL (hasn't populated yet, or YouTube's
    // internals changed), each field falls back to DOM/meta scraping exactly
    // as before.
    const playerData = getFreshPlayerData(videoId || null);
    const title = getTitle(playerData);
    const channel = getChannelName(playerData);
    const channelUrl = getChannelUrl(playerData);
    const uploadDate = getUploadDate(playerData); // YYYY-MM-DD or ""
    const year = uploadDate ? uploadDate.slice(0, 4) : "";
    const description = getDescription(playerData);
    const keywords = getKeywords(playerData);
    const durationSeconds = getDurationSeconds(playerData);
    const playlistTitle = getPlaylistTitle();
    const timestamp = getCurrentTimestamp();
    const language = getLanguage();

    // Build the canonical watch URL from the URL's own video ID, which is
    // always current. <link rel="canonical"> was confirmed (by testing) to
    // keep pointing at the PREVIOUS video after an in-page navigation, so
    // it's used only as a last resort when videoId itself is unavailable.
    const watchUrl = videoId
      ? `https://www.youtube.com/watch?v=${videoId}`
      : document.querySelector('link[rel="canonical"]')?.href || "";

    const now = new Date();
    const dateAccessed = readableDate(now);

    const keywordList = [];
    if (channel) keywordList.push(channel);
    keywordList.push(...keywords.filter((k) => k.toLowerCase() !== channel.toLowerCase()));
    // De-duplicate while preserving order.
    const seenKw = new Set();
    const dedupedKeywords = keywordList.filter((k) => {
      const key = k.toLowerCase();
      if (seenKw.has(key)) return false;
      seenKw.add(key);
      return true;
    });

    return {
      creators: channel ? [channel] : [],
      year: uploadDate || year,
      title,
      seriesEditor: "",
      seriesTitle: playlistTitle,
      placePublished: "",
      dateRecorded: "",
      numberOfScreens: "",
      shortUrl: videoId ? `youtu.be/${videoId}` : "",
      embedUrl: videoId ? `https://www.youtube.com/embed/${videoId}` : "",
      contributors: [],
      timestamp,
      dateAccessed,
      shortTitle: title ? buildShortTitle(title, channel) : "",
      doi: "",
      yearCited: String(now.getFullYear()),
      dateCited: dateAccessed,
      formatLength: formatClock(durationSeconds),
      accessionNumber: videoId,
      keywords: dedupedKeywords,
      abstract: description,
      notes: channel ? `Uploaded by ${channel}.` : "",
      researchNotes: `Metadata auto-extracted from the YouTube watch page on ${dateAccessed} using the One-Click EndNote Exporter browser extension. Verify against the original video before citing.`,
      url: watchUrl,
      fileAttachments: "",
      authorAddress: channelUrl,
      translatedAuthor: "",
      translatedTitle: "",
      databaseName: "",
      databaseProvider: "",
      language,
    };
  }

  // ---------------------------------------------------------------------
  // EndNote (.enw) record builder
  // ---------------------------------------------------------------------

  function buildEnw(d) {
    const lines = [];
    const add = (tag, value) => {
      if (value !== undefined && value !== null && String(value).trim() !== "") {
        lines.push(`${tag} ${value}`);
      }
    };
    const addMulti = (tag, values) => {
      (values || []).forEach((v) => add(tag, v));
    };

    add("%0", "Online Multimedia");
    addMulti("%A", d.creators);
    add("%D", d.year);
    add("%T", d.title);
    add("%E", d.seriesEditor);
    add("%B", d.seriesTitle);
    add("%C", d.placePublished);
    add("%I", "YouTube");
    add("%6", d.dateRecorded);
    add("%N", d.numberOfScreens);
    add("%P", d.shortUrl);
    add("%&", d.embedUrl);
    addMulti("%Y", d.contributors);
    add("%7", d.timestamp);
    add("%8", d.dateAccessed);
    add("%9", "YouTube video");
    add("%!", d.shortTitle);
    add("%R", d.doi);
    add("%1", d.yearCited);
    add("%2", d.dateCited);
    add("%#", d.formatLength);
    add("%M", d.accessionNumber);
    add("%F", "YouTube");
    addMulti("%K", d.keywords);
    add("%X", d.abstract);
    add("%Z", d.notes);
    add("%<", d.researchNotes);
    add("%U", d.url);
    add("%>", d.fileAttachments);
    add("%+", d.authorAddress);
    add("%H", d.translatedAuthor);
    add("%Q", d.translatedTitle);
    add("%~", d.databaseName);
    add("%W", d.databaseProvider);
    add("%G", d.language);

    return lines.join("\r\n") + "\r\n";
  }

  function downloadEnw(content, filename) {
    const blob = new Blob([content], { type: "application/x-endnote-refer" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
  }

  // ---------------------------------------------------------------------
  // Button injection
  // ---------------------------------------------------------------------

  function flashState(btn, cls, label, revertLabel) {
    btn.classList.add(cls);
    const span = btn.querySelector(".enw-export-btn__label");
    if (span) span.textContent = label;
    setTimeout(() => {
      btn.classList.remove(cls);
      if (span) span.textContent = revertLabel;
    }, 2000);
  }

  function makeButton() {
    const btn = document.createElement("button");
    btn.id = BUTTON_ID;
    btn.className = "enw-export-btn";
    btn.type = "button";
    btn.title = "Export this video as an EndNote (.enw) reference";
    btn.innerHTML = `
      <svg class="enw-export-btn__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M6 3.5A1.5 1.5 0 0 1 7.5 2H15l4.5 4.5V20.5A1.5 1.5 0 0 1 18 22H7.5A1.5 1.5 0 0 1 6 20.5v-17Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        <path d="M14.5 2.5V7a1 1 0 0 0 1 1h4" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        <path d="M9 12h6M9 15h6M9 18h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
      <span class="enw-export-btn__label">Export EndNote</span>
    `;

    btn.addEventListener("click", () => {
      try {
        const data = collectData();
        const content = buildEnw(data);
        const idPart = data.accessionNumber ? ` [${data.accessionNumber}]` : "";
        const filename = sanitizeFilename(
          `${data.title || "youtube-video"}${idPart}.enw`
        );
        downloadEnw(content, filename);
        flashState(btn, "enw-success", "Exported ✓", "Export EndNote");
      } catch (err) {
        console.error("[EndNote Exporter] failed to export:", err);
        flashState(btn, "enw-error", "Export failed", "Export EndNote");
      }
    });

    return btn;
  }

  function alreadyInjected() {
    return !!document.getElementById(BUTTON_ID);
  }

  function findActionsBar() {
    // Scope to the metadata block under the player first — YouTube reuses
    // the same #top-level-buttons-computed id inside engagement panels
    // (comments, chat) which are present in the DOM but not visible.
    const scoped = document.querySelector(
      "ytd-watch-metadata #top-level-buttons-computed"
    );
    if (scoped) return scoped;

    // Fall back to picking whichever match is actually rendered on screen.
    const candidates = document.querySelectorAll("#top-level-buttons-computed");
    for (const el of candidates) {
      const rect = el.getBoundingClientRect();
      if (rect.width > 0 && rect.height > 0) return el;
    }
    return null;
  }

  function tryInjectNextToActions() {
    if (alreadyInjected()) return true;
    const actionsBar = findActionsBar();
    if (!actionsBar || !actionsBar.parentElement) return false;
    const btn = makeButton();
    actionsBar.parentElement.appendChild(btn);
    return true;
  }

  function tryInjectBelowTitle() {
    if (alreadyInjected()) return true;
    const titleContainer = document.querySelector(
      "#title.ytd-watch-metadata, ytd-watch-metadata #title"
    );
    if (!titleContainer || !titleContainer.parentElement) return false;
    const btn = makeButton();
    btn.classList.add("enw-fallback");
    titleContainer.parentElement.insertBefore(btn, titleContainer.nextSibling);
    return true;
  }

  function tryInject() {
    if (!getVideoId()) return false;
    return tryInjectNextToActions() || tryInjectBelowTitle();
  }

  function removeButtonIfDetached() {
    const btn = document.getElementById(BUTTON_ID);
    if (btn && !document.body.contains(btn)) btn.remove();
  }

  // Try immediately, then keep watching: YouTube renders the player
  // metadata asynchronously and re-renders it on every SPA navigation.
  let attempts = 0;
  const pollTimer = setInterval(() => {
    attempts += 1;
    if (tryInject() || attempts > 40) clearInterval(pollTimer);
  }, 500);

  const observer = new MutationObserver(() => {
    removeButtonIfDetached();
    if (!alreadyInjected()) tryInject();
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });

  // YouTube fires this custom event on every client-side navigation.
  document.addEventListener("yt-navigate-finish", () => {
    const old = document.getElementById(BUTTON_ID);
    if (old) old.remove();
    attempts = 0;
    setTimeout(tryInject, 300);
  });
})();

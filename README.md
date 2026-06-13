# FreshRSS — Invidious Video Feed

A FreshRSS extension that embeds YouTube channel feeds using your own [Invidious](https://invidious.io) instance instead of youtube.com, keeping tracking and ads out of your reader.

Forked from [tunbridgep/freshrss-invidious](https://github.com/tunbridgep/freshrss-invidious), which itself was forked from [Korbak/freshrss-invidious](https://github.com/Korbak/freshrss-invidious) and originally from [kevinpapst/freshrss-youtube](https://github.com/kevinpapst/freshrss-youtube).

---

## What it does

- **YouTube channel feeds** — replaces the bare entry with an embedded Invidious player and the video description
- **Invidious feeds** — same, for feeds sourced directly from an Invidious instance
- **All feeds (optional)** — replaces any YouTube `<iframe>` embeds and links in regular articles with your Invidious instance

---

## Installation

```bash
cd /path/to/FreshRSS/extensions/
wget https://github.com/YOUR_USERNAME/freshrss-invidious/archive/master.zip
unzip master.zip
mv freshrss-invidious-master/xExtension-Invidious .
rm -rf freshrss-invidious-master/ master.zip
```

Then go to **FreshRSS → Extensions** and enable **Invidious Video Feed**.

---

## Configuration

Open the extension settings in FreshRSS:

| Field | Description |
|---|---|
| **Invidious instance** | Hostname of your Invidious instance — **hostname only**, no `https://` (e.g. `inv.example.com`) |
| **Player height** | Embed height in pixels (default 315) |
| **Player width** | Embed width in pixels (default 560) |
| **Show "Watch on YouTube" link** | Adds a plain link to the original YouTube page below each embed |
| **Replace YouTube embeds in all feeds** | When enabled, also rewrites YouTube iframes and links in ordinary article feeds, not just dedicated YouTube channel feeds |

---

## Subscribing to YouTube channels

YouTube exposes an RSS feed for every channel:

```
https://www.youtube.com/feeds/videos.xml?channel_id=CHANNEL_ID
```

The channel ID appears in the channel URL: `https://www.youtube.com/channel/CHANNEL_ID`.  
For handle-based URLs (`@username`), open the channel page and view source to find `"channelId"`.

---

## Changes in this fork (v1.2)

Fixes applied on top of the tunbridgep fork:

- **Hook changed from `entry_before_insert` to `entry_before_display`** — the original hook only fired for newly fetched entries. Switching to the display hook means all existing entries are affected immediately, without needing a re-fetch or database wipe.
- **FreshRSS 1.28+ compatibility** — hook registration now uses `Minz_HookType::EntryBeforeDisplay` when available, with a string-literal fallback for older installs.
- **`www.` no longer prepended to the instance hostname** — `www.youtube.com` is now replaced before `youtube.com` so the `www.` prefix isn't left orphaned on your instance URL.
- **YouTube link correctly gated behind its checkbox** — raw HTML from the stored feed entry (which can include links back to youtube.com) is now stripped with `strip_tags()` before display, so the YouTube link only appears when you explicitly enable it.
- **`appendYoutubeLink()` return value captured** — in the original, the return value was discarded, so the "Watch on YouTube" link never actually appeared even when enabled.
- **No more blocking HTTP request on every page render** — the original made a `file_get_contents()` call to the Invidious instance on every display to fetch the video description. This is replaced with the description already stored in the RSS entry.
- **`saveHTML()` fix** — the DOM-based `replaceYoutubeEmbeds()` no longer wraps output in a full `<!DOCTYPE html><html><body>` document.
- **PHP 8 compatibility** — `$url_info['host']` access is now null-guarded with `?? ''`.
- **`print_r()` debug line removed** — there was a stray `print_r($invidious_link)` that output raw text into the page.
- **`metadata.json` version type fixed** — was a float (`1.1`), now a string (`"1.2"`) as the spec requires.
- **`show_content` label corrected** — was mislabelled "Additionally display feed content"; it controls the YouTube link, not content display.

---

## Troubleshooting

**Extension doesn't affect existing entries** — this should no longer be an issue since the hook runs at display time. If you still see unprocessed entries, try disabling and re-enabling the extension.

**Instance field shows `www.` prefix** — make sure you enter only the bare hostname in settings, e.g. `inv.example.com` not `https://www.inv.example.com`.

**Settings page crashes on submit** — upgrade FreshRSS if you're on a very old version. The extension uses `FreshRSS_Context::$user_conf` which has been stable for years.

**Videos don't play** — verify your Invidious instance is reachable from your browser. Self-hosted instances must be accessible on the same network you're browsing from.

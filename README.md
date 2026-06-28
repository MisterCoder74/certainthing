# CertainThing

> **Vibe coding for people who don't want to learn a dev tool.**

Browser-based AI coding assistant with multimodal input, live reasoning transparency, website scraping, session management, and one-click deployment. Built on a vanilla PHP + JS stack — no frameworks, no Composer, no build step.

**Live:** `https://www.vivacitydesign.net/certainThing/v1.2/certainthing/`

---

## Features

### 🤖 AI Engine
- **Model**: `gpt-5-nano` by default · paid users can switch to `gpt-5.4-nano` via the in-UI selector
- **Reasoning**: streaming via `reasoning_content` (SSE)
- **Dynamic token budgets** by tier: Shared → 28k · BYOK → 64k · Paid → 110k
- **Token budget pill**: displayed in the reasoning panel on every request
- **Streaming**: hardcoded `true`; `reasoning_effort` defaults to `medium`

### 🔑 BYOK (Bring Your Own Key)
Key cascade (in priority order):
1. Per-user key file `data/keys/{user_id}.key` (valid, non-empty)
2. Shared admin key `data/shared_key.txt`
3. `OPENAI_API_KEY` environment variable
4. Error

- **Validation**: `is_valid_openai_key()` — rejects empty files, `*` placeholders, malformed strings (must be `>20 chars`, match `/^sk-[A-Za-z0-9\-_]{10,}/`)
- **Source transparency**: `🔑 [source] · sk-xxxx...` label shown in the reasoning panel on every request
- **Modal key status**: `save_api_key.php` GET returns `key_preview` (first 15 chars), `is_trial`, `show_shared_warning`
- **Trial warning**: `⚠️` banner shown in modal when user is on shared key during trial

### 💬 Chat Interface
- Split-panel UI: chat (left) + reasoning trace (right)
- Live SSE reasoning stream with status indicators (Thinking / Generating / Done)
- Session sidebar (☰): list, switch, search, delete past sessions
- Mobile-responsive: collapsible reasoning pane toggled via 🧠 button (≤830px)

### 📎 Multimodal Input
- **Images**: uploaded as base64 Vision input (no OpenAI Files API)
- **PDFs**: injected as base64 `type:"file"` in the Chat Completions envelope
- **Text files**: `.txt`, `.html`, `.css`, `.js`, `.php` — injected as plain text
- **URLs**: paste a link → server-side cURL scrape → raw HTML injected as AI context (~30KB truncation)

### 🐛 Empathetic Debugger
- Dedicated **🐛 button** in the input toolbar (same pattern as other tools)
- **Language selector**: choose the target language/framework for the debug session
- Separate `debug_prompt.txt` system prompt — isolated from the main coding prompt
- `debug_mode` + `debug_language` POST params passed to `chat.php`
- Reasoning panel emits debug metrics
- Response format: **Issues / Support / Solutions / What's Working**

### 🎙️ Voice Prompt
- **🎙️ button** in the input toolbar — visible on all plans
- Uses the **browser's Web Speech API** (`window.SpeechRecognition` / `webkitSpeechRecognition`) — zero external API cost, no audio data sent to OpenAI
- **No `lang` property set**: browser inherits the OS/system language automatically
- **Auto-stop on silence**: 2.5s silence after speech ends triggers `recognition.stop()`; manual click also stops at any time
- **Inline live transcript**: interim results are written into the textarea in real time; confirmed text is appended to any pre-existing prompt content
- **Firefox graceful degradation**: button is disabled with tooltip "not supported in this browser" when API is unavailable
- **Recording state**: mic button pulses red while active

### ⚙️ Model Selector (Paid)
- **Dropdown** in the input toolbar — visible only when `body[data-mode="paid"]`
- Options: `gpt-5-nano` (default) · `gpt-5.4-nano`
- Selected model is passed via `model` POST param; `chat.php` validates against session `user_mode` before applying
- Trial and BYOK users are always served `gpt-5-nano`

### 📤 Output & Export
- **Syntax-highlighted code blocks**: highlight.js, tabbed multi-file output
- **Copy** and **Download** buttons per file
- **ZIP export**: download all files from a response in one click
- **Live Preview Pane**: sandboxed iframe tab alongside the reasoning trace
- **🚀 Deploy (F10)**: saves generated code to a unique `deploy/` URL for live preview — recursive copy preserves subfolder structure (e.g., `assets/images/heroes/`)
- **Push to GitHub**: one-click commit of generated code to the user's repository via `github_push.php`

### 👤 Auth & Subscription
- Register / Login / Password Reset flow
- **Trial**: 7-day free trial (shared key, 28k token budget)
- **Paid**: €4.99/month (Stripe Checkout, tax-inclusive, monthly) — 110k token budget
- **Status dot** in header:
  - Trial: 🟢 < 4 days · 🟠 4–6 days · 🔴 ≥ 7 days
  - Paid: 🟢 0–24 days remaining · 🟠 25–29 days · 🔴 expired
- **Subscription expiry**: calendar-based logic (`+1 month` via `DateTime::modify()`), handles February and short months correctly
- **Stripe webhook**: `invoice.paid` → `stripe_webhook.php` → user status `→ paid`
- **User schema**: `id`, `email`, `password_hash`, `mode` (trial|paid), `status` (enabled|disabled), `created_at`, `last_payment_at`, `stripe_customer_id`

### 📄 Promo Page
- `promo.php`: public-facing landing page
- Includes debugger feature card (commit `22dbbcaa`)
- System prompt references `assets/images/heroes/` library (37 tagged images)

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | Vanilla HTML / CSS / JavaScript |
| Backend | PHP 8.x (no Composer, no frameworks) |
| AI | OpenAI `gpt-5-nano` / `gpt-5.4-nano` (paid) via Chat Completions (SSE) |
| Auth | Custom flat-file JSON (`data/users.json`) |
| Payments | Stripe Checkout (hosted), `invoice.paid` webhook |
| Deployment | Shared hosting (vivacitydesign.net) |

---

## Requirements

- PHP 8.x with extensions: `curl`, `json`, `mbstring`, `xml`, `zip`
- OpenAI API Key (env var, shared key file, or per-user BYOK)
- Stripe account (live mode) for paid plan

---

## Setup

1. Clone the repository.
2. Make `certainthing/data/` and `certainthing/data/keys/` writable by the web server.
3. Point your web server to the `certainthing/` directory.
4. Optionally set `OPENAI_API_KEY` as a shared fallback, or place it in `data/shared_key.txt`.
5. Register your Stripe webhook endpoint (`auth/stripe_webhook.php`) for the `invoice.paid` event in your Stripe Dashboard.
6. The `certainthing/deploy/` directory is created automatically on first deploy.

---

## File Structure

```
certainthing/
├── index.php               # Main app entry point
├── promo.php               # Public landing page
├── register.php / login.php / logout.php
├── reset_password.php      # Password reset flow
├── doc.html                # Full project documentation page
├── assets/
│   ├── app.js              # Frontend logic (chat, streaming, UI)
│   ├── style.css
│   └── images/heroes/      # 37 tagged hero images for deploy
├── api/
│   ├── chat.php            # SSE streaming endpoint (AI + debug mode)
│   ├── scrape.php          # URL scraper (cURL, ~30KB truncation)
│   ├── upload.php          # File upload handler
│   ├── save_api_key.php    # BYOK key save/read
│   ├── deploy.php          # Live deploy (recursive copy)
│   ├── github_push.php     # Git push via GitHub API
│   └── email_helper.php    # Email utility
├── auth/
│   └── stripe_webhook.php  # Stripe invoice.paid handler
├── config.php              # Key cascade + validation helpers
├── prompts/
│   ├── system_prompt.txt   # Main coding system prompt
│   └── debug_prompt.txt    # Debugger system prompt
└── data/
    ├── users.json          # User accounts
    ├── shared_key.txt      # Shared OpenAI key (fallback)
    └── keys/               # Per-user BYOK key files
```

---

## Usage

- **Register** at `register.php` — starts a 7-day free trial.
- **Chat** in `index.php` — describe what you want to build.
- **Attach files or paste URLs** using the toolbar icons.
- **Debug existing code** with the 🐛 button — select language, paste or attach code.
- **Preview** generated code live in the Preview tab.
- **Deploy** with **F10** or 🚀 — get a unique live URL instantly.
- **Push to GitHub** from the output tab toolbar.
- **Manage sessions** with the ☰ sidebar.
- **Add your OpenAI key** via the 🔑 button to unlock your personal token budget.

---

## Backlog (Next Phase)

- **⬇️ Scrape + Export PDF/DOCX** — download button post-response, keyword detection, format injection in system prompt
- **🔍 Deep Research** — dedicated button, two-step `gpt-5-nano` orchestrator + `gpt-5-search-api` retrieval, cost-aware UX

# CertainThing

AI-powered vibe coding assistant with multimodal input, website analysis, and conversational + reasoning interface.

## Current Status
**Phase 5 — Website Analyzer** implemented:
- Client-side URL detection in chat messages.
- Server-side web scraping via cURL/DOMDocument extraction.
- Reasoning trace updates during URL fetching.
- Context integration with scraped content appended to AI messages.

**Phase 4 — Multimodal Input** implemented:
- Image upload with GPT-4o Vision integration.
- Text file attachments (.txt, .html, .css, .js, .php).
- File content extraction and context appending.

**Phase 3 — Code Renderer** implemented:
- Syntax-highlighted code blocks with highlight.js.
- Tabbed multi-file output interface.
- Copy and download buttons per file.
- ZIP export for all files in a response.

**Phase 2 — Reasoning Pane** implemented:
- Live SSE streaming of AI reasoning steps.
- Visual status indicators (Thinking, Generating, Done).
- Persistent session history.

## Requirements
- PHP 8.x with `curl`, `json`, `mbstring`, `xml`, `zip` extensions.
- OpenAI API Key set in `OPENAI_API_KEY` environment variable.

## Setup
1. Clone the repository.
2. Ensure `certainthing/data` is writable by the web server.
3. Configure your web server to point to the `certainthing/` directory or access it via `/certainthing/`.
4. Set the `OPENAI_API_KEY` environment variable.

## Usage
- Register a new account at `register.php`.
- Start chatting in `index.php`.
- View the AI's reasoning in the right pane.

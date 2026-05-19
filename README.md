# CertainThing

AI-powered vibe coding assistant with multimodal input, website analysis, conversational + reasoning interface, session management, and one-click code deployment.

## Current Status

**Phase 8 — Documentation & Handoff** implemented:
- Added full project documentation page at [`certainthing/doc.html`](certainthing/doc.html).
- Includes architecture notes, full file tree with descriptions, function reference by file, use cases, feature flows, and end-to-end prompt-to-deploy workflow.
- Uses the app's dark visual language (`#0d1117` background, `#58a6ff` accents) for consistency.

**Phase 7 — Advanced Features** implemented:
- **Real Thinking Tokens**: Switched to `o3-mini` for true model-based reasoning streaming via `reasoning_content`.
- **Live Preview Pane**: Tabbed interface to switch between reasoning trace and a live sandboxed iframe preview of the generated code.
- **GitHub Integration**: One-click "Push to GitHub" to commit generated code directly to a user's repository.
- **Enhanced Code Integrity**: Refined system prompts and streaming logic to ensure code completeness and valid structural tags.

**Phase 6 — Polish & Integration** implemented:
- **Session Sidebar**: List, switch, search, and delete past projects from a collapsible left sidebar.
- **App Deploy (F10)**: Press F10 (or click 🚀 Deploy) to save generated code files to a live-viewable `deploy/` folder. Each deployment gets a unique URL to view the app running live.
- **Mobile Toggle**: Collapsible reasoning pane toggled via a button in the header on mobile screens (≤830px).
- **Refined Error Handling**: Comprehensive `try/catch` blocks across all fetch operations with user-friendly toast notifications.

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
5. The `certainthing/deploy/` directory will be created automatically on first deploy.

## Usage
- Register a new account at `register.php`.
- Start chatting in `index.php`.
- Use the sidebar (☰) to manage sessions.
- Press **F10** or click **🚀 Deploy** to deploy generated code live.
- View the AI's reasoning in the right pane on the right (toggle with 🧠 on mobile).

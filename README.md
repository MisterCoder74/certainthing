# CertainThing

AI-powered vibe coding assistant with multimodal input, website analysis, and conversational + reasoning interface.

## Current Status
**Phase 1 — Foundation** implemented:
- Project scaffold and folder structure.
- Authentication module (Login/Register).
- Two-pane responsive UI layout.
- Streaming chat with OpenAI integration (GPT-4o).
- Session persistence via JSON files.
- Simulated reasoning trace.

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
